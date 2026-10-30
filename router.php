<?php
declare(strict_types=1);

// Always use this router with public/ as document root. No arbitrary PHP or private paths are served.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$host = $_SERVER['HTTP_HOST'] ?? '';
if (!preg_match('/^(127\.0\.0\.1|localhost)(:[0-9]+)?$/D', $host)
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This application is available on localhost only.');
}
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
if (in_array($path, ['/style.css', '/app.js'], true) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: ' . ($path === '/style.css' ? 'text/css' : 'text/javascript') . '; charset=utf-8');
    readfile(__DIR__ . '/public' . $path);
    return;
}
if (in_array($path, ['/', '/api/generate', '/api/campaign', '/bundle', '/brief', '/asset', '/output'], true)) {
    require __DIR__ . '/public/index.php';
    return;
}
http_response_code(404);
echo 'Not found.';
