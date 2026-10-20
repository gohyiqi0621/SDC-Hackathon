<?php
/**
 * Product.php - every query against products.
 *
 * Every method takes the owner's user id and filters on it, so one user can
 * never see or change another user's products. Search, filter and sort
 * values are bound or picked from a fixed list - never pasted into SQL.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class Product
{
    public const STATUSES   = ['active', 'inactive'];
    public const CURRENCIES = ['MYR', 'SGD', 'USD', 'EUR', 'GBP', 'IDR', 'THB', 'CNY'];

    /** Sort keys the page offers, mapped to safe ORDER BY clauses. */
    public const SORTS = [
        'newest'     => ['Newest first',       'p.created_at DESC, p.id DESC'],
        'oldest'     => ['Oldest first',       'p.created_at ASC, p.id ASC'],
        'name_asc'   => ['Name (A-Z)',         'LOWER(p.name) ASC, p.id ASC'],
        'name_desc'  => ['Name (Z-A)',         'LOWER(p.name) DESC, p.id DESC'],
        'price_asc'  => ['Price (low-high)',   'p.price ASC, p.id ASC'],
        'price_desc' => ['Price (high-low)',   'p.price DESC, p.id DESC'],
    ];

    private const COLUMNS = 'p.id, p.category_id, p.name, p.sku, p.description, p.characteristics,
                             p.image_url, p.price, p.promotion_price, p.currency, p.status,
                             p.created_at, p.updated_at, c.name AS category_name';

    /**
     * One page of a user's products.
     *
     * @param array{q?:string, category?:int, status?:string, sort?:string} $filters
     * @return array{items: array<int, array>, total: int}
     */
    public function search(int $userId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($userId, $filters);

        $count = Database::connection()->prepare(
            'SELECT COUNT(*) FROM products p WHERE ' . $where
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $order = self::SORTS[$filters['sort'] ?? 'newest'][1] ?? self::SORTS['newest'][1];

        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM products p
               JOIN product_categories c ON c.id = p.category_id AND c.user_id = p.user_id
              WHERE ' . $where . '
              ORDER BY ' . $order . '
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Total products the user owns, ignoring filters. */
    public function countForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM products WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function find(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM products p
               JOIN product_categories c ON c.id = p.category_id AND c.user_id = p.user_id
              WHERE p.user_id = ? AND p.id = ?
              LIMIT 1'
        );
        $stmt->execute([$userId, $id]);

        return $stmt->fetch() ?: null;
    }

    public function skuExists(int $userId, string $sku, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT 1 FROM products WHERE user_id = ? AND sku = ?';
        $params = [$userId, $sku];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data validated by ProductService */
    public function create(int $userId, array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO products
                (user_id, category_id, name, sku, description, characteristics, image_url,
                 price, promotion_price, currency, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([
            $userId,
            $data['category_id'],
            $data['name'],
            $data['sku'],
            $data['description'],
            $data['characteristics'],
            $data['image_url'],
            $data['price'],
            $data['promotion_price'],
            $data['currency'],
            $data['status'],
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data validated by ProductService */
    public function update(int $userId, int $id, array $data): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE products
                SET category_id = ?, name = ?, sku = ?, description = ?, characteristics = ?,
                    image_url = ?, price = ?, promotion_price = ?, currency = ?, status = ?,
                    updated_at = CURRENT_TIMESTAMP
              WHERE user_id = ? AND id = ?'
        );
        $stmt->execute([
            $data['category_id'],
            $data['name'],
            $data['sku'],
            $data['description'],
            $data['characteristics'],
            $data['image_url'],
            $data['price'],
            $data['promotion_price'],
            $data['currency'],
            $data['status'],
            $userId,
            $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM products WHERE user_id = ? AND id = ?');
        $stmt->execute([$userId, $id]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function where(int $userId, array $filters): array
    {
        $where  = ['p.user_id = ?'];
        $params = [$userId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // Escape LIKE wildcards so "50%" searches for the text "50%".
            $like     = '%' . addcslashes(mb_strtolower($q), '%_\\') . '%';
            $where[]  = '(LOWER(p.name) LIKE ? OR LOWER(COALESCE(p.sku, \'\')) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($filters['category'])) {
            $where[]  = 'p.category_id = ?';
            $params[] = (int) $filters['category'];
        }

        if (in_array($filters['status'] ?? '', self::STATUSES, true)) {
            $where[]  = 'p.status = ?';
            $params[] = $filters['status'];
        }

        return [implode(' AND ', $where), $params];
    }
}
