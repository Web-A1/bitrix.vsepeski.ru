<?php

declare(strict_types=1);

namespace B24\Center\Tests\Unit;

use B24\Center\Infrastructure\Bitrix\Install\InstallRequestHandler;
use B24\Center\Infrastructure\Bitrix\Install\PlacementBindingDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InstallRequestHandlerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/b24-install-test-' . uniqid();
        mkdir($this->projectRoot . '/public/hauls', 0777, true);
        mkdir($this->projectRoot . '/storage/bitrix', 0777, true);
        file_put_contents($this->projectRoot . '/public/hauls/index.html', '<html></html>');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testHandleWithoutTokensReturnsReadyPayload(): void
    {
        $dispatcher = new RecordingDispatcher();
        $handler = new InstallRequestHandler($this->projectRoot, new NullLogger(), $dispatcher);

        $result = $handler->handle([], [], [], [], 'GET');

        $payload = $result->getJsonPayload();
        self::assertNotNull($payload);
        self::assertTrue($payload['result']);
        self::assertSame('Install endpoint ready', $payload['message']);
        self::assertCount(0, $dispatcher->calls);
    }

    public function testHandlePersistsTokensAndDispatchesPlacements(): void
    {
        $dispatcher = new RecordingDispatcher();
        $dispatcher->nextResult = ['CRM_DEAL_DETAIL_TAB' => ['bind' => true]];
        $handler = new InstallRequestHandler($this->projectRoot, new NullLogger(), $dispatcher);

        $payload = [
            'auth' => [
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'expires_in' => 3600,
                'domain' => 'example.bitrix24.ru',
            ],
            'event' => 'ONAPPINSTALL',
        ];

        $result = $handler->handle($payload, [], [], [], 'POST');

        $json = $result->getJsonPayload();
        self::assertNotNull($json);
        self::assertTrue($json['result']);
        self::assertSame($dispatcher->nextResult, $json['bindings']);
        self::assertCount(1, $dispatcher->calls);

        $call = $dispatcher->calls[0];
        self::assertSame('example.bitrix24.ru', $call['domain']);
        self::assertSame('access', $call['token']);
        self::assertContainsEquals('CRM_DEAL_DETAIL_TAB', $call['placements']);

        $storedPath = $this->projectRoot . '/storage/bitrix/oauth.json';
        self::assertFileExists($storedPath);

        $stored = json_decode((string) file_get_contents($storedPath), true);
        self::assertSame('access', $stored['access_token']);
        self::assertSame('refresh', $stored['refresh_token']);
    }

    public function testFallbackRebindsPlacementsWhenEnabled(): void
    {
        $dispatcher = new RecordingDispatcher();
        $handler = new InstallRequestHandler(
            $this->projectRoot,
            new NullLogger(),
            $dispatcher,
            'https://example.com/install.php?placement=hauls',
            true,
            0
        );

        $payload = [
            'auth' => [
                'access_token' => 'access-fallback',
                'refresh_token' => 'refresh-fallback',
                'expires_in' => 3600,
                'domain' => 'fallback.example.com',
            ],
            'PLACEMENT' => 'DEFAULT',
        ];

        $handler->handle($payload, [], [], [], 'POST');

        self::assertCount(1, $dispatcher->calls);
        $call = $dispatcher->calls[0];
        self::assertSame('fallback.example.com', $call['domain']);
        self::assertSame('access-fallback', $call['token']);
    }

    public function testFallbackHonorsThrottle(): void
    {
        $statePath = $this->projectRoot . '/storage/bitrix/install-fallback.json';
        file_put_contents($statePath, json_encode([
            'last_rebind_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], JSON_PRETTY_PRINT));

        $dispatcher = new RecordingDispatcher();
        $handler = new InstallRequestHandler(
            $this->projectRoot,
            new NullLogger(),
            $dispatcher,
            'https://example.com/install.php?placement=hauls',
            true,
            3600
        );

        $payload = [
            'auth' => [
                'access_token' => 'access-fallback',
                'refresh_token' => 'refresh-fallback',
                'expires_in' => 3600,
                'domain' => 'fallback.example.com',
            ],
            'PLACEMENT' => 'DEFAULT',
        ];

        $handler->handle($payload, [], [], [], 'POST');

        self::assertCount(0, $dispatcher->calls);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}

final class RecordingDispatcher implements PlacementBindingDispatcher
{
    /**
     * @var list<array{domain:string,token:string,handler:string,placements:array,options:array}>
     */
    public array $calls = [];

    /**
     * @var array<string,mixed>
     */
    public array $nextResult = [];

    /**
     * @param list<string> $placements
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function dispatch(
        string $domain,
        string $token,
        string $handlerUri,
        array $placements,
        array $options = []
    ): array {
        $this->calls[] = [
            'domain' => $domain,
            'token' => $token,
            'handler' => $handlerUri,
            'placements' => $placements,
            'options' => $options,
        ];

        return $this->nextResult;
    }
}
