<?php

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''
);

// 1. Jika pengunjung membuka root domain, arahkan langsung ke halaman login
if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    header('Location: /login/login.html', true, 302);
    exit;
}

// 2. Teruskan semua request lainnya (termasuk /api/...) ke Laravel Front Controller
require_once __DIR__ . '/public/index.php';
