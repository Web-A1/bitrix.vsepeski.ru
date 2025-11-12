<?php

declare(strict_types=1);

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Http\Kernel;
use B24\Center\Infrastructure\Http\Response;
use Throwable;

require dirname(__DIR__) . '/vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => $isSecure ? 'None' : 'Lax',
    ]);
    session_start();
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$kernel = $app->get(Kernel::class);

try {
    $response = $kernel->handle($_SERVER);
} catch (Throwable $exception) {
    $payload = [
        'status' => 'error',
        'message' => $exception->getMessage(),
    ];

    $response = Response::json($payload, 500);
}

$response->send();
