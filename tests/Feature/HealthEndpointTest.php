<?php

declare(strict_types=1);

namespace B24\Center\Tests\Feature;

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Auth\ActorContextResolver;
use B24\Center\Infrastructure\Auth\SessionAuthManager;
use B24\Center\Infrastructure\Http\Kernel;
use PHPUnit\Framework\TestCase;
use PDO;

final class HealthEndpointTest extends TestCase
{
    private Application $app;
    private string $queueDir;
    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application();
        $this->app->singleton(SessionAuthManager::class, static fn () => new SessionAuthManager());
        $this->app->singleton(
            ActorContextResolver::class,
            static fn (Application $container) => new ActorContextResolver($container->get(SessionAuthManager::class))
        );
        $this->bindPdoStub();

        $projectRoot = dirname(__DIR__, 2);
        $this->queueDir = $projectRoot . '/storage/bitrix/placement-jobs';
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0777, true);
        }
        $this->cleanupQueue();
    }

    protected function tearDown(): void
    {
        $this->cleanupQueue();
        parent::tearDown();
    }

    public function testHealthEndpointReturnsOkWhenDependenciesHealthy(): void
    {
        $kernel = new Kernel($this->app);
        $response = $kernel->handle($this->server('/health'));

        self::assertSame(200, $response->status());
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertSame('ok', $payload['checks']['database']['status']);
        self::assertSame(0, $payload['checks']['queue']['pending']);
        self::assertSame(0, $payload['checks']['queue']['failed']);
    }

    public function testHealthEndpointReportsDegradedWhenQueueHasFailedJobs(): void
    {
        $failedFile = $this->queueDir . '/test-job.failed';
        file_put_contents($failedFile, '{}');
        $this->createdFiles[] = $failedFile;

        $kernel = new Kernel($this->app);
        $response = $kernel->handle($this->server('/health'));

        self::assertSame(503, $response->status());
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('degraded', $payload['status']);
        self::assertSame('warning', $payload['checks']['queue']['status']);
        self::assertGreaterThanOrEqual(1, $payload['checks']['queue']['failed']);
    }

    private function bindPdoStub(): void
    {
        $pdoStub = new class {
            public function query(string $statement): bool
            {
                return true;
            }
        };

        $this->app->singleton(PDO::class, static fn () => $pdoStub);
    }

    private function server(string $uri): array
    {
        return [
            'REQUEST_URI' => $uri,
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'localhost',
        ];
    }

    private function cleanupQueue(): void
    {
        $pattern = $this->queueDir . '/test-job*';
        foreach (glob($pattern) ?: [] as $file) {
            @unlink($file);
        }

        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->createdFiles = [];
    }
}
