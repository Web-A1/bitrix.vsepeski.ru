<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Bitrix\Install;

use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Ui\HaulPlacementPageRenderer;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class InstallRequestHandler
{
    private const DEFAULT_PLACEMENTS = [
        'CRM_DEAL_DETAIL_TAB',
        'CRM_DEAL_LIST_MENU',
    ];

    private readonly HaulPlacementPageRenderer $renderer;

    public function __construct(
        private readonly string $projectRoot,
        private readonly LoggerInterface $logger,
        private readonly PlacementBindingDispatcher $bindingDispatcher,
        private readonly string $placementHandlerUri = 'https://bitrix.vsepeski.ru/bitrix/install.php?placement=hauls'
    ) {
        $this->renderer = new HaulPlacementPageRenderer($projectRoot);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $request
     */
    public function handle(
        array $payload,
        array $query,
        array $post,
        array $request,
        string $requestMethod
    ): InstallResult {
        $auth = $this->extractAuthData($payload);
        $hasTokens = $this->hasTokens($auth);
        $isPlacementLaunch = $this->isPlacementLaunch($payload, $query, $request);
        $isTokenDelivery = strtoupper($requestMethod) === 'POST' && $hasTokens;
        $eventName = $this->extractEventName($payload);
        $isInstallEvent = $this->isInstallEvent($eventName);

        if ($isPlacementLaunch && !$isInstallEvent && !$isTokenDelivery) {
            return $this->renderPlacement($payload, $query, $post, $request);
        }

        $this->logger->info('install.php payload parsed', [
            'event' => $eventName,
            'is_install_event' => $isInstallEvent,
            'is_placement_launch' => $isPlacementLaunch,
            'has_tokens' => $hasTokens,
            'domain' => $auth['domain'] ?? $payload['DOMAIN'] ?? null,
        ]);

        if (!$hasTokens) {
            return InstallResult::json([
                'result' => true,
                'message' => 'Install endpoint ready',
            ]);
        }

        $storedData = $this->buildStoredPayload($auth, $payload);

        if (!$this->persistOAuthPayload($storedData)) {
            return InstallResult::json([
                'result' => false,
                'error' => 'failed to persist auth payload',
            ], 500);
        }

        if ($isPlacementLaunch && !$isInstallEvent) {
            return $this->renderPlacement($payload, $query, $post, $request);
        }

        $domain = $auth['domain'] ?? $payload['DOMAIN'] ?? null;

        $bindings = [];

        if ($isInstallEvent && is_string($domain) && $domain !== '') {
            $bindings = $this->rebindPlacements($domain, (string) $auth['access_token']);
        }

        return InstallResult::json(['result' => true, 'bindings' => $bindings]);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $request
     */
    private function renderPlacement(
        array $payload,
        array $query,
        array $post,
        array $request
    ): InstallResult {
        try {
            $response = $this->renderer->render($payload, $query, $post, $request);
        } catch (RuntimeException $exception) {
            $this->logger->error('failed to render hauls placement', ['exception' => $exception]);

            return InstallResult::json([
                'result' => false,
                'error' => 'failed to render hauls placement',
                'message' => $exception->getMessage(),
            ], 500);
        }

        if ($response instanceof Response) {
            return InstallResult::html($response);
        }

        return InstallResult::json([
            'result' => false,
            'error' => 'unexpected placement renderer response',
        ], 500);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $query
     * @param array<string,mixed> $request
     */
    private function isPlacementLaunch(array $payload, array $query, array $request): bool
    {
        if (isset($payload['PLACEMENT']) || isset($payload['placement'])) {
            return true;
        }

        if (isset($query['PLACEMENT']) || isset($query['placement'])) {
            return true;
        }

        if (isset($request['PLACEMENT']) || isset($request['placement'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function buildStoredPayload(array $auth, array $payload): array
    {
        $expiresIn = isset($auth['expires_in']) ? (int) $auth['expires_in'] : 3600;
        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $expiresIn));

        return [
            'received_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
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
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function persistOAuthPayload(array $payload): bool
    {
        $storageDir = $this->projectRoot . '/storage/bitrix';

        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            $this->logger->error('failed to create storage directory', ['path' => $storageDir]);
            return false;
        }

        $filePath = $storageDir . '/oauth.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            $this->logger->error('failed to encode auth payload');
            return false;
        }

        if (file_put_contents($filePath, $encoded) === false) {
            $this->logger->error('failed to persist auth payload', ['path' => $filePath]);
            return false;
        }

        $this->logger->info('install.php oauth payload persisted', [
            'path' => $filePath,
            'expires_at' => $payload['expires_at'] ?? null,
            'domain' => $payload['domain'] ?? null,
        ]);

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private function rebindPlacements(string $domain, string $token): array
    {
        $options = $this->buildPlacementOptions();

        $this->logger->info('install.php rebind placements', [
            'domain' => $domain,
            'placements' => self::DEFAULT_PLACEMENTS,
        ]);

        return $this->bindingDispatcher->dispatch(
            $domain,
            $token,
            $this->placementHandlerUri,
            self::DEFAULT_PLACEMENTS,
            $options
        );
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function extractAuthData(array $payload): array
    {
        if (isset($payload['auth']) && is_array($payload['auth'])) {
            return $payload['auth'];
        }

        return [
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

    /**
     * @param array<string,mixed> $auth
     */
    private function hasTokens(array $auth): bool
    {
        return !empty($auth['access_token']) && !empty($auth['refresh_token']);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractEventName(array $payload): ?string
    {
        if (isset($payload['event']) && is_string($payload['event'])) {
            return $payload['event'];
        }

        if (isset($payload['EVENT']) && is_string($payload['EVENT'])) {
            return $payload['EVENT'];
        }

        return null;
    }

    private function isInstallEvent(?string $eventName): bool
    {
        return is_string($eventName) && stripos($eventName, 'ONAPPINSTALL') !== false;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPlacementOptions(): array
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

}
