<?php
/**
 * layouts/footer.php - closes whichever shell header.php opened.
 *
 * Optional: 'scripts' => ['assets/js/x.js'] (paths inside public/).
 */
$bare    = $bare ?? false;
$scripts = $scripts ?? [];

require __DIR__ . '/' . ($bare ? 'auth_footer' : 'app_footer') . '.php';
