<?php
/**
 * Temporary PHP diagnostic. Open http://localhost/SDC-Hackathon/public/diag-php.php
 * Only answers direct localhost requests (not through ngrok). Delete it when done.
 */
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true) || isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/plain; charset=utf-8');

echo 'PHP version:        ' . PHP_VERSION . "\n";
echo 'PHP binary dir:     ' . PHP_BINARY . "\n";
echo 'Loaded php.ini:     ' . (php_ini_loaded_file() ?: '(none)') . "\n";
echo 'extension_dir:      ' . ini_get('extension_dir') . "\n";
echo 'curl loaded:        ' . (extension_loaded('curl') ? 'YES' : 'NO') . "\n";
echo 'openssl loaded:     ' . (extension_loaded('openssl') ? 'YES' : 'NO') . "\n";
echo 'pdo_pgsql loaded:   ' . (extension_loaded('pdo_pgsql') ? 'YES' : 'NO') . "\n";

$ext = rtrim((string) ini_get('extension_dir'), '\\/');
echo 'php_curl.dll exists: ' . (is_file($ext . DIRECTORY_SEPARATOR . 'php_curl.dll') ? 'YES' : 'NO') . "\n";

$phpDir = dirname($ext);
echo "\nDLLs in $phpDir that curl needs:\n";
foreach (['libssh2.dll', 'nghttp2.dll', 'libcrypto-3-x64.dll', 'libssl-3-x64.dll', 'libcrypto-1_1-x64.dll', 'libssl-1_1-x64.dll'] as $dll) {
    echo '  ' . str_pad($dll, 24) . (is_file($phpDir . DIRECTORY_SEPARATOR . $dll) ? 'found' : '-') . "\n";
}

echo "\nPATH contains PHP dir: " . (stripos((string) getenv('PATH'), $phpDir) !== false ? 'YES' : 'NO') . "\n";
if (extension_loaded('curl')) {
    $v = curl_version();
    echo 'curl version: ' . $v['version'] . ' / ' . $v['ssl_version'] . "\n";
}
