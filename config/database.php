<?php

declare(strict_types=1);

return [
    'default' => $_ENV['DB_CONNECTION'] ?? 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_DATABASE'] ?? 'b24_center',
            'username' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => $_ENV['DB_PG_HOST'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['DB_PG_PORT'] ?? '5432',
            'database' => $_ENV['DB_PG_DATABASE'] ?? 'b24_center',
            'username' => $_ENV['DB_PG_USERNAME'] ?? 'postgres',
            'password' => $_ENV['DB_PG_PASSWORD'] ?? '',
        ],
    ],
];
