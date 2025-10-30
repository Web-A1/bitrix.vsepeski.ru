<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Persistence\Database;

use B24\Center\Core\Application;
use B24\Center\Infrastructure\Persistence\Database\ConnectionFactory;
use PDO;

final class DatabaseServiceProvider
{
    public function register(Application $app): void
    {
        $app->singleton(PDO::class, static function (): PDO {
            static $connection = null;

            if ($connection instanceof PDO) {
                return $connection;
            }

            $connection = ConnectionFactory::make();

            return $connection;
        });
    }
}
