<?php
/**
 * UserSettingsRow.php - shared queries for the one-row-per-user settings tables
 * (company_profiles, ai_assistant_settings, telegram_integrations,
 * google_calendar_integrations).
 *
 * Every query filters on the owner's user_id, which controllers take from the
 * session - never from the request - so one user can never read or change
 * another user's settings.
 *
 * save() is a manual upsert (find the user's row, then UPDATE or INSERT) so it
 * also works on tables a teammate created without a UNIQUE (user_id).
 */
declare(strict_types=1);

namespace Src\Models;

use PDO;
use Src\Support\Database;

abstract class UserSettingsRow
{
    /** Table name (a constant in each subclass, never user input). */
    abstract protected function table(): string;

    /** @return string[] columns save() may write (user_id/id/timestamps excluded) */
    abstract protected function writable(): array;

    public function find(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE user_id = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$userId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Insert or update the user's row with the given columns. Unknown keys are
     * ignored. Returns the saved row.
     *
     * @param array<string, mixed> $values
     */
    public function save(int $userId, array $values): array
    {
        $values = array_intersect_key($values, array_flip($this->writable()));
        $table  = $this->table();

        // Non-emulated prepares send every value as text; PostgreSQL wants
        // 't'/'f' for booleans (an empty string is not a valid boolean).
        foreach ($values as $column => $value) {
            if (is_bool($value)) {
                $values[$column] = $value ? 't' : 'f';
            }
        }

        Database::transaction(static function (PDO $pdo) use ($table, $userId, $values): void {
            $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE user_id = ? ORDER BY id LIMIT 1 FOR UPDATE');
            $stmt->execute([$userId]);
            $id = $stmt->fetchColumn();

            $columns = array_keys($values);

            if ($id !== false) {
                $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $columns));
                $set = $set === '' ? 'updated_at = CURRENT_TIMESTAMP' : $set . ', updated_at = CURRENT_TIMESTAMP';
                $pdo->prepare('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ? AND user_id = ?')
                    ->execute(array_merge(array_values($values), [$id, $userId]));
                return;
            }

            $names        = array_merge(['user_id'], $columns);
            $placeholders = implode(', ', array_fill(0, count($names), '?'));
            $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (' . $placeholders . ')')
                ->execute(array_merge([$userId], array_values($values)));
        });

        return $this->find($userId) ?? [];
    }
}
