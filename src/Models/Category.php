<?php
/**
 * Category.php - every query against product_categories.
 *
 * Every method takes the owner's user id and filters on it, so one user can
 * never read, change or delete another user's categories.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class Category
{
    /** All of a user's categories, A-Z, with how many products use each. */
    public function allForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.id, c.name, c.description, c.created_at, c.updated_at,
                    COUNT(p.id) AS product_count
               FROM product_categories c
               LEFT JOIN products p ON p.category_id = c.id AND p.user_id = c.user_id
              WHERE c.user_id = ?
              GROUP BY c.id
              ORDER BY LOWER(c.name)'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function find(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, name, description, created_at, updated_at
               FROM product_categories
              WHERE user_id = ? AND id = ?
              LIMIT 1'
        );
        $stmt->execute([$userId, $id]);

        return $stmt->fetch() ?: null;
    }

    /** Case-insensitive name check, so "Food" and "food" are not both allowed. */
    public function nameExists(int $userId, string $name, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT 1 FROM product_categories WHERE user_id = ? AND LOWER(name) = LOWER(?)';
        $params = [$userId, trim($name)];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    public function create(int $userId, string $name, ?string $description): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO product_categories (user_id, name, description)
             VALUES (?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([$userId, trim($name), $description]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $userId, int $id, string $name, ?string $description): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE product_categories
                SET name = ?, description = ?, updated_at = CURRENT_TIMESTAMP
              WHERE user_id = ? AND id = ?'
        );
        $stmt->execute([trim($name), $description, $userId, $id]);

        return $stmt->rowCount() > 0;
    }

    public function productCount(int $userId, int $id): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM products WHERE user_id = ? AND category_id = ?'
        );
        $stmt->execute([$userId, $id]);

        return (int) $stmt->fetchColumn();
    }

    /** Deletes only an empty category; the database also refuses a used one. */
    public function delete(int $userId, int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM product_categories WHERE user_id = ? AND id = ?'
        );
        $stmt->execute([$userId, $id]);

        return $stmt->rowCount() > 0;
    }
}
