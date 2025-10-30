<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Persistence\Database;

use PDO;
use PDOException;
use RuntimeException;

final class ConnectionFactory
{
    public static function make(): PDO
    {
        $driver = $_ENV['DB_CONNECTION'] ?? 'mysql';

        return match ($driver) {
            'mysql' => self::createMysqlConnection(),
            'pgsql' => self::createPostgresConnection(),
            default => throw new RuntimeException(sprintf('Database driver "%s" is not supported.', $driver)),
        };
    }

    private static function createMysqlConnection(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = (int) ($_ENV['DB_PORT'] ?? 3306);
        $database = $_ENV['DB_DATABASE'] ?? '';
        $username = $_ENV['DB_USERNAME'] ?? '';
        $password = $_ENV['DB_PASSWORD'] ?? '';
        $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to connect to the MySQL database: ' . $exception->getMessage(), 0, $exception);
        }

        return $pdo;
    }

    private static function createPostgresConnection(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = (int) ($_ENV['DB_PORT'] ?? 5432);
        $database = $_ENV['DB_DATABASE'] ?? '';
        $username = $_ENV['DB_USERNAME'] ?? '';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to connect to the PostgreSQL database: ' . $exception->getMessage(), 0, $exception);
        }

        return $pdo;
    }
}

