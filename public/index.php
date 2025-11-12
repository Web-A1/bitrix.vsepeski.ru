<?php

declare(strict_types=1);

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Http\Kernel;
use B24\Center\Infrastructure\Http\Response;
use Throwable;

require dirname(__DIR__) . '/vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = isSecureRequest();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => $isSecure ? 'None' : 'Lax',
    ]);
    session_start();
}

function isSecureRequest(): bool
{
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['HTTP_CF_VISITOR'] ?? null;
    if (is_string($forwardedProto) && stripos($forwardedProto, 'https') !== false) {
        return true;
    }

    if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if (is_string($origin) && str_starts_with(strtolower($origin), 'https://')) {
        return true;
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? null;
    if (is_string($referer) && str_starts_with(strtolower($referer), 'https://')) {
        return true;
    }

    return false;
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
