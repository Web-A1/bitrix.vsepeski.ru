<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Http;

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Http\Response;
use B24\Center\Infrastructure\Auth\ActorContextResolver;
use B24\Center\Infrastructure\Auth\SessionAuthManager;
use B24\Center\Infrastructure\Auth\LocalDriverAuthenticator;
use B24\Center\Modules\Hauls\Application\Services\HaulService;
use B24\Center\Modules\Hauls\Application\DTO\ActorContext;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Infrastructure\MaterialRepository;
use B24\Center\Modules\Hauls\Infrastructure\TruckRepository;
use B24\Center\Modules\Hauls\Application\Services\DriverLookupService;
use B24\Center\Modules\Hauls\Application\Services\CompanyDirectoryService;
use B24\Center\Modules\Hauls\Application\Services\DealInfoService;
use B24\Center\Modules\Hauls\Ui\HaulController;
use B24\Center\Modules\Hauls\Ui\MaterialController;
use B24\Center\Modules\Hauls\Ui\TruckController;
use B24\Center\Modules\Hauls\Ui\DriverController;
use B24\Center\Modules\Hauls\Ui\HaulPlacementPageRenderer;
use B24\Center\Modules\Hauls\Ui\CompanyDirectoryController;
use B24\Center\Modules\Hauls\Ui\DealInfoController;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

class Kernel
{
    public function __construct(private readonly Application $container)
    {
    }

    public function handle(array $server): Response
    {
        $request = Request::fromGlobals($server);

        try {
            return $this->dispatch($request);
        } catch (Throwable $exception) {
            return Response::json([
                'error' => 'Internal Server Error',
                'message' => $_ENV['APP_DEBUG'] ?? false ? $exception->getMessage() : null,
            ], 500);
        }
    }

    private function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = rtrim($request->path(), '/') ?: '/';
        /** @var SessionAuthManager $authManager */
        $authManager = $this->container->get(SessionAuthManager::class);
        /** @var ActorContextResolver $actorResolver */
        $actorResolver = $this->container->get(ActorContextResolver::class);

        if ($path === '/') {
            return Response::json([
                'status' => 'ok',
                'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
                'app' => [
                    'env' => $_ENV['APP_ENV'] ?? 'local',
                    'debug' => filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOL),
                ],
            ]);
        }

        if ($path === '/health') {
            return $this->healthCheck();
        }

        if ($path === '/hauls') {
            /** @var HaulPlacementPageRenderer $renderer */
            $renderer = $this->container->get(HaulPlacementPageRenderer::class);

            try {
                $payload = $request->body();
                if (!is_array($payload)) {
                    $payload = [];
                }

                foreach ($_REQUEST as $key => $value) {
                    if (!array_key_exists($key, $payload)) {
                        $payload[$key] = $value;
                    }
                }

                $response = $renderer->render(
                    $payload,
                    $_GET,
                    $_POST,
                    $_REQUEST
                );
            } catch (RuntimeException $exception) {
                return Response::json([
                    'error' => 'Failed to render hauls placement',
                    'message' => $exception->getMessage(),
                ], 500);
            }

            return $response;
        }

        if ($path === '/api/auth/me') {
            $user = $authManager->user();

            if ($user === null) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }

            return Response::json(['data' => $user]);
        }

        if ($path === '/api/auth/login') {
            if ($method !== 'POST') {
                return $this->methodNotAllowed(['POST']);
            }

            $payload = $request->body();
            $login = isset($payload['login']) ? trim((string) $payload['login']) : '';
            $password = isset($payload['password']) && is_string($payload['password'])
                ? $payload['password']
                : '';

            if ($login === '' || $password === '') {
                return Response::json(['error' => 'Укажите логин и пароль.'], 422);
            }

            try {
                /** @var LocalDriverAuthenticator $authenticator */
                $authenticator = $this->container->get(LocalDriverAuthenticator::class);
                $user = $authenticator->login($login, $password);
            } catch (RuntimeException $exception) {
                return Response::json(['error' => $exception->getMessage()], 401);
            }

            $authManager->login($user);

            return Response::json(['data' => $authManager->user()]);
        }

        if ($path === '/api/auth/logout') {
            if ($method !== 'POST') {
                return $this->methodNotAllowed(['POST']);
            }

            $authManager->logout();

            return Response::noContent();
        }

        $haulController = new HaulController(
            $this->container->get(HaulService::class),
            $actorResolver
        );

        /** @var HaulRepository $haulRepository */
        $haulRepository = $this->container->get(HaulRepository::class);

        $truckController = new TruckController(
            $this->container->get(TruckRepository::class),
            $haulRepository,
            $actorResolver
        );
        $materialController = new MaterialController(
            $this->container->get(MaterialRepository::class),
            $haulRepository,
            $actorResolver
        );
        $driverController = new DriverController($this->container->get(DriverLookupService::class));
        $companyController = new CompanyDirectoryController($this->container->get(CompanyDirectoryService::class));
        $dealController = new DealInfoController($this->container->get(DealInfoService::class));

        if ($path === '/api/mobile/hauls') {
            $user = $authManager->user();

            if ($user === null) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }

            return match ($method) {
                'GET' => $haulController->myHauls((int) $user['id']),
                default => $this->methodNotAllowed(['GET']),
            };
        }

        if (preg_match('#^/api/mobile/hauls/([A-Za-z0-9\\-]+)/status$#', $path, $matches)) {
            $user = $authManager->user();

            if ($user === null) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }

            if ($method !== 'POST') {
                return $this->methodNotAllowed(['POST']);
            }

            $actor = new ActorContext(
                isset($user['id']) ? (int) $user['id'] : null,
                isset($user['name']) ? (string) $user['name'] : null,
                'driver'
            );

            return $haulController->transitionStatus($matches[1], $request, $actor);
        }

        if (preg_match('#^/api/deals/(\d+)$#', $path, $matches)) {
            $dealId = (int) $matches[1];

            return match ($method) {
                'GET' => $dealController->show($dealId),
                default => $this->methodNotAllowed(['GET']),
            };
        }

        if (preg_match('#^/api/deals/(\d+)/hauls$#', $path, $matches)) {
            $dealId = (int) $matches[1];

            return match ($method) {
                'GET' => $haulController->index($dealId),
                'POST' => $haulController->store($dealId, $request),
                default => $this->methodNotAllowed(['GET', 'POST']),
            };
        }

        if ($path === '/api/trucks') {
            return match ($method) {
                'GET' => $truckController->index(),
                'POST' => $truckController->store($request),
                default => $this->methodNotAllowed(['GET', 'POST']),
            };
        }

        if (preg_match('#^/api/trucks/([A-Za-z0-9\\-]+)$#', $path, $matches)) {
            $truckId = $matches[1];

            return match ($method) {
                'PUT', 'PATCH' => $truckController->update($truckId, $request),
                'DELETE' => $truckController->destroy($truckId, $request),
                default => $this->methodNotAllowed(['PUT', 'PATCH', 'DELETE']),
            };
        }

        if ($path === '/api/drivers') {
            return match ($method) {
                'GET' => $driverController->index(),
                default => $this->methodNotAllowed(['GET']),
            };
        }

        if ($path === '/api/crm/companies') {
            return match ($method) {
                'GET' => $companyController->index($request),
                default => $this->methodNotAllowed(['GET']),
            };
        }

        if ($path === '/api/materials') {
            return match ($method) {
                'GET' => $materialController->index(),
                'POST' => $materialController->store($request),
                default => $this->methodNotAllowed(['GET', 'POST']),
            };
        }

        if (preg_match('#^/api/materials/([A-Za-z0-9\\-]+)$#', $path, $matches)) {
            $materialId = $matches[1];

            return match ($method) {
                'PUT', 'PATCH' => $materialController->update($materialId, $request),
                'DELETE' => $materialController->destroy($materialId, $request),
                default => $this->methodNotAllowed(['PUT', 'PATCH', 'DELETE']),
            };
        }

        if (preg_match('#^/api/hauls/([A-Za-z0-9\\-]+)/status$#', $path, $matches)) {
            $haulId = $matches[1];

            return match ($method) {
                'POST', 'PUT', 'PATCH' => $haulController->transitionStatus($haulId, $request, null),
                default => $this->methodNotAllowed(['POST', 'PUT', 'PATCH']),
            };
        }

        if (preg_match('#^/api/hauls/([A-Za-z0-9\\-]+)$#', $path, $matches)) {
            $haulId = $matches[1];

            return match ($method) {
                'GET' => $haulController->show($haulId),
                'PUT', 'PATCH' => $haulController->update($haulId, $request),
                'DELETE' => $haulController->destroy($haulId),
                default => $this->methodNotAllowed(['GET', 'PUT', 'PATCH', 'DELETE']),
            };
        }

        return Response::json(['error' => 'Not Found'], 404);
    }

    /**
     * @param list<string> $allowed
     */
    private function methodNotAllowed(array $allowed): Response
    {
        return new Response(
            json_encode(['error' => 'Method Not Allowed', 'allowed' => $allowed], JSON_THROW_ON_ERROR),
            405,
            ['Content-Type' => 'application/json', 'Allow' => implode(', ', $allowed)]
        );
    }

    private function healthCheck(): Response
    {
        $app = [
            'status' => 'ok',
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'env' => $_ENV['APP_ENV'] ?? 'local',
            'debug' => filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOL),
        ];

        $database = [
            'status' => 'ok',
        ];

        try {
            /** @var PDO $pdo */
            $pdo = $this->container->get(PDO::class);
            $pdo->query('SELECT 1');
        } catch (Throwable $exception) {
            $database['status'] = 'error';
            $database['message'] = $exception->getMessage();
        }

        $queueDir = $this->projectRoot() . '/storage/bitrix/placement-jobs';
        $queue = [
            'status' => 'ok',
            'pending' => 0,
            'failed' => 0,
        ];

        if (!is_dir($queueDir)) {
            $queue['status'] = 'warning';
            $queue['message'] = 'queue directory missing';
        } else {
            $pending = glob($queueDir . '/*.json') ?: [];
            $failed = glob($queueDir . '/*.failed') ?: [];
            $queue['pending'] = count($pending);
            $queue['failed'] = count($failed);

            if ($queue['pending'] > 10 || $queue['failed'] > 0) {
                $queue['status'] = 'warning';
            }
        }

        $overallOk = $database['status'] === 'ok' && $queue['status'] === 'ok';

        return Response::json([
            'status' => $overallOk ? 'ok' : 'degraded',
            'checks' => [
                'app' => $app,
                'database' => $database,
                'queue' => $queue,
            ],
        ], $overallOk ? 200 : 503);
    }

    private function projectRoot(): string
    {
        static $root = null;

        if ($root === null) {
            $root = dirname(__DIR__, 3);
        }

        return $root;
    }
}
