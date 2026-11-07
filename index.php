<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['shutdown_error' => $error], JSON_PRETTY_PRINT);
    }
});

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''
);

// 1. Jika pengunjung membuka root domain, arahkan langsung ke halaman login
if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    header('Location: /login/login.html', true, 302);
    exit;
}

// 1.5 Cek versi PHP server hosting
if (isset($_GET['check_php'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'php_version' => PHP_VERSION,
        'alt_php' => glob('/opt/alt/php*'),
        'ea_php' => glob('/opt/cpanel/ea-php*')
    ], JSON_PRETTY_PRINT);
    exit;
}

// 2. Teruskan semua request lainnya ke Laravel Front Controller di public/index.php
try {
    require_once __DIR__ . '/public/index.php';
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ], JSON_PRETTY_PRINT);
    exit;
}
