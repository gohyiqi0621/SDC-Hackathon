<?php
/**
 * layouts/footer.php - dispatches to the v2 shell footer.
 * Pass the same ['bare' => ...] flag you gave header.php.
 * Optional: ['scripts' => ['https://cdn.../chart.js', asset('js/dashboard.js')]]
 */
$bare    = $bare ?? false;
$scripts = $scripts ?? [];

require __DIR__ . '/' . ($bare ? 'auth_footer' : 'app_footer') . '.php';
