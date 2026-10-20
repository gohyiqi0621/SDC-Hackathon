<?php
/**
 * View.php - tiny PHP template renderer.
 *
 * Views are plain PHP files under src/Views/. They receive their data as local
 * variables and are expected to escape output with e().
 */
declare(strict_types=1);

namespace Src\Support;

use RuntimeException;

final class View
{
    public static function render(string $path, array $data = []): void
    {
        $file = APP_ROOT . '/src/Views/' . $path . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $path);
        }

        extract($data, EXTR_SKIP);
        require $file;
    }

    public static function exists(string $path): bool
    {
        return is_file(APP_ROOT . '/src/Views/' . $path . '.php');
    }
}
