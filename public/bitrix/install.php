<?php

declare(strict_types=1);

/**
 * Bitrix24 application installation handler.
 *
 * Bitrix отправляет данные об авторизации (access_token / refresh_token и т.д.)
 * во время установки или переустановки локального приложения.
 * Скрипт сохраняет полученные значения в storage/bitrix/oauth.json, чтобы
 * дальнейшие REST-вызовы могли выполняться от имени приложения.
 */

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input') ?: '';
$decoded = json_decode($rawBody, true);

if (is_array($decoded)) {
    $payload = $decoded;
} else {
    // Fallback для form-data — Bitrix может прислать auth в $_REQUEST.
    $payload = $_REQUEST;
}

$auth = $payload['auth'] ?? [];

if (!is_array($auth) || empty($auth['access_token']) || empty($auth['refresh_token'])) {
    http_response_code(400);
    echo json_encode([
        'result' => false,
        'error' => 'auth payload is missing or incomplete',
    ], JSON_UNESCAPED_UNICODE);
    return;
}

$expiresIn = isset($auth['expires_in']) ? (int) $auth['expires_in'] : 3600;
$expiresAt = (new DateTimeImmutable())->modify(sprintf('+%d seconds', $expiresIn));

$storedData = [
    'received_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'access_token' => $auth['access_token'],
    'refresh_token' => $auth['refresh_token'],
    'expires_in' => $expiresIn,
    'expires_at' => $expiresAt->format(DATE_ATOM),
    'scope' => $auth['scope'] ?? null,
    'domain' => $auth['domain'] ?? null,
    'client_endpoint' => $auth['client_endpoint'] ?? null,
    'server_endpoint' => $auth['server_endpoint'] ?? null,
    'member_id' => $auth['member_id'] ?? null,
    'application_token' => $auth['application_token'] ?? null,
    'status' => $auth['status'] ?? null,
    'raw' => $payload,
];

$projectRoot = dirname(__DIR__, 2);
$storageDir = $projectRoot . '/storage/bitrix';

if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    http_response_code(500);
    echo json_encode([
        'result' => false,
        'error' => 'failed to create storage directory',
    ], JSON_UNESCAPED_UNICODE);
    return;
}

$filePath = $storageDir . '/oauth.json';
$json = json_encode($storedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (file_put_contents($filePath, $json) === false) {
    http_response_code(500);
    echo json_encode([
        'result' => false,
        'error' => 'failed to persist auth payload',
    ], JSON_UNESCAPED_UNICODE);
    return;
}

echo json_encode(['result' => true], JSON_UNESCAPED_UNICODE);
