<?php
/**
 * OrderController.php - AI Chatbot > Customer > Orders.
 *
 * Orders placed through the AI chatbot (Flowise tool buy_product). Staff open
 * the customer's payment receipt and approve or reject it; the customer is
 * told on Telegram either way (OrderService).
 *
 *   GET  orders               list: summary cards, status filter + search, table, Details popups
 *   GET  orders/receipt       the receipt file (only the owner's orders)
 *   POST orders/approve       -> paid ("Payment successful"), Telegram "Payment verified"
 *                                only after the customer sent a receipt
 *   POST orders/ship          -> shipped, Telegram "Your order has been shipped"
 *   POST orders/reject        -> payment_rejected, Telegram asks for a new receipt
 *   POST orders/cancel        -> cancelled
 *   POST orders/delete        delete the ticked orders (ids[]); unpaid invoices go too,
 *                             paid invoices stay in Financial > Invoice
 *   POST orders/send_invoice  the order's invoice PDF to the customer on Telegram
 *   POST orders/send_receipt  the official receipt PDF to the customer on Telegram
 */
declare(strict_types=1);

namespace Src\Controllers;

use PDOException;
use RuntimeException;
use Src\Models\Order;
use Src\Services\OfficialReceiptPdf;
use Src\Services\OrderService;
use Src\Services\PrivateFiles;

final class OrderController
{
    private const PER_PAGE = 15;

    /** The status filter on the Orders page: value => label ('' = all). */
    public const FILTERS = [
        ''                  => 'All',
        'awaiting'          => 'Awaiting payment',
        'payment_submitted' => 'Verify payment',
        'paid'              => 'Payment successful',
        'shipped'           => 'Shipped',
    ];

    /** Date filter choices on the Orders page. */
    public const RANGES = [
        ''          => 'Any date',
        'today'     => 'Today',
        'yesterday' => 'Yesterday',
        '7d'        => 'Last 7 days',
        '30d'       => 'Last 30 days',
        'month'     => 'This month',
        'custom'    => 'Custom dates',
    ];

    /** GET orders */
    public function index(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $page   = max(1, (int) ($_GET['p'] ?? 1));
        $model  = new Order();
        $f      = $this->filtersFromQuery();

        try {
            $summary  = $model->summary($userId, $f);
            $total    = $summary['count'];
            $pages    = max(1, (int) ceil($total / self::PER_PAGE));
            $page     = min($page, $pages);
            $orders   = $model->search($userId, $f, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $counts   = $model->countsByStatus($userId, $f);
            $overview = $model->overview($userId);
            $items    = $model->itemsFor(array_map(static fn (array $o): int => (int) $o['id'], $orders));
            $invoices = $this->invoicesFor($userId, $orders);
            $missingTable = false;
        } catch (PDOException $e) {
            if (!in_array((string) $e->getCode(), ['42P01', '42703'], true)) {
                throw $e;
            }
            [$total, $pages, $orders, $items, $invoices, $missingTable] = [0, 1, [], [], [], true];
            $summary  = ['count' => 0, 'sum' => '0', 'currency' => 'MYR'];
            $counts   = array_fill_keys(array_keys(Order::STATUSES), 0);
            $overview = ['review' => 0, 'waiting' => 0, 'to_ship' => 0, 'today' => 0, 'sales_today' => format_money(0)];
        }

        view('orders/index', [
            'user'         => $user,
            'orders'       => $orders,
            'items'        => $items,
            'counts'       => $counts,
            'overview'     => $overview,
            'summary'      => $summary,
            'invoices'     => $invoices,
            'f'            => $f,
            'status'       => $f['status'],
            'q'            => $f['q'],
            'total'        => $total,
            'page'         => $page,
            'pages'        => $pages,
            'perPage'      => self::PER_PAGE,
            'missingTable' => $missingTable,
        ]);
    }

    /**
     * The Orders page filters from the query string, validated.
     *
     * @return array{status: string, q: string, range: string, from: ?string, to: ?string, product: string, sort: string}
     */
    private function filtersFromQuery(): array
    {
        $status = (string) ($_GET['status'] ?? '');
        if ($status === 'pending_payment' || $status === 'payment_rejected') {
            $status = 'awaiting';   // old links
        }
        return [
            'status'  => isset(self::FILTERS[$status]) ? $status : '',
            'q'       => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'range'   => '',
            'from'    => null,
            'to'      => null,
            'product' => '',
            'sort'    => 'newest',
        ];
    }

    /** order id => its invoice (made when the order was placed), when there is one. */
    private function invoicesFor(int $userId, array $orders): array
    {
        $out = [];
        try {
            $model = new \Src\Models\Invoice();
            foreach ($orders as $order) {
                $invoice = $model->findByOrder($userId, (int) $order['id']);
                if ($invoice !== null) {
                    $out[(int) $order['id']] = $invoice;
                }
            }
        } catch (\Throwable $e) {
            // invoices table not there yet (migration 022): the Details popup says "not created"
        }
        return $out;
    }

    /** GET orders/receipt?id= - the bank reference (transfer slip) the customer sent. */
    public function receipt(): void
    {
        $user  = require_login();
        $order = (new Order())->find((int) $user['id'], (int) ($_GET['id'] ?? 0));
        $files = new PrivateFiles('receipts');
        $path  = $order !== null ? $files->pathFor((int) $user['id'], $order['receipt_path']) : null;

        if ($path === null) {
            abort(404, 'This order has no bank reference yet.');
        }

        $files->send($path, (string) $order['receipt_mime'], (string) ($order['receipt_name'] ?: 'bank-reference'), !empty($_GET['download']));
    }

    /**
     * GET orders/official_receipt?id=[&download=1] - the official receipt PDF
     * (only for "Payment successful" and "Shipped" orders).
     */
    public function officialReceipt(): void
    {
        $user  = require_login();
        $order = (new Order())->find((int) $user['id'], (int) ($_GET['id'] ?? 0));

        if ($order === null) {
            abort(404, 'That order was not found.');
        }
        if (!in_array((string) $order['status'], OfficialReceiptPdf::STATUSES, true)) {
            abort(404, 'The official receipt is issued after the payment is approved.');
        }

        try {
            $pdf = (new OrderService())->officialReceipt((int) $user['id'], $order);
        } catch (\Throwable $e) {
            error_log('[official-receipt] ' . $e->getMessage());
            abort(500, 'The official receipt could not be created: ' . $e->getMessage());
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . OfficialReceiptPdf::fileName($order) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }

    /** POST orders/approve */
    public function approve(): void
    {
        $this->change(function (int $userId, array $order): void {
            $result = (new OrderService())->approve($userId, $order);
            flash('success', $result['notified']
                ? 'The customer was told on Telegram: "Payment verified"' . (!empty($result['receipt_sent']) ? ', with the official receipt (PDF).' : '. The official receipt could not be sent; download it from the order.')
                : 'Marked as paid. The customer could not be messaged on Telegram (check the Telegram connection). The official receipt is on the order.',
                'Order ' . $order['order_no'] . ': payment successful');
        });
    }

    /** POST orders/send_receipt - send the official receipt PDF to the customer on Telegram (again). */
    public function sendReceipt(): void
    {
        $this->change(function (int $userId, array $order): void {
            if ((new OrderService())->resendOfficialReceipt($userId, $order)) {
                flash('success', 'The official receipt (PDF) was sent to the customer on Telegram.', 'Receipt sent');
            } else {
                flash('error', 'Telegram did not accept the file. Check the Telegram connection, or download the receipt and send it yourself.', 'Receipt not sent');
            }
        });
    }

    /** POST orders/delete  (ids[]) - delete one or more orders. The customer is not messaged. */
    public function delete(): never
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $ids    = array_slice((array) ($_POST['ids'] ?? []), 0, 200);

        try {
            // Unpaid invoices of these orders are removed; paid ones are kept (accounting).
            $orders = new Order();
            $nos    = [];
            foreach ($ids as $id) {
                $order = $orders->find($userId, (int) $id);
                if ($order !== null) {
                    $nos[] = (string) $order['order_no'];
                }
            }
            (new \Src\Services\OrderInvoiceService())->removeFor($userId, $nos);

            $deleted = $orders->deleteMany($userId, $ids);
            $files   = new PrivateFiles('receipts');
            foreach ($deleted as $row) {
                $files->delete($userId, $row['receipt_path'] ?? null);   // the customer's bank reference
            }
            \Src\Support\FlowLog::write('order.deleted', ['orders' => array_column($deleted, 'order_no')]);

            $n = count($deleted);
            if ($n === 0) {
                flash('error', 'Tick at least one order to delete.', 'Nothing deleted');
            } else {
                flash('success', $n === 1 ? 'Order ' . $deleted[0]['order_no'] . ' was deleted.' : $n . ' orders were deleted.', 'Deleted');
            }
        } catch (\Throwable $e) {
            error_log('[orders] delete: ' . $e->getMessage());
            flash('error', 'The orders could not be deleted. Please try again.', 'Not deleted');
        }

        redirect($this->backUrl());
    }

    /** POST orders/send_invoice - the order's invoice PDF to the customer on Telegram. */
    public function sendInvoice(): void
    {
        $this->change(function (int $userId, array $order): void {
            $invoice = (new OrderService())->sendInvoice($userId, $order);
            flash('success', 'Invoice ' . $invoice . ' (PDF) was sent to the customer on Telegram.', 'Invoice sent');
        });
    }

    /** POST orders/ship */
    public function ship(): void
    {
        $this->change(function (int $userId, array $order): void {
            $result = (new OrderService())->ship($userId, $order);
            flash('success', $result['notified']
                ? 'The customer was told on Telegram that the order has been shipped.'
                : 'Marked as shipped. The customer could not be messaged on Telegram.',
                'Order ' . $order['order_no'] . ' shipped');
        });
    }

    /** POST orders/reject */
    public function reject(): void
    {
        $this->change(function (int $userId, array $order): void {
            $result = (new OrderService())->reject($userId, $order, (string) ($_POST['reason'] ?? ''));
            flash('info', $result['notified']
                ? 'The customer was asked on Telegram to send a new receipt.'
                : 'Marked as rejected. The customer could not be messaged on Telegram.',
                'Payment for ' . $order['order_no'] . ' rejected');
        });
    }

    /** POST orders/cancel */
    public function cancel(): void
    {
        $this->change(function (int $userId, array $order): void {
            (new OrderService())->cancel($userId, $order);
            flash('info', 'The customer was told the order is cancelled.', 'Order ' . $order['order_no'] . ' cancelled');
        });
    }

    /* ---------------------------------------------------------- private ---- */

    private function change(callable $action): never
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $order  = (new Order())->find($userId, (int) ($_POST['id'] ?? 0));

        if ($order === null) {
            flash('error', 'That order was not found.', 'Not changed');
            redirect($this->backUrl());
        }

        try {
            $action($userId, $order);
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage(), 'Not changed');
        }

        redirect($this->backUrl());
    }

    /** Back to the same filtered list (only our own orders page). */
    private function backUrl(): string
    {
        $back = (string) ($_POST['return'] ?? '');
        return str_starts_with($back, APP_URL . '/') ? $back : url('orders');
    }
}
