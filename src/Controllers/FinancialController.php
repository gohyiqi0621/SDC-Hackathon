<?php
/**
 * FinancialController.php - Financial > Invoice, and the User / Supervisor switch.
 *
 * One page: the invoice list (search, filter, sort, paginate) with an
 * "Upload invoice" popup to add one by hand or with a PDF / image. Anyone can
 * view and upload; only a Supervisor can edit, mark paid or delete (see
 * finance_role()). Claims live in ClaimController.
 */
declare(strict_types=1);

namespace Src\Controllers;

use PDOException;
use Src\Models\Invoice;
use Src\Models\InvoiceItem;
use Src\Services\InvoiceService;

final class FinancialController
{
    private const PER_PAGE = 10;

    /**
     * GET /?page=financial&action=invoice
     *   &new=1     opens the upload popup
     *   &edit=N    opens the popup on invoice N (Supervisor only)
     */
    public function invoice(): void
    {
        $user  = require_login();
        $modal = null;

        if (!empty($_GET['new'])) {
            $modal = $this->newModal();
        } elseif (!empty($_GET['edit']) && is_supervisor()) {
            $invoice = $this->ownedOr404($user, (int) $_GET['edit']);
            $values  = $invoice + ['items' => (new InvoiceItem())->forInvoice((int) $invoice['id'])];
            $modal   = ['invoice' => $invoice, 'values' => $values, 'errors' => []];
        }

        $this->renderList($user, $modal);
    }

    /** POST /?page=financial&action=role  (role = user|supervisor) - the demo role switch. */
    public function role(): void
    {
        require_login();

        $_SESSION['finance_role'] = ($_POST['role'] ?? '') === 'supervisor' ? 'supervisor' : 'user';

        $back = (string) ($_POST['return'] ?? '');
        redirect(str_starts_with($back, APP_URL . '/') ? $back : url('financial', ['action' => 'invoice']));
    }

    /** POST /?page=financial&action=store - anyone may upload. */
    public function store(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $service = new InvoiceService();
        $upload  = $_FILES['invoice_file'] ?? null;

        [$data, $errors] = $service->validate($userId, $_POST);
        $fileError = $service->fileError($upload);
        if ($fileError !== null) {
            $errors['invoice_file'] = $fileError;
        }

        // Payment status is a Supervisor decision; a User's upload starts unpaid.
        if (!is_supervisor()) {
            $data['status'] = 'unpaid';
            unset($errors['status']);
        }

        if ($errors !== []) {
            $this->fail($user, null, $_POST, $errors);
            return;
        }

        $file = $service->hasFile($upload) ? $service->storeFile($userId, $upload) : null;
        if ($service->hasFile($upload) && $file === null) {
            $this->fail($user, null, $_POST, ['invoice_file' => 'The file could not be saved. Please try again.']);
            return;
        }

        try {
            (new Invoice())->create($userId, $data + ($file ?? []));
        } catch (PDOException $e) {
            $service->deleteFile($userId, $file['file_path'] ?? null);
            $this->fail($user, null, $_POST, $this->dbErrors($e));
            return;
        }

        respond_ok('Invoice "' . $data['invoice_number'] . '" was submitted.', url('financial', ['action' => 'invoice']));
    }

    /** POST /?page=financial&action=update - Supervisor only. */
    public function update(): void
    {
        $user = require_login();
        require_supervisor('edit an invoice');

        $userId  = (int) $user['id'];
        $invoice = $this->ownedOr404($user, (int) ($_POST['id'] ?? 0));
        $service = new InvoiceService();
        $upload  = $_FILES['invoice_file'] ?? null;

        [$data, $errors] = $service->validate($userId, $_POST, (int) $invoice['id']);
        $fileError = $service->fileError($upload);
        if ($fileError !== null) {
            $errors['invoice_file'] = $fileError;
        }

        if ($errors !== []) {
            $this->fail($user, $invoice, $_POST + $this->fileColumns($invoice), $errors);
            return;
        }

        $newFile = $service->hasFile($upload) ? $service->storeFile($userId, $upload) : null;
        if ($service->hasFile($upload) && $newFile === null) {
            $this->fail($user, $invoice, $_POST + $this->fileColumns($invoice), ['invoice_file' => 'The file could not be saved. Please try again.']);
            return;
        }

        $remove = !empty($_POST['remove_file']);
        $file   = $newFile ?? ($remove ? $this->fileColumns([]) : $this->fileColumns($invoice));

        try {
            (new Invoice())->update($userId, (int) $invoice['id'], $data + $file);
        } catch (PDOException $e) {
            $service->deleteFile($userId, $newFile['file_path'] ?? null);
            $this->fail($user, $invoice, $_POST + $this->fileColumns($invoice), $this->dbErrors($e));
            return;
        }

        // The old file is only removed once the database no longer points at it.
        if ($invoice['file_path'] !== null && $invoice['file_path'] !== $file['file_path']) {
            $service->deleteFile($userId, $invoice['file_path']);
        }

        respond_ok('Invoice "' . $data['invoice_number'] . '" was updated.', url('financial', ['action' => 'invoice']));
    }

    /** POST /?page=financial&action=mark_paid - Supervisor only. */
    public function markPaid(): void
    {
        $user = require_login();
        require_supervisor('mark an invoice as paid');

        $userId  = (int) $user['id'];
        $invoice = (new Invoice())->find($userId, (int) ($_POST['id'] ?? 0));

        if ($invoice === null || !(new Invoice())->markPaid($userId, (int) $invoice['id'])) {
            flash('error', 'That invoice was not found or is already paid.', 'Not changed');
            redirect($this->backUrl());
        }

        flash('success', 'Invoice "' . $invoice['invoice_number'] . '" is now marked as paid.', 'Marked as paid');
        redirect($this->backUrl());
    }

    /** POST /?page=financial&action=delete - Supervisor only. */
    public function delete(): void
    {
        $user = require_login();
        require_supervisor('delete an invoice');

        $userId  = (int) $user['id'];
        $invoice = (new Invoice())->find($userId, (int) ($_POST['id'] ?? 0));

        if ($invoice === null) {
            flash('error', 'That invoice was not found. It may already have been removed.', 'Not removed');
            redirect($this->backUrl());
        }

        (new Invoice())->delete($userId, (int) $invoice['id']);
        (new InvoiceService())->deleteFile($userId, $invoice['file_path']);

        flash('success', 'Invoice "' . $invoice['invoice_number'] . '" was removed.', 'Invoice removed');
        redirect($this->backUrl());
    }

    /** GET /?page=financial&action=file&id=N[&download=1] - stream the stored file to its owner. */
    public function file(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $invoice = $this->ownedOr404($user, (int) ($_GET['id'] ?? 0));
        $path    = (new InvoiceService())->pathFor($userId, $invoice['file_path']);

        if ($path === null) {
            abort(404, 'This invoice has no file attached.');
        }

        (new InvoiceService())->files()->send(
            $path,
            (string) $invoice['file_mime'],
            (string) ($invoice['file_name'] ?: 'invoice'),
            !empty($_GET['download'])
        );
    }

    /* ---------------------------------------- old addresses (redirect) ---- */

    /** GET /?page=financial&action=upload - the upload page became a popup. */
    public function upload(): void
    {
        redirect(url('financial', ['action' => 'invoice', 'new' => 1]));
    }

    /** GET /?page=financial&action=edit&id=N */
    public function edit(): void
    {
        redirect(url('financial', ['action' => 'invoice', 'edit' => (int) ($_GET['id'] ?? 0)]));
    }

    /* ---------------------------------------------------------- private ---- */

    /**
     * The list page, optionally with the popup open.
     *
     * @param array{invoice: ?array, values: array, errors: array}|null $modal
     */
    private function renderList(array $user, ?array $modal): void
    {
        $userId  = (int) $user['id'];
        $filters = $this->filters();
        $page    = max(1, (int) ($_GET['p'] ?? 1));

        $result = (new Invoice())->search($userId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $pages  = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        // Asked for a page past the end (e.g. after a delete): show the last one.
        if ($page > $pages && $result['total'] > 0) {
            $page   = $pages;
            $result = (new Invoice())->search($userId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }

        view('financial/invoice', [
            'user'      => $user,
            'invoices'  => $result['items'],
            'total'     => $result['total'],
            'allCount'  => (new Invoice())->countForUser($userId),
            'stats'     => (new Invoice())->stats($userId),
            'filters'   => $filters,
            'page'      => $page,
            'pages'     => $pages,
            'perPage'   => self::PER_PAGE,
            'modal'     => $modal ?? $this->newModal(),
            'modalOpen' => $modal !== null,
        ]);
    }

    /** @return array{invoice: null, values: array, errors: array} */
    private function newModal(): array
    {
        return [
            'invoice' => null,
            'values'  => ['type' => 'purchase', 'issue_date' => date('Y-m-d'), 'currency' => 'MYR', 'status' => 'unpaid'],
            'errors'  => [],
        ];
    }

    /** Search / filter / sort values from the query string, cleaned. */
    private function filters(): array
    {
        $sort   = (string) ($_GET['sort'] ?? 'newest');
        $status = (string) ($_GET['status'] ?? '');
        $type   = (string) ($_GET['type'] ?? '');

        return [
            'q'      => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'status' => in_array($status, Invoice::STATUSES, true) ? $status : '',
            'type'   => isset(Invoice::TYPES[$type]) ? $type : '',
            'sort'   => isset(Invoice::SORTS[$sort]) ? $sort : 'newest',
        ];
    }

    /** @return array{file_path: ?string, file_name: ?string, file_mime: ?string, file_size: ?int} */
    private function fileColumns(array $invoice): array
    {
        return [
            'file_path' => $invoice['file_path'] ?? null,
            'file_name' => $invoice['file_name'] ?? null,
            'file_mime' => $invoice['file_mime'] ?? null,
            'file_size' => isset($invoice['file_size']) ? (int) $invoice['file_size'] : null,
        ];
    }

    private function ownedOr404(array $user, int $id): array
    {
        $invoice = $id > 0 ? (new Invoice())->find((int) $user['id'], $id) : null;

        if ($invoice === null) {
            abort(404, 'That invoice was not found.');
        }

        return $invoice;
    }

    /** A failed save: JSON errors for the popup, or the list with the popup reopened. */
    private function fail(array $user, ?array $invoice, array $values, array $errors): void
    {
        if (wants_json()) {
            json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the highlighted fields.', 422);
        }

        set_status(422);
        $this->renderList($user, ['invoice' => $invoice, 'values' => $values, 'errors' => $errors]);
    }

    /** Turn a constraint error (e.g. a duplicate number from a double submit) into a field error. */
    private function dbErrors(PDOException $e): array
    {
        $message = $e->getMessage();

        if ($e->getCode() === '23505' && str_contains($message, 'invoices_user_number_uniq')) {
            return ['invoice_number' => 'You already have an invoice with this number.'];
        }
        if ($e->getCode() === '23514') {
            return ['form' => 'Some values are not allowed. Check the dates, amount and status.'];
        }

        error_log('[invoices] ' . $message);

        return ['form' => 'The invoice could not be saved. Please try again.'];
    }

    /** Return to the page the user came from (keeps filters and page), never off-site. */
    private function backUrl(): string
    {
        $back = (string) ($_POST['return'] ?? '');

        return str_starts_with($back, APP_URL . '/') ? $back : url('financial', ['action' => 'invoice']);
    }
}
