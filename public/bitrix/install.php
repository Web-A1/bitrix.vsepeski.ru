<?php

declare(strict_types=1);

use B24\Center\Infrastructure\Bitrix\Install\InstallRequestHandler;
use B24\Center\Infrastructure\Bitrix\Install\SyncPlacementBindingDispatcher;
use B24\Center\Infrastructure\Logging\InstallLoggerFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__, 2);
$logger = InstallLoggerFactory::create($projectRoot);

set_exception_handler(static function (\Throwable $exception) use ($logger): void {
    $logger->error('install.php uncaught exception', ['exception' => $exception]);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($logger): bool {
    $logger->error('install.php php error', [
        'severity' => $severity,
        'message' => $message,
        'file' => $file,
        'line' => $line,
    ]);

    return false;
});

register_shutdown_function(static function () use ($logger): void {
    $error = error_get_last();

    if ($error !== null) {
        $logger->error('install.php shutdown error', $error);
    }
});

$rawBody = file_get_contents('php://input') ?: '';
$payload = collectPayload($rawBody, $_REQUEST);

$logger->info('install.php request received', [
    'has_raw_body' => $rawBody !== '',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'query' => $_GET,
]);

if ($rawBody !== '') {
    $logger->debug('install.php raw payload preview', ['raw' => mb_substr($rawBody, 0, 500)]);
}

$bindingDispatcher = new SyncPlacementBindingDispatcher($logger);
$handler = new InstallRequestHandler($projectRoot, $logger, $bindingDispatcher);
$result = $handler->handle(
    $payload,
    $_GET,
    $_POST,
    $_REQUEST,
    $_SERVER['REQUEST_METHOD'] ?? 'GET'
);

$result->send();

/**
 * @param array<string,mixed> $request
 *
 * @return array<string,mixed>
 */
function collectPayload(string $rawBody, array $request): array
{
    $payload = [];
    $decoded = json_decode($rawBody, true);
    $formData = [];

    if ($rawBody !== '') {
        parse_str($rawBody, $formData);
    }

    if (is_array($formData)) {
        $payload = $formData;
    }

    if (is_array($decoded) && $decoded !== []) {
        $payload = array_merge($payload, $decoded);
    }

    foreach ($request as $key => $value) {
        if (!array_key_exists($key, $payload)) {
            $payload[$key] = $value;
        }
    }

    return $payload;
}
