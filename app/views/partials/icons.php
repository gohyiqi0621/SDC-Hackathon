<?php
/**
 * partials/icons.php — inline SVG sprite + icon() helper.
 * Include ONCE per page, right after <body>.
 * Use anywhere:   <?= icon('home') ?>   <?= icon('plus', 'icon--sm') ?>
 *
 * Copied exactly from the v2 design system (sdc-frontend-v2/php/partials/icons.php).
 */

// v1 icon names still resolve so no call site is left rendering a blank square.
$GLOBALS['__sdc_icon_alias'] = [
    'dashboard'   => 'home',
    'ai'          => 'sparkle',
    'close'       => 'x',
    'chevron'     => 'chevron-down',
    'chevron-l'   => 'chevron-left',
    'chevron-r'   => 'chevron-right',
];

$GLOBALS['__sdc_icons'] = [
    'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-5h4v5h4a1 1 0 0 0 1-1v-9"/>',
    'items' => '<rect x="4" y="4" width="16" height="16" rx="4"/><path d="M8 9h8M8 13h8M8 17h5"/>',
    'sparkle' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-6 8-6s8 2 8 6"/>',
    'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16 4.7a3.5 3.5 0 0 1 0 6.6M18.5 14.8c2 .7 3 2.2 3 5.2"/>',
    'shield' => '<path d="M12 3l8 3v6c0 4.5-3.2 7.8-8 9-4.8-1.2-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
    'key' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3M14 9l2 2"/>',
    'lock' => '<rect x="5" y="11" width="14" height="9" rx="3"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'bell' => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 21h4"/>',
    'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>',
    'logout' => '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4"/><path d="M16 8l4 4-4 4M20 12H9"/>',
    'send' => '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
    'paperclip' => '<path d="M20 11.5l-8 8a5 5 0 0 1-7-7l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7L9.5 17.2a1.7 1.7 0 0 1-2.4-2.4L15 7"/>',
    'camera' => '<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/>',
    'check' => '<path d="M20 6L9 17l-5-5"/>',
    'x' => '<path d="M18 6L6 18M6 6l12 12"/>',
    'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
    'chevron-left' => '<path d="M15 6l-6 6 6 6"/>',
    'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
    'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'edit' => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/>',
    'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
    'eye-off' => '<path d="M10.6 5.1A9.9 9.9 0 0 1 12 5c6.5 0 10 7 10 7a17.8 17.8 0 0 1-3.2 4.2M6.3 6.4A17.6 17.6 0 0 0 2 12s3.5 7 10 7a9.9 9.9 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M3 3l18 18"/>',
    'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.1"/>',
    'refresh' => '<path d="M20 11a8 8 0 0 0-14-4M4 5v4h4M4 13a8 8 0 0 0 14 4M20 19v-4h-4"/>',
    'calendar' => '<rect x="4" y="5" width="16" height="15" rx="3"/><path d="M4 10h16M8 3v4M16 3v4"/>',
    'alert' => '<path d="M12 3 2.5 20h19z"/><path d="M12 10v4M12 17v.1"/>',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M4 8l8 5.5L20 8"/>',
];

if (!function_exists('icon')) {
    /**
     * Inline SVG icon from the sprite. Second argument is a size class, not a
     * pixel size (the CSS owns the geometry): icon('plus', 'icon--sm').
     * A legacy integer is tolerated and mapped to the nearest size class.
     */
    function icon(string $name, $class = ''): string
    {
        if (is_int($class)) {
            $class = $class <= 16 ? 'icon--sm' : '';
        }
        $name = $GLOBALS['__sdc_icon_alias'][$name] ?? $name;
        $cls  = trim('icon ' . (string) $class);

        return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true" focusable="false">'
             . '<use href="#i-' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"/></svg>';
    }
}
?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
<?php foreach ($GLOBALS['__sdc_icons'] as $__n => $__p): ?>
<symbol id="i-<?= $__n ?>" viewBox="0 0 24 24"><?= $__p ?></symbol>
<?php endforeach; ?>
<symbol id="brand-mark" viewBox="0 0 32 32"><defs><linearGradient id="bm-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#34d27b"/><stop offset="1" stop-color="#0f7f47"/></linearGradient></defs><rect width="32" height="32" rx="9" fill="url(#bm-g)"/><path d="M9 21c0-6 4-10 14-10 0 8-4 12-10 12-1.5 0-2.7-.4-3.6-1.2" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 23c2-4 5-7 9-9" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></symbol>
</svg>
