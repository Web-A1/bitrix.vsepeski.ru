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

// Собираем все доступные источники данных (JSON + form-data + query string).
$payload = [];
if (is_array($decoded)) {
    $payload = $decoded;
}

foreach ($_REQUEST as $key => $value) {
    if (!array_key_exists($key, $payload)) {
        $payload[$key] = $value;
    }
}

$auth = [];

if (isset($payload['auth']) && is_array($payload['auth'])) {
    $auth = $payload['auth'];
} else {
    // Bitrix может передавать токены верхним регистром или без вложения "auth".
    $auth = [
        'access_token' => $payload['access_token'] ?? $payload['AUTH_ID'] ?? $payload['AUTH'] ?? null,
        'refresh_token' => $payload['refresh_token'] ?? $payload['REFRESH_ID'] ?? null,
        'expires_in' => $payload['expires_in'] ?? $payload['expires'] ?? null,
        'scope' => $payload['scope'] ?? $payload['SCOPE'] ?? null,
        'domain' => $payload['domain'] ?? $payload['DOMAIN'] ?? null,
        'client_endpoint' => $payload['client_endpoint'] ?? $payload['CLIENT_ENDPOINT'] ?? null,
        'server_endpoint' => $payload['server_endpoint'] ?? $payload['SERVER_ENDPOINT'] ?? null,
        'member_id' => $payload['member_id'] ?? $payload['MEMBER_ID'] ?? null,
        'application_token' => $payload['application_token'] ?? $payload['APPLICATION_TOKEN'] ?? null,
        'status' => $payload['status'] ?? $payload['STATUS'] ?? null,
    ];
}

if (empty($auth['access_token']) || empty($auth['refresh_token'])) {
    // Нет авторизационных данных — вероятно, страница открыта пользователем вручную.
    echo json_encode([
        'result' => true,
        'message' => 'Install endpoint ready',
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

$domain = $auth['domain'] ?? $payload['DOMAIN'] ?? null;
$bindings = [];

if (is_string($domain) && $domain !== '') {
    $primaryHandler = 'https://bitrix.vsepeski.ru/hauls/index.html?v=20241101';

    $bindings['CRM_DEAL_DETAIL_TAB'] = bindPlacement(
        $domain,
        $auth['access_token'],
        'CRM_DEAL_DETAIL_TAB',
        $primaryHandler,
        ['TITLE' => 'Рейсы']
    );

    $bindings['CRM_DEAL_LIST_MENU'] = bindPlacement(
        $domain,
        $auth['access_token'],
        'CRM_DEAL_LIST_MENU',
        $primaryHandler,
        ['TITLE' => 'Рейсы']
    );
}

echo json_encode(['result' => true, 'bindings' => $bindings], JSON_UNESCAPED_UNICODE);

/**
 * Выполняет REST-запрос placement.bind от имени установленного приложения.
 *
 * @return array{result:mixed}|array{error:string,raw?:string,url?:string}
 */
function bindPlacement(string $domain, string $token, string $placement, string $handler, array $extra = []): array
{
    $query = http_build_query(array_merge([
        'auth' => $token,
        'PLACEMENT' => $placement,
        'HANDLER' => $handler,
    ], $extra));

    $url = sprintf('https://%s/rest/placement.bind.json?%s', $domain, $query);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);

    if ($raw === false) {
        return ['error' => 'request_failed', 'url' => $url];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['error' => 'invalid_response', 'raw' => $raw];
}
