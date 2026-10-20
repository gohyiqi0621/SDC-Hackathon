<?php
/**
 * ClaimController.php - Financial > Claims.
 *
 * Staff expense claims, kept separate from invoices: list (search, filter,
 * sort, paginate), a form of its own to add or edit a claim with an optional
 * receipt, review actions (approve, reject, mark reimbursed, reopen), remove,
 * and open the stored receipt. Every query is scoped to the signed-in user.
 */
declare(strict_types=1);

namespace Src\Controllers;

use PDOException;
use Src\Models\Claim;
use Src\Services\ClaimService;

final class ClaimController
{
    private const PER_PAGE = 10;

    /** GET /?page=financial&action=claims */
    public function index(): void
    {
        $user    = require_login();
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
            'user'     => $user,
            'claims'   => $result['items'],
            'total'    => $result['total'],
            'allCount' => (new Claim())->countForUser($userId),
            'stats'    => (new Claim())->stats($userId),
            'filters'  => $filters,
            'page'     => $page,
            'pages'    => $pages,
            'perPage'  => self::PER_PAGE,
        ]);
    }

    /** GET /?page=financial&action=claim_new */
    public function create(): void
    {
        $user = require_login();

        $this->renderForm($user, null, [
            'claimant_name' => (string) ($user['name'] ?? ''),
            'expense_date'  => date('Y-m-d'),
            'currency'      => 'MYR',
        ]);
    }

    /** POST /?page=financial&action=claim_store */
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

    /** GET /?page=financial&action=claim_edit&id=N */
    public function edit(): void
    {
        $user  = require_login();
        $claim = $this->ownedOr404($user, (int) ($_GET['id'] ?? 0));

        $this->renderForm($user, $claim, $claim);
    }

    /** POST /?page=financial&action=claim_update */
    public function update(): void
    {
        $user    = require_login();
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

    /** POST /?page=financial&action=claim_status  (id, do = approve|reject|reimburse|reopen) */
    public function status(): void
    {
        $user   = require_login();
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

    /** POST /?page=financial&action=claim_delete */
    public function delete(): void
    {
        $user   = require_login();
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

    /* ---------------------------------------------------------- private ---- */

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

    private function renderForm(array $user, ?array $claim, array $values, array $errors = []): void
    {
        view('financial/claim_form', [
            'user'   => $user,
            'claim'  => $claim,
            'values' => $values,
            'errors' => $errors,
        ]);
    }

    private function fail(array $user, ?array $claim, array $values, array $errors): void
    {
        if (wants_json()) {
            json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the highlighted fields.', 422);
        }

        set_status(422);
        $this->renderForm($user, $claim, $values, $errors);
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
