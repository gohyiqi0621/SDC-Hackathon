<?php
/**
 * Order.php - orders and order_items (migration 009).
 *
 * Every query is scoped to the business (user_id), so one business can never
 * read or change another business's orders.
 */
declare(strict_types=1);

namespace Src\Models;

use RuntimeException;
use Src\Support\Database;

final class Order
{
    public const STATUSES = [
        'pending_payment'   => 'Awaiting payment',
        'payment_submitted' => 'Receipt to review',
        'paid'              => 'Paid',
        'payment_rejected'  => 'Payment rejected',
        'cancelled'         => 'Cancelled',
    ];

    /** Orders that still wait for a receipt from the customer. */
    public const AWAITING_RECEIPT = ['pending_payment', 'payment_rejected'];

    /**
     * Create an order with its items in one transaction.
     *
     * @param array $order  channel, customer_chat_id, customer_name, customer_username, customer_phone, delivery_note, currency
     * @param list<array{product_id:int, product_name:string, sku:?string, unit_price:string, quantity:int}> $items
     * @return array the new orders row
     */
    public function create(int $userId, array $order, array $items): array
    {
        if ($items === []) {
            throw new RuntimeException('An order needs at least one item.');
        }

        $total = '0.00';
        foreach ($items as $i => $item) {
            $line = self::lineTotal((string) $item['unit_price'], (string) $item['quantity']);
            $items[$i]['line_total'] = $line;
            $total = self::addMoney($total, $line);
        }

        $db = Database::connection();
        $db->beginTransaction();

        try {
            $row = null;
            for ($try = 0; $try < 5 && $row === null; $try++) {
                $stmt = $db->prepare(
                    'INSERT INTO orders (user_id, order_no, channel, customer_chat_id, customer_name, customer_username,
                                         customer_phone, delivery_note, currency, total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (user_id, order_no) DO NOTHING
                     RETURNING *'
                );
                $stmt->execute([
                    $userId, $this->newOrderNo(), $order['channel'], $order['customer_chat_id'],
                    $order['customer_name'] ?: null, $order['customer_username'] ?: null,
                    $order['customer_phone'] ?: null, $order['delivery_note'] ?: null,
                    $order['currency'], $total,
                ]);
                $row = $stmt->fetch() ?: null;
            }
            if ($row === null) {
                throw new RuntimeException('Could not create an order number. Please try again.');
            }

            $itemStmt = $db->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, sku, unit_price, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                $itemStmt->execute([$row['id'], $item['product_id'], $item['product_name'], $item['sku'],
                                    $item['unit_price'], $item['quantity'], $item['line_total']]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $row;
    }

    public function find(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM orders WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $id]);

        return $stmt->fetch() ?: null;
    }

    /** The newest order of this customer that still needs a receipt. */
    public function latestAwaitingReceipt(int $userId, string $channel, string $chatId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM orders
              WHERE user_id = ? AND channel = ? AND customer_chat_id = ?
                AND status IN ('pending_payment', 'payment_rejected')
              ORDER BY created_at DESC, id DESC
              LIMIT 1"
        );
        $stmt->execute([$userId, $channel, $chatId]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array> */
    public function items(int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        $stmt->execute([$orderId]);

        return $stmt->fetchAll();
    }

    /** Items for several orders at once: order id => list of items. */
    public function itemsFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt  = Database::connection()->prepare("SELECT * FROM order_items WHERE order_id IN ($marks) ORDER BY id");
        $stmt->execute(array_values(array_map('intval', $orderIds)));

        $out = [];
        foreach ($stmt->fetchAll() as $item) {
            $out[(int) $item['order_id']][] = $item;
        }
        return $out;
    }

    /** @return list<array> newest first */
    public function search(int $userId, string $status, string $q, int $limit, int $offset): array
    {
        [$where, $params] = $this->filters($userId, $status, $q);
        $stmt = Database::connection()->prepare(
            "SELECT * FROM orders WHERE $where
              ORDER BY (status = 'payment_submitted') DESC, created_at DESC, id DESC
              LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function count(int $userId, string $status, string $q): int
    {
        [$where, $params] = $this->filters($userId, $status, $q);
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM orders WHERE $where");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** status => number of orders */
    public function countsByStatus(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT status, COUNT(*) AS n FROM orders WHERE user_id = ? GROUP BY status');
        $stmt->execute([$userId]);

        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /** Update some columns of one order (updated_at is set too). */
    public function update(int $userId, int $id, array $values): ?array
    {
        $allowed = ['status', 'receipt_path', 'receipt_mime', 'receipt_name', 'receipt_uploaded_at',
                    'reviewed_at', 'review_note'];
        $values  = array_intersect_key($values, array_flip($allowed));
        if ($values === []) {
            return $this->find($userId, $id);
        }

        $sets = [];
        foreach (array_keys($values) as $column) {
            $sets[] = $column . ' = ?';
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';

        $stmt = Database::connection()->prepare(
            'UPDATE orders SET ' . implode(', ', $sets) . ' WHERE user_id = ? AND id = ? RETURNING *'
        );
        $stmt->execute([...array_values($values), $userId, $id]);

        return $stmt->fetch() ?: null;
    }

    /* ---------------------------------------------------------- private ---- */

    /** Money maths in whole cents, so 0.1 + 0.2 never becomes 0.30000001. */
    private static function lineTotal(string $amount, string $qty): string
    {
        $cents = (int) round(((float) $amount) * 100) * (int) $qty;
        return number_format($cents / 100, 2, '.', '');
    }

    private static function addMoney(string $a, string $b): string
    {
        $cents = (int) round(((float) $a) * 100) + (int) round(((float) $b) * 100);
        return number_format($cents / 100, 2, '.', '');
    }

    /** e.g. ORD-251021-7K3Q: date + 4 random characters (no 0/O/1/I). */
    private function newOrderNo(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $suffix   = '';
        for ($i = 0; $i < 4; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'ORD-' . date('ymd') . '-' . $suffix;
    }

    /** @return array{0: string, 1: array} */
    private function filters(int $userId, string $status, string $q): array
    {
        $where  = 'user_id = ?';
        $params = [$userId];

        if (isset(self::STATUSES[$status])) {
            $where   .= ' AND status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $like     = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
            $where   .= ' AND (LOWER(order_no) LIKE ? OR LOWER(COALESCE(customer_name, \'\')) LIKE ?'
                      . ' OR LOWER(COALESCE(customer_username, \'\')) LIKE ? OR customer_chat_id LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        return [$where, $params];
    }
}
