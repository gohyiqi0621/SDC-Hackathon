<?php
/**
 * ApiKey.php - the admin "Key" page. Secrets are stored encrypted and are
 * never read back out; only the label, mask and dates are ever queried.
 */
declare(strict_types=1);

class ApiKey
{
    /**
     * Store a new key. Returns [id, ''] on success or [0, errorMessage].
     * The plaintext is encrypted here and never written down.
     *
     * @return array{0:int,1:string}
     */
    public function create(string $label, string $secret, ?int $createdBy): array
    {
        if (!crypto_available()) {
            return [0, 'Server encryption is not configured (APP_KEY missing).'];
        }

        $sealed = encrypt_secret($secret);
        if ($sealed === null) {
            return [0, 'The key could not be encrypted.'];
        }

        $stmt = db()->prepare(
            'INSERT INTO api_keys (label, secret_encrypted, last4, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $label,
            $sealed,
            substr($secret, -4),
            $createdBy,
        ]);

        return [(int) db()->lastInsertId(), ''];
    }

    /** Newest first. No secret column is selected - it cannot leak from here. */
    public function all(): array
    {
        return db()->query(
            'SELECT k.id, k.label, k.last4, k.created_at, u.name AS creator_name
               FROM api_keys k
               LEFT JOIN users u ON u.id = k.created_by
              ORDER BY k.created_at DESC, k.id DESC'
        )->fetchAll();
    }

    public function count(): int
    {
        return (int) db()->query('SELECT COUNT(*) FROM api_keys')->fetchColumn();
    }

    public function delete(int $id): bool
    {
        $stmt = db()->prepare('DELETE FROM api_keys WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
