<?php
/**
 * FileBackup.php - keep a copy of every uploaded file in PostgreSQL.
 *
 * Why: a Replit (autoscale) deployment starts from a fresh copy of the code
 * every time it is published or wakes up, so files written to its disk
 * (company logo, payment QR, product images, bank references) disappear.
 * The database stays. Every upload is therefore also saved in the
 * file_backups table, and a file that is missing on disk is written back
 * from there the first time it is needed (restore()).
 *
 * Areas:
 *   public   files under public/            (uploads/company/..., uploads/products/...)
 *   private  files under storage/uploads/   (invoices, claims, order receipts)
 *
 * A backup problem is logged and reported to the upload caller so files are
 * never reported as saved when only an instance-local copy exists. The table
 * is created by migration 023 and applied to production through Publish.
 */
declare(strict_types=1);

namespace Src\Support;

use PDO;
use Throwable;

final class FileBackup
{
    private const MAX_BYTES = 12 * 1024 * 1024;

    /** Absolute path of a file in an area. */
    public static function absolute(string $area, string $relative): string
    {
        $root = $area === 'private' ? STORAGE_ROOT . '/uploads' : PUBLIC_ROOT;
        return $root . '/' . ltrim($relative, '/');
    }

    /** Save (or replace) the copy of a file that was just written to disk. */
    public static function save(string $area, string $relative, ?string $mime = null): bool
    {
        $relative = ltrim($relative, '/');
        $path     = self::absolute($area, $relative);
        $size     = is_file($path) ? filesize($path) : false;
        if (!in_array($area, ['public', 'private'], true) || !self::safe($relative)
            || $size === false || $size > self::MAX_BYTES) {
            error_log('[file-backup] refused to save an invalid or oversized file');
            return false;
        }

        try {
            $mime = $mime ?: (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $data = file_get_contents($path);
            if ($data === false) {
                error_log('[file-backup] could not read ' . $relative);
                return false;
            }
            $stmt = Database::connection()->prepare(
                'INSERT INTO file_backups (area, path, mime, data) VALUES (?, ?, ?, ?)
                 ON CONFLICT (area, path) DO UPDATE SET mime = EXCLUDED.mime, data = EXCLUDED.data, created_at = CURRENT_TIMESTAMP'
            );
            $stmt->bindValue(1, $area);
            $stmt->bindValue(2, $relative);
            $stmt->bindValue(3, $mime);
            $stmt->bindValue(4, $data, PDO::PARAM_LOB);
            if (!$stmt->execute()) {
                error_log('[file-backup] database refused to save ' . $relative);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            error_log('[file-backup] save ' . $relative . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Make sure the file is on disk: when it is missing, write it back from
     * the database copy. Returns true when the file is there afterwards.
     */
    public static function restore(string $area, string $relative): bool
    {
        $relative = ltrim($relative, '/');
        $path     = self::absolute($area, $relative);
        if (is_file($path)) {
            return true;
        }
        if (!self::safe($relative)) {
            return false;
        }

        try {
            $stmt = Database::connection()->prepare('SELECT data FROM file_backups WHERE area = ? AND path = ?');
            $stmt->execute([$area, $relative]);
            $data = $stmt->fetchColumn();
            if ($data === false || $data === null) {
                return false;
            }
            $bytes = is_resource($data) ? (string) stream_get_contents($data) : (string) $data;

            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
            return file_put_contents($path, $bytes, LOCK_EX) !== false;
        } catch (Throwable $e) {
            error_log('[file-backup] restore ' . $relative . ': ' . $e->getMessage());
            return false;
        }
    }

    /** The stored type of a file (for serving it), or null. */
    public static function mime(string $area, string $relative): ?string
    {
        try {
            $stmt = Database::connection()->prepare('SELECT mime FROM file_backups WHERE area = ? AND path = ?');
            $stmt->execute([$area, ltrim($relative, '/')]);
            $mime = $stmt->fetchColumn();
            return is_string($mime) && $mime !== '' ? $mime : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** The file was deleted on purpose: drop its copy too. */
    public static function forget(string $area, string $relative): void
    {
        try {
            Database::connection()->prepare('DELETE FROM file_backups WHERE area = ? AND path = ?')
                ->execute([$area, ltrim($relative, '/')]);
        } catch (Throwable $e) {
            error_log('[file-backup] forget ' . $relative . ': ' . $e->getMessage());
        }
    }

    /**
     * GET files/public?path=uploads/... - a public upload (logo, payment QR,
     * product image) that is missing on disk: restore it and send it. The web
     * server sends files that are on disk itself, so this only runs once per file.
     */
    public static function servePublic(string $relative): never
    {
        $relative = ltrim($relative, '/');
        if (!preg_match('#^uploads/(company|products)/\d+/[A-Za-z0-9_-]+\.(jpe?g|png|webp|gif)$#', $relative)
            || !self::restore('public', $relative)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Not found');
        }
        $path = self::absolute('public', $relative);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!str_starts_with($mime, 'image/')) {
            http_response_code(404);
            exit('Not found');
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }

    private static function safe(string $relative): bool
    {
        return $relative !== '' && !str_contains($relative, '..') && !str_contains($relative, "\0")
            && preg_match('#^[A-Za-z0-9_./-]+$#', $relative) === 1;
    }

}
