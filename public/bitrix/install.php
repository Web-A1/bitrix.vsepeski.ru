<?php

declare(strict_types=1);

use B24\Center\Infrastructure\Http\Response;
use B24\Center\Infrastructure\Logging\InstallLoggerFactory;
use B24\Center\Modules\Hauls\Ui\HaulPlacementPageRenderer;
use Psr\Log\LoggerInterface;

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

/**
 * Bitrix24 application installation handler.
 *
 * Bitrix отправляет данные об авторизации (access_token / refresh_token и т.д.)
 * во время установки или переустановки локального приложения.
 * Скрипт сохраняет полученные значения в storage/bitrix/oauth.json, чтобы
 * дальнейшие REST-вызовы могли выполняться от имени приложения.
 */

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
$isPlacementLaunch = isset($payload['PLACEMENT']) || isset($payload['placement']);
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isPostRequest = $requestMethod === 'POST';
$isTokenDelivery = $isPostRequest && $hasTokens;
$eventName = null;

if (isset($payload['event']) && is_string($payload['event'])) {
    $eventName = $payload['event'];
} elseif (isset($payload['EVENT']) && is_string($payload['EVENT'])) {
    $eventName = $payload['EVENT'];
}

$isInstallEvent = is_string($eventName) && stripos($eventName, 'ONAPPINSTALL') !== false;

if ($isPlacementLaunch && !$isInstallEvent && !$isTokenDelivery) {
    renderPlacement($projectRoot, $payload, $_GET, $_POST, $_REQUEST, $logger);
    return;
}

$logger->info('install.php payload parsed', [
    'event' => $eventName,
    'is_install_event' => $isInstallEvent,
    'is_placement_launch' => $isPlacementLaunch,
    'has_tokens' => $hasTokens,
    'domain' => $auth['domain'] ?? $payload['DOMAIN'] ?? null,
]);

if (!$hasTokens) {
    respondJson([
        'result' => true,
        'message' => 'Install endpoint ready',
    ]);
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

if (!persistOAuthPayload($projectRoot, $storedData, $logger)) {
    respondJson([
        'result' => false,
        'error' => 'failed to persist auth payload',
    ], 500);
    return;
}

if ($isPlacementLaunch && !$isInstallEvent) {
    renderPlacement($projectRoot, $payload, $_GET, $_POST, $_REQUEST, $logger);
    return;
}

$domain = $auth['domain'] ?? $payload['DOMAIN'] ?? null;
$bindings = [];

if ($isInstallEvent && is_string($domain) && $domain !== '') {
    $primaryHandler = 'https://bitrix.vsepeski.ru/bitrix/install.php?placement=hauls';

    $options = buildPlacementOptions();

    $logger->info('install.php rebind placements', [
        'domain' => $domain,
        'placements' => ['CRM_DEAL_DETAIL_TAB', 'CRM_DEAL_LIST_MENU'],
    ]);

    $bindings['CRM_DEAL_DETAIL_TAB'] = rebindPlacement(
        $domain,
        $auth['access_token'],
        'CRM_DEAL_DETAIL_TAB',
        $primaryHandler,
        $options
    );

    $bindings['CRM_DEAL_LIST_MENU'] = rebindPlacement(
        $domain,
        $auth['access_token'],
        'CRM_DEAL_LIST_MENU',
        $primaryHandler,
        $options
    );
}

respondJson(['result' => true, 'bindings' => $bindings]);

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
 * Returns placement options recommended by Bitrix24 for mobile CRM tabs.
 *
 * @return array<string,mixed>
 */
function buildPlacementOptions(): array
{
    return [
        'TITLE' => 'Рейсы',
        'DESCRIPTION' => 'Вкладка с рейсами сделки',
        'LANG' => 'ru',
        'LANG_ALL' => [
            'ru' => ['TITLE' => 'Рейсы', 'DESCRIPTION' => 'Вкладка с рейсами сделки', 'GROUP_NAME' => ''],
            'en' => ['TITLE' => 'Hauls', 'DESCRIPTION' => 'Deal hauls tab', 'GROUP_NAME' => ''],
        ],
        'OPTIONS' => [
            'register' => 'Y',
            'support_mobile' => 'Y',
            'supportMobile' => 'Y',
        ],
    ];
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
    $hasComplexParams = preg_match('/%5B.+%5D=/', $query) === 1;

    $endpoint = $url;
    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['error' => 'curl_init_failed', 'url' => $endpoint];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'vsepeski-local-app-install',
    ];

    $options[CURLOPT_URL] = $hasComplexParams ? $endpoint : $endpoint . '?' . $query;

    if ($hasComplexParams) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $query;
    }

    curl_setopt_array($ch, $options);

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

/**
 * @param array<string,mixed> $payload
 *
 * @return array<string,mixed>
 */
function renderPlacement(
    string $projectRoot,
    array $payload,
    array $query,
    array $post,
    array $request,
    LoggerInterface $logger
): void {
    $renderer = new HaulPlacementPageRenderer($projectRoot);

    try {
        $response = $renderer->render($payload, $query, $post, $request);
    } catch (\RuntimeException $exception) {
        $logger->error('failed to render hauls placement', ['exception' => $exception]);
        respondJson([
            'result' => false,
            'error' => 'failed to render hauls placement',
            'message' => $exception->getMessage(),
        ], 500);
        return;
    }

    if ($response instanceof Response) {
        $response->send();
    }
}

/**
 * @param array<string,mixed> $payload
 */
function respondJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<string,mixed> $payload
 */
function persistOAuthPayload(string $projectRoot, array $payload, LoggerInterface $logger): bool
{
    $storageDir = $projectRoot . '/storage/bitrix';

    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        $logger->error('failed to create storage directory', ['path' => $storageDir]);
        return false;
    }

    $filePath = $storageDir . '/oauth.json';
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!is_string($encoded)) {
        $logger->error('failed to encode auth payload');
        return false;
    }

    if (file_put_contents($filePath, $encoded) === false) {
        $logger->error('failed to persist auth payload', ['path' => $filePath]);
        return false;
    }

    $logger->info('install.php oauth payload persisted', [
        'path' => $filePath,
        'expires_at' => $payload['expires_at'] ?? null,
        'domain' => $payload['domain'] ?? null,
    ]);

    return true;
}
