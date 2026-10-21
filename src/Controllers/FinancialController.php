<?php
/**
 * FinancialController.php - Invoice, Upload Invoice and Settlement.
 *
 * Invoices are stored per user: list (search, filter, sort, paginate), add by
 * hand or with a PDF / image, edit, remove, and open the stored file.
 * Claims live in ClaimController. Settlement still shows its empty state.
 */
declare(strict_types=1);

namespace Src\Controllers;

use PDOException;
use Src\Models\Invoice;
use Src\Services\InvoiceService;

final class FinancialController
{
    private const PER_PAGE      = 10;
    private const LIBRARY_LIMIT = 50;

    /** GET /?page=financial&action=invoice */
    public function invoice(): void
    {
        $user    = require_login();
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
            'user'     => $user,
            'invoices' => $result['items'],
            'total'    => $result['total'],
            'allCount' => (new Invoice())->countForUser($userId),
            'stats'    => (new Invoice())->stats($userId),
            'filters'  => $filters,
            'page'     => $page,
            'pages'    => $pages,
            'perPage'  => self::PER_PAGE,
        ]);
    }

    /** GET /?page=financial&action=upload - the form plus the file library beside it. */
    public function upload(): void
    {
        $user = require_login();

        $this->renderForm($user, null, [
            'issue_date' => date('Y-m-d'),
            'currency'   => 'MYR',
            'status'     => 'unpaid',
        ]);
    }

    /** POST /?page=financial&action=store */
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

        respond_ok('Invoice "' . $data['invoice_number'] . '" was saved.', url('financial', ['action' => 'upload']));
    }

    /** GET /?page=financial&action=edit&id=N */
    public function edit(): void
    {
        $user    = require_login();
        $invoice = $this->ownedOr404($user, (int) ($_GET['id'] ?? 0));

        $this->renderForm($user, $invoice, $invoice);
    }

    /** POST /?page=financial&action=update */
    public function update(): void
    {
        $user    = require_login();
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

    /** POST /?page=financial&action=delete */
    public function delete(): void
    {
        $user    = require_login();
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

    /** GET /?page=financial&action=settlement */
    public function settlement(): void
    {
        view('financial/settlement', ['user' => require_login()]);
    }

    /* ---------------------------------------------------------- private ---- */

    /** Search / filter / sort values from the query string, cleaned. */
    private function filters(): array
    {
        $sort   = (string) ($_GET['sort'] ?? 'newest');
        $status = (string) ($_GET['status'] ?? '');

        return [
            'q'      => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'status' => in_array($status, Invoice::STATUSES, true) ? $status : '',
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

    /** The upload page: the form (new or edit) and the searchable file library. */
    private function renderForm(array $user, ?array $invoice, array $values, array $errors = []): void
    {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

        view('financial/upload', [
            'user'      => $user,
            'invoice'   => $invoice,
            'values'    => $values,
            'errors'    => $errors,
            'library'   => (new Invoice())->library((int) $user['id'], $q, self::LIBRARY_LIMIT),
            'libraryQ'  => $q,
            'fileCount' => (new Invoice())->countForUser((int) $user['id']),
        ]);
    }

    private function fail(array $user, ?array $invoice, array $values, array $errors): void
    {
        if (wants_json()) {
            json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the highlighted fields.', 422);
        }

        set_status(422);
        $this->renderForm($user, $invoice, $values, $errors);
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
