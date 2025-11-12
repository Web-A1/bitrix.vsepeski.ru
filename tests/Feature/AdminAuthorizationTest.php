<?php

declare(strict_types=1);

namespace B24\Center\Tests\Feature;

use B24\Center\Infrastructure\Auth\ActorContextResolver;
use B24\Center\Infrastructure\Auth\SessionAuthManager;
use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Bitrix\BitrixRestClient;
use B24\Center\Modules\Hauls\Application\Services\HaulService;
use B24\Center\Modules\Hauls\Application\Services\DealInfoService;
use B24\Center\Modules\Hauls\Infrastructure\HaulChangeHistoryRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulStatusHistoryRepository;
use B24\Center\Modules\Hauls\Infrastructure\MaterialRepository;
use B24\Center\Modules\Hauls\Infrastructure\TruckRepository;
use B24\Center\Modules\Hauls\Ui\HaulController;
use B24\Center\Modules\Hauls\Ui\MaterialController;
use B24\Center\Modules\Hauls\Ui\TruckController;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminAuthorizationTest extends TestCase
{
    private SessionAuthManager $authManager;
    private ActorContextResolver $resolver;
    private PDO $pdo;
    private DealInfoService $dealService;

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        $this->authManager = new SessionAuthManager();
        $this->resolver = new ActorContextResolver($this->authManager);
        $this->dealService = new DealInfoService(new BitrixRestClient('https://example.com/rest'));
        $this->pdo = new class extends PDO {
            public function __construct()
            {
            }

            public function prepare($statement, $options = null): PDOStatement|false
            {
                throw new RuntimeException('Database access is not expected during authorization checks.');
            }

            public function query(
                string $statement,
                ?int $fetchMode = null,
                mixed ...$fetchModeArgs,
            ): PDOStatement|false {
                throw new RuntimeException('Database access is not expected during authorization checks.');
            }

            public function exec($statement): int|false
            {
                throw new RuntimeException('Database access is not expected during authorization checks.');
            }
        };
    }

    public function testMaterialsStoreRequiresAdminSession(): void
    {
        $controller = new MaterialController(
            new MaterialRepository($this->pdo),
            new HaulRepository($this->pdo),
            $this->resolver
        );

        $request = Request::fake(
            method: 'POST',
            path: '/api/materials',
            body: ['name' => 'Щебень'],
            headers: ['X-Actor-Role' => 'admin']
        );

        $response = $controller->store($request);

        self::assertSame(403, $response->status());
    }

    public function testTrucksUpdateRequiresAdminSession(): void
    {
        $controller = new TruckController(
            new TruckRepository($this->pdo),
            new HaulRepository($this->pdo),
            $this->resolver
        );

        $request = Request::fake(
            method: 'PATCH',
            path: '/api/trucks/truck-1',
            headers: ['X-Actor-Role' => 'admin'],
            body: ['license_plate' => 'A123AA'],
        );

        $response = $controller->update('truck-1', $request);

        self::assertSame(403, $response->status());
    }

    public function testHaulStoreRequiresAdminSession(): void
    {
        $controller = new HaulController(
            new HaulService(
                new HaulRepository($this->pdo),
                new HaulStatusHistoryRepository($this->pdo),
                new HaulChangeHistoryRepository($this->pdo)
            ),
            $this->resolver,
            $this->dealService
        );

        $request = Request::fake(
            method: 'POST',
            path: '/api/deals/1/hauls',
            body: [
                'truck_id' => 'truck-1',
                'material_id' => 'material-1',
                'load_address_text' => 'Адрес загрузки',
                'unload_address_text' => 'Адрес выгрузки',
            ],
            headers: ['X-Actor-Role' => 'admin']
        );

        $response = $controller->store(1, $request);

        self::assertSame(403, $response->status());
    }
}
