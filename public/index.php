<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$asset = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$assets = ['/assets/app.css' => 'text/css; charset=UTF-8', '/assets/app.js' => 'text/javascript; charset=UTF-8'];
if (isset($assets[$asset])) {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    header('Content-Type: ' . $assets[$asset]);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile(__DIR__ . $asset);
    }
    exit;
}
header('Content-Type: text/html; charset=UTF-8');
require dirname(__DIR__) . '/app/Web/helpers.php';
\App\Security\Session::start();
(new \App\Web\Controller())->run();
