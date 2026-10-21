<?php
/**
 * OrderController.php - AI Chatbot > Customer > Orders.
 *
 * Orders placed through the AI chatbot (Flowise tool buy_product). Staff open
 * the customer's payment receipt and approve or reject it; the customer is
 * told on Telegram either way (OrderService).
 *
 *   GET  orders               list (filter by status, search)
 *   GET  orders/receipt       the receipt file (only the owner's orders)
 *   POST orders/approve       -> paid, Telegram "Payment verified"
 *   POST orders/reject        -> payment_rejected, Telegram asks for a new receipt
 *   POST orders/cancel        -> cancelled
 */
declare(strict_types=1);

namespace Src\Controllers;

use PDOException;
use RuntimeException;
use Src\Models\Order;
use Src\Services\OrderService;
use Src\Services\PrivateFiles;

final class OrderController
{
    private const PER_PAGE = 15;

    /** GET orders */
    public function index(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $status = (string) ($_GET['status'] ?? '');
        $status = isset(Order::STATUSES[$status]) ? $status : '';
        $q      = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $page   = max(1, (int) ($_GET['p'] ?? 1));
        $model  = new Order();

        try {
            $total  = $model->count($userId, $status, $q);
            $pages  = max(1, (int) ceil($total / self::PER_PAGE));
            $page   = min($page, $pages);
            $orders = $model->search($userId, $status, $q, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
            $counts = $model->countsByStatus($userId);
            $items  = $model->itemsFor(array_map(static fn (array $o): int => (int) $o['id'], $orders));
            $missingTable = false;
        } catch (PDOException $e) {
            if (!in_array((string) $e->getCode(), ['42P01', '42703'], true)) {
                throw $e;
            }
            [$total, $pages, $orders, $items, $missingTable] = [0, 1, [], [], true];
            $counts = array_fill_keys(array_keys(Order::STATUSES), 0);
        }

        view('orders/index', [
            'user'         => $user,
            'orders'       => $orders,
            'items'        => $items,
            'counts'       => $counts,
            'status'       => $status,
            'q'            => $q,
            'total'        => $total,
            'page'         => $page,
            'pages'        => $pages,
            'perPage'      => self::PER_PAGE,
            'missingTable' => $missingTable,
        ]);
    }

    /** GET orders/receipt?id= */
    public function receipt(): void
    {
        $user  = require_login();
        $order = (new Order())->find((int) $user['id'], (int) ($_GET['id'] ?? 0));
        $files = new PrivateFiles('receipts');
        $path  = $order !== null ? $files->pathFor((int) $user['id'], $order['receipt_path']) : null;

        if ($path === null) {
            abort(404, 'This order has no payment receipt yet.');
        }

        $files->send($path, (string) $order['receipt_mime'], (string) ($order['receipt_name'] ?: 'receipt'), !empty($_GET['download']));
    }

    /** POST orders/approve */
    public function approve(): void
    {
        $this->change(function (int $userId, array $order): void {
            $result = (new OrderService())->approve($userId, $order);
            flash('success', $result['notified']
                ? 'The customer was told on Telegram: "Payment verified".'
                : 'Marked as paid. The customer could not be messaged on Telegram (check the Telegram connection).',
                'Order ' . $order['order_no'] . ' paid');
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
