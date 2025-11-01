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

$hasTokens = !empty($auth['access_token']) && !empty($auth['refresh_token']);
$isPlacementLaunch = isset($payload['PLACEMENT']);
$eventName = null;

if (isset($payload['event']) && is_string($payload['event'])) {
    $eventName = $payload['event'];
} elseif (isset($payload['EVENT']) && is_string($payload['EVENT'])) {
    $eventName = $payload['EVENT'];
}

$isInstallEvent = is_string($eventName) && stripos($eventName, 'ONAPPINSTALL') !== false;

if ($isPlacementLaunch && !$isInstallEvent) {
    $projectRoot = dirname(__DIR__, 2);
    $indexPath = $projectRoot . '/public/hauls/index.html';

    if (!is_file($indexPath)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'result' => false,
            'error' => 'hauls index missing',
            'path' => $indexPath,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $html = file_get_contents($indexPath);
    if ($html === false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'result' => false,
            'error' => 'failed to read hauls index',
            'path' => $indexPath,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $bootstrapData = [
        'payload' => $payload,
        'get' => $_GET,
        'post' => $_POST,
        'request' => $_REQUEST,
    ];

    $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    $bootstrapScript = '<script>window.B24_INSTALL_PAYLOAD = ' . json_encode($bootstrapData, $jsonOptions) . ';</script>';

    $moduleTag = '<script src="../assets/hauls.js" type="module"></script>';
    if (str_contains($html, $moduleTag)) {
        $html = str_replace($moduleTag, $bootstrapScript . "\n    " . $moduleTag, $html);
    } else {
        $html .= "\n" . $bootstrapScript;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    return;
}

if (!$hasTokens) {
    header('Content-Type: application/json; charset=utf-8');
    // Нет авторизационных данных — вероятно, ручной запрос или пинг.
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
    header('Content-Type: application/json; charset=utf-8');
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

    $bindings['CRM_DEAL_DETAIL_TAB'] = rebindPlacement(
        $domain,
        $auth['access_token'],
        'CRM_DEAL_DETAIL_TAB',
        $primaryHandler,
        ['TITLE' => 'Рейсы']
    );

    $bindings['CRM_DEAL_LIST_MENU'] = rebindPlacement(
        $domain,
        $auth['access_token'],
        'CRM_DEAL_LIST_MENU',
        $primaryHandler,
        ['TITLE' => 'Рейсы']
    );
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['result' => true, 'bindings' => $bindings], JSON_UNESCAPED_UNICODE);

/**
 * Снимает старый обработчик и привязывает новый для указанного placement.
 *
 * @return array<string,mixed>
 */
function rebindPlacement(string $domain, string $token, string $placement, string $handler, array $extra = []): array
{
    $result = [
        'unbind' => callBitrix($domain, 'placement.unbind.json', [
            'auth' => $token,
            'PLACEMENT' => $placement,
            'HANDLER' => $handler,
        ]),
        'bind' => callBitrix($domain, 'placement.bind.json', array_merge([
            'auth' => $token,
            'PLACEMENT' => $placement,
            'HANDLER' => $handler,
        ], $extra)),
    ];

    return $result;
}

/**
 * Вызывает REST-метод Bitrix24 и возвращает результат.
 *
 * @return array<string,mixed>
 */
function callBitrix(string $domain, string $method, array $params): array
{
    $url = sprintf('https://%s/rest/%s', $domain, $method);
    $query = http_build_query($params);
    $endpoint = $url . '?' . $query;

    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['error' => 'curl_init_failed', 'url' => $endpoint];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'vsepeski-local-app-install',
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch) ?: 'curl_exec_failed';
        curl_close($ch);
        return ['error' => $error, 'url' => $endpoint];
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        return [
            'error' => 'invalid_json',
            'http_status' => $status,
            'raw' => $body,
            'url' => $endpoint,
        ];
    }

    $decoded['http_status'] = $status;
    $decoded['url'] = $endpoint;

    return $decoded;
}
