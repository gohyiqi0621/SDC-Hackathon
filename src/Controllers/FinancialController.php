<?php
/**
 * FinancialController.php - Financial > Invoice, and the User / Supervisor switch.
 *
 * The business CREATES invoices for its customers from its own Products &
 * Services (popup: customer + tick products + quantities), as a draft or
 * straight away (numbered INV-2026-0001). Payments are recorded against an
 * invoice as receipts (RCPT-2026-0001); partial payments are allowed.
 *
 *   Anyone      view, create, save / edit drafts, print invoices and receipts
 *   Supervisor  also edit an unpaid invoice with no payments, record payments,
 *               delete drafts / unpaid invoices with no payments, SST settings
 *
 * Claims live in ClaimController.
 */
declare(strict_types=1);

namespace Src\Controllers;

use PDOException;
use RuntimeException;
use Src\Models\FinanceSetting;
use Src\Models\Invoice;
use Src\Models\InvoiceItem;
use Src\Models\Order;
use Src\Models\Product;
use Src\Models\Receipt;
use Src\Services\DocumentExtractService;
use Src\Services\InvoicePdfService;
use Src\Services\InvoiceReceiptPdf;
use Src\Services\InvoiceService;

final class FinancialController
{
    private const PER_PAGE = 10;

    /**
     * GET /?page=financial&action=invoice
     *   &new=1           the create popup
     *   &edit=N          the popup on draft N (or unpaid N for a Supervisor)
     *   &from_order=N    the create popup filled from order N
     *   &payments=N      the payments / receipts popup of invoice N
     */
    public function invoice(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $modal  = null;
        $pay    = null;

        if (!empty($_GET['new'])) {
            $modal = $this->newModal($userId);
        } elseif (!empty($_GET['edit'])) {
            $invoice = $this->ownedOr404($user, (int) $_GET['edit']);
            if ($this->canEdit($invoice)) {
                $modal = $this->editModal($invoice);
            } else {
                flash('error', 'This invoice can no longer be changed.', 'Locked');
            }
        } elseif (!empty($_GET['from_order'])) {
            $modal = $this->orderModal($userId, (int) $_GET['from_order']);
        } elseif (!empty($_GET['payments'])) {
            $pay = $this->paymentsModal($user, $this->ownedOr404($user, (int) $_GET['payments']));
        }

        $this->renderList($user, $modal, $pay);
    }

    /** POST /?page=financial&action=role  (role = user|supervisor) - the demo role switch. */
    public function role(): void
    {
        require_login();

        $_SESSION['finance_role'] = ($_POST['role'] ?? '') === 'supervisor' ? 'supervisor' : 'user';

        $back = (string) ($_POST['return'] ?? '');
        redirect(str_starts_with($back, APP_URL . '/') ? $back : url('financial', ['action' => 'invoice']));
    }

    /** POST /?page=financial&action=store  (intent = draft|issue) - anyone may create. */
    public function store(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $issue  = ($_POST['intent'] ?? '') === 'issue';

        [$data, $errors] = (new InvoiceService())->validate($userId, $_POST, $issue, (new FinanceSetting())->sstRate($userId));

        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId > 0) {
            if ((new Order())->find($userId, $orderId) === null) {
                $errors['form'] = 'That order was not found.';
            } elseif (($existing = (new Invoice())->findByOrder($userId, $orderId)) !== null) {
                $errors['form'] = 'Order already invoiced' . ($existing['invoice_number'] ? ' as ' . $existing['invoice_number'] : ' (draft)') . '.';
            } else {
                $data['order_id'] = $orderId;
            }
        }

        if ($errors !== []) {
            $this->fail($user, null, $_POST, $errors);
            return;
        }

        try {
            $id = (new Invoice())->create($userId, $data, $issue);
        } catch (PDOException $e) {
            $this->fail($user, null, $_POST, $this->dbErrors($e));
            return;
        }

        $invoice = (new Invoice())->find($userId, $id);
        respond_ok(
            $issue ? 'Invoice ' . $invoice['invoice_number'] . ' was created.' : 'Draft saved. You can finish it later.',
            url('financial', ['action' => 'invoice'])
        );
    }

    /** POST /?page=financial&action=update  (id, intent = draft|issue) */
    public function update(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $invoice = $this->ownedOr404($user, (int) ($_POST['id'] ?? 0));
        $issue   = $invoice['status'] === 'draft' && ($_POST['intent'] ?? '') === 'issue';

        if (!$this->canEdit($invoice)) {
            $this->fail($user, $invoice, $_POST, ['form' => is_supervisor()
                ? 'This invoice already has payments and can no longer be changed.'
                : 'Only a supervisor can change an invoice once it is created.']);
            return;
        }

        $mustHaveItems = $issue || $invoice['status'] !== 'draft';
        [$data, $errors] = (new InvoiceService())->validate($userId, $_POST, $mustHaveItems, (new FinanceSetting())->sstRate($userId));
        $data['order_id'] = $invoice['order_id'];

        if ($errors !== []) {
            $this->fail($user, $invoice, $_POST, $errors);
            return;
        }

        try {
            $saved = (new Invoice())->save($userId, (int) $invoice['id'], $data, $issue);
        } catch (PDOException $e) {
            $this->fail($user, $invoice, $_POST, $this->dbErrors($e));
            return;
        }

        if (!$saved) {
            $this->fail($user, $invoice, $_POST, ['form' => 'This invoice changed in the meantime and can no longer be edited.']);
            return;
        }

        $fresh = (new Invoice())->find($userId, (int) $invoice['id']);
        respond_ok(
            $issue ? 'Invoice ' . $fresh['invoice_number'] . ' was created.'
                   : ($fresh['status'] === 'draft' ? 'Draft saved.' : 'Invoice ' . $fresh['invoice_number'] . ' was updated.'),
            url('financial', ['action' => 'invoice'])
        );
    }

    /** POST /?page=financial&action=pay  (id, amount, payment_date, method, reference, notes) - Supervisor only. */
    public function pay(): void
    {
        $user = require_login();
        require_supervisor('record a payment');

        $userId  = (int) $user['id'];
        $invoice = $this->ownedOr404($user, (int) ($_POST['id'] ?? 0));

        [$data, $errors] = (new InvoiceService())->validatePayment($_POST, $invoice);
        if ($errors === []) {
            try {
                $receiptId = (new Receipt())->record($userId, (int) $invoice['id'], $data);
            } catch (RuntimeException $e) {
                $errors['form'] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            if (wants_json()) {
                json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the highlighted fields.', 422);
            }
            set_status(422);
            $this->renderList($user, null, $this->paymentsModal($user, $invoice, $_POST, $errors));
            return;
        }

        $receipt = (new Receipt())->find($userId, $receiptId);
        respond_ok(
            'Payment recorded. Receipt ' . $receipt['receipt_number'] . ' is ready to print.',
            url('financial', ['action' => 'invoice', 'payments' => $invoice['id']])
        );
    }

    /** POST /?page=financial&action=settings  (sst_registered, sst_rate) - Supervisor only. */
    public function settings(): void
    {
        $user = require_login();
        require_supervisor('change the SST setting');

        $registered = !empty($_POST['sst_registered']);
        $rate       = str_replace(['%', ' '], '', trim((string) ($_POST['sst_rate'] ?? '')));

        if ($registered && (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $rate) || (float) $rate <= 0 || (float) $rate > 100)) {
            flash('error', 'Enter the SST rate as a percentage, e.g. 8.', 'Not saved');
            redirect(url('financial', ['action' => 'invoice']));
        }

        (new FinanceSetting())->save((int) $user['id'], $registered, $registered ? $rate : '8');

        flash('success', $registered
            ? 'New invoices will charge SST at ' . rtrim(rtrim($rate, '0'), '.') . '%.'
            : 'New invoices will not charge SST.', 'SST setting saved');
        redirect(url('financial', ['action' => 'invoice']));
    }

    /** POST /?page=financial&action=delete - Supervisor only; drafts / unpaid with no payments. */
    public function delete(): void
    {
        $user = require_login();
        require_supervisor('delete an invoice');

        $userId  = (int) $user['id'];
        $invoice = (new Invoice())->find($userId, (int) ($_POST['id'] ?? 0));
        $label   = $invoice === null ? '' : ($invoice['invoice_number'] ?: 'Draft for ' . $invoice['party_name']);

        if ($invoice === null || !(new Invoice())->delete($userId, (int) $invoice['id'])) {
            flash('error', 'Only drafts and unpaid invoices with no payments can be removed.', 'Not removed');
            redirect($this->backUrl());
        }

        // Older uploaded invoices may still have a stored file.
        (new InvoiceService())->files()->delete($userId, $invoice['file_path']);

        flash('success', $label . ' was removed.', 'Invoice removed');
        redirect($this->backUrl());
    }

    /** GET /?page=financial&action=print&id=N - the invoice as a PDF (not for drafts). */
    public function print(): void
    {
        $user    = require_login();
        $invoice = $this->ownedOr404($user, (int) ($_GET['id'] ?? 0));

        if ($invoice['status'] === 'draft') {
            abort(404, 'A draft has no invoice number yet. Create the invoice first, then print it.');
        }

        $items = (new InvoiceItem())->forInvoice((int) $invoice['id']);
        $pdf   = (new InvoicePdfService())->render($invoice, $items);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode('Invoice-' . $invoice['invoice_number'] . '.pdf') . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /** GET /?page=financial&action=receipt&id=N - one receipt as a PDF. */
    public function receipt(): void
    {
        $user    = require_login();
        $receipt = (new Receipt())->find((int) $user['id'], (int) ($_GET['id'] ?? 0));

        if ($receipt === null) {
            abort(404, 'That receipt was not found.');
        }

        $pdf = (new InvoiceReceiptPdf())->render($receipt, \company());

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode('Receipt-' . $receipt['receipt_number'] . '.pdf') . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    /** GET /?page=financial&action=file&id=N[&download=1] - the file of an older uploaded invoice. */
    public function file(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $invoice = $this->ownedOr404($user, (int) ($_GET['id'] ?? 0));
        $files   = (new InvoiceService())->files();
        $path    = $files->pathFor($userId, $invoice['file_path']);

        if ($path === null) {
            abort(404, 'This invoice has no file attached.');
        }

        $files->send($path, (string) $invoice['file_mime'], (string) ($invoice['file_name'] ?: 'invoice'), !empty($_GET['download']));
    }

    /**
     * POST /?page=financial&action=extract  (kind = claim, pages[] = page images as data URLs)
     *
     * AI auto-fill for the New claim popup: reads the receipt and returns its
     * fields as JSON. Saves nothing.
     */
    public function extract(): void
    {
        $user = require_login();

        // A light brake on accidental loops: at most 40 reads per hour per session.
        $now    = time();
        $recent = array_values(array_filter((array) ($_SESSION['extract_calls'] ?? []), static fn ($t) => $t > $now - 3600));
        if (count($recent) >= 40) {
            json_response(false, null, 'Too many AI reads this hour. Please type the details, or try again later.', 429);
        }
        $_SESSION['extract_calls'] = array_merge($recent, [$now]);

        try {
            $result = (new DocumentExtractService())->extract((int) $user['id'], 'claim', (array) ($_POST['pages'] ?? []));
        } catch (RuntimeException $e) {
            json_response(false, null, $e->getMessage(), 422);
        }

        json_response(true, $result, 'Filled by AI. Please check every field.');
    }

    /* ---------------------------------------- old addresses (redirect) ---- */

    /** GET /?page=financial&action=upload - invoices are created now, not uploaded. */
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

    /** Drafts: anyone. Unpaid with no payments (and built from products): Supervisor. */
    private function canEdit(array $invoice): bool
    {
        if ($invoice['status'] === 'draft') {
            return true;
        }

        return is_supervisor() && $invoice['status'] === 'unpaid' && (float) $invoice['amount_paid'] === 0.0
            && $invoice['type'] === 'sales' && empty($invoice['file_path']);
    }

    /**
     * The list page, optionally with the create popup or the payments popup open.
     *
     * @param array{invoice: ?array, values: array, errors: array}|null $modal
     * @param array<string, mixed>|null $pay
     */
    private function renderList(array $user, ?array $modal, ?array $pay = null): void
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
            'modal'     => $modal ?? $this->newModal($userId),
            'modalOpen' => $modal !== null,
            'pay'       => $pay,
            'products'  => (new Product())->forInvoicing($userId),
            'customers' => (new Invoice())->customers($userId),
            'finance'   => (new FinanceSetting())->forUser($userId),
        ]);
    }

    /** @return array{invoice: null, values: array, errors: array} */
    private function newModal(int $userId): array
    {
        return [
            'invoice' => null,
            'values'  => ['issue_date' => date('Y-m-d'), 'currency' => 'MYR', 'lines' => []],
            'errors'  => [],
        ];
    }

    /** The popup on a saved invoice: its product lines become ticked quantities. */
    private function editModal(array $invoice): array
    {
        $lines = [];
        foreach ((new InvoiceItem())->forInvoice((int) $invoice['id']) as $item) {
            if ($item['product_id'] !== null) {
                $lines[(int) $item['product_id']] = rtrim(rtrim((string) $item['quantity'], '0'), '.');
            }
        }

        return ['invoice' => $invoice, 'values' => $invoice + ['lines' => $lines], 'errors' => []];
    }

    /** A new invoice filled from an order: its customer and products. */
    private function orderModal(int $userId, int $orderId): ?array
    {
        $order = (new Order())->find($userId, $orderId);
        if ($order === null) {
            flash('error', 'That order was not found.', 'Not found');
            return null;
        }

        $existing = (new Invoice())->findByOrder($userId, $orderId);
        if ($existing !== null) {
            // Created automatically with the order: open it (with its payments).
            redirect(url('financial', ['action' => 'invoice', 'payments' => (int) $existing['id']]));
        }

        $lines   = [];
        $missing = 0;
        foreach ((new Order())->items($orderId) as $item) {
            if ($item['product_id'] !== null) {
                $lines[(int) $item['product_id']] = (string) ((int) ($lines[(int) $item['product_id']] ?? 0) + (int) $item['quantity']);
            } else {
                $missing++;
            }
        }
        if ($missing > 0) {
            flash('info', $missing . ' item(s) of this order are no longer in Products & Services and were left out.', 'Check the items');
        }

        return [
            'invoice' => null,
            'values'  => [
                'party_name'   => (string) ($order['customer_name'] ?? ''),
                'party_phone'  => (string) ($order['customer_phone'] ?? ''),
                'reference_no' => (string) $order['order_no'],
                'notes'        => (string) ($order['delivery_note'] ?? ''),
                'issue_date'   => date('Y-m-d'),
                'currency'     => (string) ($order['currency'] ?: 'MYR'),
                'order_id'     => $orderId,
                'lines'        => $lines,
            ],
            'errors'  => [],
        ];
    }

    /** The payments popup: the invoice, its receipts, and (Supervisor) the record-payment form. */
    private function paymentsModal(array $user, array $invoice, array $values = [], array $errors = []): array
    {
        if ($invoice['status'] === 'draft') {
            flash('info', 'A draft has no payments. Create the invoice first.', 'Draft');
        }

        $balance = number_format((float) $invoice['amount'] - (float) $invoice['amount_paid'], 2, '.', '');

        return [
            'invoice'  => $invoice,
            'receipts' => (new Receipt())->forInvoice((int) $user['id'], (int) $invoice['id']),
            'values'   => $values + ['amount' => $balance, 'payment_date' => date('Y-m-d'), 'method' => 'bank_transfer'],
            'errors'   => $errors,
        ];
    }

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

    /** Turn a constraint error into a message. */
    private function dbErrors(PDOException $e): array
    {
        $message = $e->getMessage();

        if ($e->getCode() === '23505' && str_contains($message, 'invoices_order_uniq')) {
            return ['form' => 'This order has already been invoiced.'];
        }
        if ($e->getCode() === '23514') {
            return ['form' => 'Some values are not allowed. Check the dates and quantities.'];
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
