<?php

declare(strict_types=1);

namespace B24\Center\Tests\Feature;

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Auth\SessionAuthManager;
use B24\Center\Infrastructure\Auth\ActorContextResolver;
use B24\Center\Infrastructure\Bitrix\BitrixUserResolver;
use B24\Center\Infrastructure\Http\Kernel;
use B24\Center\Infrastructure\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class BitrixAuthEndpointTest extends TestCase
{
    private Application $app;
    private Kernel $kernel;
    private SessionAuthManager $authManager;
    private string $oauthPath;
    private FakeBitrixUserResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        $_ENV['BITRIX_PORTAL_URL'] = 'https://example.bitrix24.ru';

        $this->oauthPath = sys_get_temp_dir() . '/bitrix-oauth-' . uniqid() . '.json';
        file_put_contents($this->oauthPath, json_encode([
            'member_id' => 'known-member',
            'domain' => 'example.bitrix24.ru',
        ], JSON_THROW_ON_ERROR));
        $_ENV['BITRIX_OAUTH_PATH'] = $this->oauthPath;

        $this->app = new Application();
        $this->authManager = new SessionAuthManager();
        $this->resolver = new FakeBitrixUserResolver([
            'ID' => 100,
            'NAME' => 'Admin',
            'LAST_NAME' => 'Test',
            'LOGIN' => 'admin.test',
            'EMAIL' => 'admin@example.com',
            'ADMIN' => 'Y',
        ]);

        $this->app->singleton(SessionAuthManager::class, fn () => $this->authManager);
        $this->app->singleton(ActorContextResolver::class, fn () => new ActorContextResolver($this->authManager));
        $this->app->singleton(BitrixUserResolver::class, fn () => $this->resolver);

        $this->kernel = new Kernel($this->app);
    }

    protected function tearDown(): void
    {
        if (is_file($this->oauthPath)) {
            @unlink($this->oauthPath);
        }
        unset($_ENV['BITRIX_OAUTH_PATH'], $_ENV['BITRIX_PORTAL_URL']);
        parent::tearDown();
    }

    public function testBitrixAuthRequiresMemberId(): void
    {
        $request = Request::fake('POST', '/api/auth/bitrix', ['auth_id' => 'token']);

        $response = $this->dispatch($request);

        self::assertSame(422, $response->status());
    }

    public function testBitrixAuthRejectsUnknownPortal(): void
    {
        $request = Request::fake('POST', '/api/auth/bitrix', [
            'auth_id' => 'token',
            'member_id' => 'other-member',
        ]);

        $response = $this->dispatch($request);

        self::assertSame(403, $response->status());
    }

    public function testBitrixAuthStoresSession(): void
    {
        $request = Request::fake('POST', '/api/auth/bitrix', [
            'auth_id' => 'token',
            'member_id' => 'known-member',
            'domain' => 'example.bitrix24.ru',
        ]);

        $response = $this->dispatch($request);

        self::assertSame(200, $response->status());
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(100, $payload['data']['id']);
        self::assertSame('admin', strtolower($payload['data']['role'] ?? ''));
    }

    private function dispatch(Request $request)
    {
        $method = new ReflectionMethod(Kernel::class, 'dispatch');
        $method->setAccessible(true);

        return $method->invoke($this->kernel, $request);
    }
}

final class FakeBitrixUserResolver extends BitrixUserResolver
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(private array $payload)
    {
        parent::__construct('https://example.bitrix24.ru');
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve(string $authToken): array
    {
        if ($authToken === 'invalid') {
            throw new RuntimeException('invalid token');
        }

        return $this->payload;
    }
}
