<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Http;

use B24\Center\Infrastructure\Http\Response;

class Kernel
{
    public function handle(array $server): Response
    {
        $payload = [
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'app' => [
                'env' => $_ENV['APP_ENV'] ?? 'local',
                'debug' => filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOL),
            ],
        ];

        return Response::json($payload);
    }
}

