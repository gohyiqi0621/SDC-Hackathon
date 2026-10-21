<?php
/**
 * ClaimController.php - Financial > Claims.
 *
 * Staff expense claims, kept separate from invoices: one page with the list
 * (search, filter, sort, paginate) and a "New claim" popup with an optional
 * receipt. Anyone can view and submit; only a Supervisor can edit, approve,
 * reject, mark reimbursed, reopen or delete (see finance_role()). Every query
 * is scoped to the signed-in user.
 */
declare(strict_types=1);

namespace Src\Controllers;

use PDOException;
use Src\Models\Claim;
use Src\Services\ClaimService;

final class ClaimController
{
    private const PER_PAGE = 10;

    /**
     * GET /?page=financial&action=claims
     *   &new=1     opens the new-claim popup
     *   &edit=N    opens the popup on claim N (Supervisor only)
     */
    public function index(): void
    {
        $user  = require_login();
        $modal = null;

        if (!empty($_GET['new'])) {
            $modal = $this->newModal($user);
        } elseif (!empty($_GET['edit']) && is_supervisor()) {
            $claim = $this->ownedOr404($user, (int) $_GET['edit']);
            if ($claim['status'] !== 'reimbursed') {
                $modal = ['claim' => $claim, 'values' => $claim, 'errors' => []];
            }
        }

        $this->renderList($user, $modal);
    }

    /** POST /?page=financial&action=claim_store - anyone may submit. */
    public function store(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $service = new ClaimService();
        $files   = $service->files();
        $upload  = $_FILES['receipt'] ?? null;

        [$data, $errors] = $service->validate($_POST);
        $fileError = $files->error($upload);
        if ($fileError !== null) {
            $errors['receipt'] = $fileError;
        }

        if ($errors !== []) {
            $this->fail($user, null, $_POST, $errors);
            return;
        }

        $file = $files->hasFile($upload) ? $files->store($userId, $upload) : null;
        if ($files->hasFile($upload) && $file === null) {
            $this->fail($user, null, $_POST, ['receipt' => 'The receipt could not be saved. Please try again.']);
            return;
        }

        try {
            (new Claim())->create($userId, $data + ($file ?? []));
        } catch (PDOException $e) {
            $files->delete($userId, $file['file_path'] ?? null);
            $this->fail($user, null, $_POST, $this->dbErrors($e));
            return;
        }

        respond_ok('Claim "' . $data['title'] . '" was submitted.', url('financial', ['action' => 'claims']));
    }

    /** POST /?page=financial&action=claim_update - Supervisor only. */
    public function update(): void
    {
        $user = require_login();
        require_supervisor('edit a claim');

        $userId  = (int) $user['id'];
        $claim   = $this->ownedOr404($user, (int) ($_POST['id'] ?? 0));
        $service = new ClaimService();
        $files   = $service->files();
        $upload  = $_FILES['receipt'] ?? null;

        // A reimbursed claim is closed: its money has already gone out.
        if ($claim['status'] === 'reimbursed') {
            $this->fail($user, $claim, $_POST + $this->fileColumns($claim), ['form' => 'This claim has been reimbursed and can no longer be edited.']);
            return;
        }

        [$data, $errors] = $service->validate($_POST);
        $fileError = $files->error($upload);
        if ($fileError !== null) {
            $errors['receipt'] = $fileError;
        }

        if ($errors !== []) {
            $this->fail($user, $claim, $_POST + $this->fileColumns($claim), $errors);
            return;
        }

        $newFile = $files->hasFile($upload) ? $files->store($userId, $upload) : null;
        if ($files->hasFile($upload) && $newFile === null) {
            $this->fail($user, $claim, $_POST + $this->fileColumns($claim), ['receipt' => 'The receipt could not be saved. Please try again.']);
            return;
        }

        $remove = !empty($_POST['remove_receipt']);
        $file   = $newFile ?? ($remove ? $this->fileColumns([]) : $this->fileColumns($claim));

        try {
            (new Claim())->update($userId, (int) $claim['id'], $data + $file);
        } catch (PDOException $e) {
            $files->delete($userId, $newFile['file_path'] ?? null);
            $this->fail($user, $claim, $_POST + $this->fileColumns($claim), $this->dbErrors($e));
            return;
        }

        // The old receipt is only removed once the database no longer points at it.
        if ($claim['file_path'] !== null && $claim['file_path'] !== $file['file_path']) {
            $files->delete($userId, $claim['file_path']);
        }

        respond_ok('Claim "' . $data['title'] . '" was updated.', url('financial', ['action' => 'claims']));
    }

    /** POST /?page=financial&action=claim_status  (id, do = approve|reject|reimburse|reopen) - Supervisor only. */
    public function status(): void
    {
        $user = require_login();
        require_supervisor('review a claim');

        $userId = (int) $user['id'];
        $claim  = (new Claim())->find($userId, (int) ($_POST['id'] ?? 0));
        $do     = (string) ($_POST['do'] ?? '');

        if ($claim === null) {
            flash('error', 'That claim was not found. It may have been removed.', 'Not changed');
            redirect($this->backUrl());
        }

        [$to, $allowedFrom] = Claim::TRANSITIONS[$do] ?? [null, []];

        if ($to === null || !in_array($claim['status'], $allowedFrom, true)
            || !(new Claim())->setStatus($userId, (int) $claim['id'], (string) $claim['status'], $to)) {
            flash('error', 'That change is not allowed from the claim\'s current status.', 'Not changed');
            redirect($this->backUrl());
        }

        [$label] = Claim::STATUS_LABELS[$to];
        flash('success', '"' . $claim['title'] . '" is now ' . strtolower($label) . '.', 'Claim ' . strtolower($label));
        redirect($this->backUrl());
    }

    /** POST /?page=financial&action=claim_delete - Supervisor only. */
    public function delete(): void
    {
        $user = require_login();
        require_supervisor('delete a claim');

        $userId = (int) $user['id'];
        $claim  = (new Claim())->find($userId, (int) ($_POST['id'] ?? 0));

        if ($claim === null) {
            flash('error', 'That claim was not found. It may already have been removed.', 'Not removed');
            redirect($this->backUrl());
        }

        (new Claim())->delete($userId, (int) $claim['id']);
        (new ClaimService())->files()->delete($userId, $claim['file_path']);

        flash('success', 'Claim "' . $claim['title'] . '" was removed.', 'Claim removed');
        redirect($this->backUrl());
    }

    /** GET /?page=financial&action=claim_file&id=N[&download=1] - the receipt, for its owner only. */
    public function file(): void
    {
        $user  = require_login();
        $claim = $this->ownedOr404($user, (int) ($_GET['id'] ?? 0));
        $files = (new ClaimService())->files();
        $path  = $files->pathFor((int) $user['id'], $claim['file_path']);

        if ($path === null) {
            abort(404, 'This claim has no receipt attached.');
        }

        $files->send($path, (string) $claim['file_mime'], (string) ($claim['file_name'] ?: 'receipt'), !empty($_GET['download']));
    }

    /* ---------------------------------------- old addresses (redirect) ---- */

    /** GET /?page=financial&action=claim_new - the form became a popup. */
    public function create(): void
    {
        redirect(url('financial', ['action' => 'claims', 'new' => 1]));
    }

    /** GET /?page=financial&action=claim_edit&id=N */
    public function edit(): void
    {
        redirect(url('financial', ['action' => 'claims', 'edit' => (int) ($_GET['id'] ?? 0)]));
    }

    /* ---------------------------------------------------------- private ---- */

    /**
     * The list page, optionally with the popup open.
     *
     * @param array{claim: ?array, values: array, errors: array}|null $modal
     */
    private function renderList(array $user, ?array $modal): void
    {
        $userId  = (int) $user['id'];
        $filters = $this->filters();
        $page    = max(1, (int) ($_GET['p'] ?? 1));

        $result = (new Claim())->search($userId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $pages  = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        // Asked for a page past the end (e.g. after a delete): show the last one.
        if ($page > $pages && $result['total'] > 0) {
            $page   = $pages;
            $result = (new Claim())->search($userId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }

        view('financial/claims', [
            'user'      => $user,
            'claims'    => $result['items'],
            'total'     => $result['total'],
            'allCount'  => (new Claim())->countForUser($userId),
            'stats'     => (new Claim())->stats($userId),
            'filters'   => $filters,
            'page'      => $page,
            'pages'     => $pages,
            'perPage'   => self::PER_PAGE,
            'modal'     => $modal ?? $this->newModal($user),
            'modalOpen' => $modal !== null,
        ]);
    }

    /** @return array{claim: null, values: array, errors: array} */
    private function newModal(array $user): array
    {
        return [
            'claim'  => null,
            'values' => [
                'claimant_name' => (string) ($user['name'] ?? ''),
                'expense_date'  => date('Y-m-d'),
                'currency'      => 'MYR',
            ],
            'errors' => [],
        ];
    }

    /** Search / filter / sort values from the query string, cleaned. */
    private function filters(): array
    {
        $sort     = (string) ($_GET['sort'] ?? 'newest');
        $status   = (string) ($_GET['status'] ?? '');
        $category = (string) ($_GET['category'] ?? '');

        return [
            'q'        => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'status'   => in_array($status, Claim::STATUSES, true) ? $status : '',
            'category' => isset(Claim::CATEGORIES[$category]) ? $category : '',
            'sort'     => isset(Claim::SORTS[$sort]) ? $sort : 'newest',
        ];
    }

    /** @return array{file_path: ?string, file_name: ?string, file_mime: ?string, file_size: ?int} */
    private function fileColumns(array $claim): array
    {
        return [
            'file_path' => $claim['file_path'] ?? null,
            'file_name' => $claim['file_name'] ?? null,
            'file_mime' => $claim['file_mime'] ?? null,
            'file_size' => isset($claim['file_size']) ? (int) $claim['file_size'] : null,
        ];
    }

    private function ownedOr404(array $user, int $id): array
    {
        $claim = $id > 0 ? (new Claim())->find((int) $user['id'], $id) : null;

        if ($claim === null) {
            abort(404, 'That claim was not found.');
        }

        return $claim;
    }

    /** A failed save: JSON errors for the popup, or the list with the popup reopened. */
    private function fail(array $user, ?array $claim, array $values, array $errors): void
    {
        if (wants_json()) {
            json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the highlighted fields.', 422);
        }

        set_status(422);
        $this->renderList($user, ['claim' => $claim, 'values' => $values, 'errors' => $errors]);
    }

    private function dbErrors(PDOException $e): array
    {
        if ($e->getCode() === '23514') {
            return ['form' => 'Some values are not allowed. Check the category, date and amount.'];
        }

        error_log('[claims] ' . $e->getMessage());

        return ['form' => 'The claim could not be saved. Please try again.'];
    }

    /** Return to the list the user came from (keeps filters and page), never off-site. */
    private function backUrl(): string
    {
        $back = (string) ($_POST['return'] ?? '');

        return str_starts_with($back, APP_URL . '/') ? $back : url('financial', ['action' => 'claims']);
    }
}
