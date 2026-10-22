<?php
/**
 * PrivateFiles.php - private uploads (invoice files, claim receipts, order payment receipts).
 *
 * Files are stored OUTSIDE public/, under storage/uploads/<folder>/<user id>/
 * with a random name, so a URL can never reach them directly. The app sends
 * them back only after checking the row belongs to the signed-in user.
 * Accepted: PDF, JPG, PNG, WebP - checked from the file's bytes, not its name.
 */
declare(strict_types=1);

namespace Src\Services;

final class PrivateFiles
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const TYPES    = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];

    /** @param string $folder one folder per feature, e.g. "invoices" or "claims" */
    public function __construct(private string $folder)
    {
    }

    /** True when the form actually carried a file. */
    public function hasFile(?array $file): bool
    {
        return $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Check an upload without saving it.
     *
     * @return string|null an error message, or null when there is no file or it is fine
     */
    public function error(?array $file): ?string
    {
        if (!$this->hasFile($file)) {
            return null;
        }

        $error = (int) ($file['error'] ?? 0);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return 'The file must be ' . (int) (self::MAX_BYTES / 1024 / 1024) . ' MB or smaller.';
        }

        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return 'The file could not be uploaded. Please try again.';
        }

        if ($this->mimeOf($file) === null) {
            return 'Upload a PDF, JPG, PNG or WebP file.';
        }

        return null;
    }

    /**
     * Save a checked upload.
     *
     * @return array{file_path: string, file_name: string, file_mime: string, file_size: int}|null
     */
    public function store(int $userId, array $file): ?array
    {
        $mime = $this->mimeOf($file);
        if ($mime === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $relativeDir = $this->folder . '/' . $userId;
        $absoluteDir = $this->root() . '/' . $relativeDir;

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            error_log('[' . $this->folder . '] cannot create ' . $absoluteDir);
            return null;
        }

        $relative = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];

        if (!move_uploaded_file((string) $file['tmp_name'], $this->root() . '/' . $relative)) {
            error_log('[' . $this->folder . '] could not move upload to ' . $relative);
            return null;
        }

        return [
            'file_path' => $relative,
            'file_name' => $this->cleanName((string) ($file['name'] ?? ''), self::TYPES[$mime]),
            'file_mime' => $mime,
            'file_size' => (int) ($file['size'] ?? 0),
        ];
    }

    /**
     * Save file bytes that did not come from a browser upload (e.g. a payment
     * receipt downloaded from Telegram). Same type checks as store().
     *
     * @return array{file_path: string, file_name: string, file_mime: string, file_size: int}|null
     *         null when the bytes are not an allowed PDF/JPG/PNG/WebP or too large
     */
    public function storeBytes(int $userId, string $bytes, string $name): ?array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!isset(self::TYPES[$mime]) || ($mime !== 'application/pdf' && @getimagesizefromstring($bytes) === false)) {
            return null;
        }

        $relativeDir = $this->folder . '/' . $userId;
        $absoluteDir = $this->root() . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            error_log('[' . $this->folder . '] cannot create ' . $absoluteDir);
            return null;
        }

        $relative = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (file_put_contents($this->root() . '/' . $relative, $bytes) === false) {
            error_log('[' . $this->folder . '] could not write ' . $relative);
            return null;
        }

        return [
            'file_path' => $relative,
            'file_name' => $this->cleanName($name, self::TYPES[$mime]),
            'file_mime' => $mime,
            'file_size' => strlen($bytes),
        ];
    }

    /** Absolute path of a file stored for this user in this folder, or null. */
    public function pathFor(int $userId, ?string $relative): ?string
    {
        $relative = (string) $relative;
        $prefix   = $this->folder . '/' . $userId . '/';

        if ($relative === '' || !str_starts_with($relative, $prefix) || str_contains($relative, '..')) {
            return null;
        }

        $path = $this->root() . '/' . $relative;

        return is_file($path) ? $path : null;
    }

    /** Remove a file stored for this user. Anything else is left alone. */
    public function delete(int $userId, ?string $relative): void
    {
        $path = $this->pathFor($userId, $relative);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * Send a stored file to the browser and stop. Inline by default (opens in a
     * tab); $download asks the browser to save it instead.
     */
    public function send(string $path, string $mime, string $name, bool $download = false): never
    {
        $name = $name !== '' ? $name : 'file';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
            . '; filename="' . addcslashes($name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($path);
        exit;
    }

    /* ----------------------------------------------------------- helpers --- */

    private function root(): string
    {
        return STORAGE_ROOT . '/uploads';
    }

    /** The real type of an upload (from its bytes, not its name), if allowed. */
    private function mimeOf(array $file): ?string
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return null;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime])) {
            return null;
        }

        // An "image" that is not really one is refused.
        if ($mime !== 'application/pdf' && @getimagesize($tmp) === false) {
            return null;
        }

        return $mime;
    }

    /** A display name safe to store and to send back in a header. */
    private function cleanName(string $name, string $ext): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^\w\-. ]+/u', '_', $base);
        $base = trim(mb_substr($base, 0, 150), ' ._') ?: 'file';

        return $base . '.' . $ext;
    }
}
