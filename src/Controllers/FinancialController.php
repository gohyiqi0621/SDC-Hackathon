<?php
/** Invoice listing, PDF printing, shared Claims support. */
declare(strict_types=1);

namespace Src\Controllers;

use RuntimeException;
use Src\Models\Invoice;
use Src\Models\InvoiceItem;
use Src\Services\DocumentExtractService;
use Src\Services\InvoicePdfService;
use Src\Services\InvoiceService;

final class FinancialController
{
    private const PER_PAGE = 10;

    public function invoice(): void
    {
        $this->renderList(require_login());
    }

    public function role(): void
    {
        require_login();

        $back = (string) ($_POST['return'] ?? '');
        if ($back !== url('financial', ['action' => 'claims'])) {
            abort(404, 'Role switching is only available on Claims.');
        }
        $_SESSION['finance_role'] = ($_POST['role'] ?? '') === 'supervisor' ? 'supervisor' : 'user';

        redirect($back);
    }


    public function print(): void
    {
        $user    = require_login();
        $invoice = $this->ownedOr404($user, (int) ($_GET['id'] ?? 0));

        if ($invoice['status'] === 'draft') {
            abort(404, 'Draft invoices are not available for printing.');
        }

        $items = (new InvoiceItem())->forInvoice((int) $invoice['id']);
        $pdf   = (new InvoicePdfService())->render($invoice, $items, (int) $user['id']);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . rawurlencode('Invoice-' . $invoice['invoice_number'] . '.pdf') . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

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

    /* ---------------------------------------------------------- private ---- */

    private function renderList(array $user): void
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
        ]);
    }

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


}
