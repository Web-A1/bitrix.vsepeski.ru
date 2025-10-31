<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Http;

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Application\Services\HaulService;
use B24\Center\Modules\Hauls\Ui\HaulController;
use DateTimeImmutable;
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

        $haulController = new HaulController($this->container->get(HaulService::class));

        if (preg_match('#^/api/deals/(\d+)/hauls$#', $path, $matches)) {
            $dealId = (int) $matches[1];

            return match ($method) {
                'GET' => $haulController->index($dealId),
                'POST' => $haulController->store($dealId, $request),
                default => $this->methodNotAllowed(['GET', 'POST']),
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
}

