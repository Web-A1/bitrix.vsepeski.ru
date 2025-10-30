<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Persistence\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly PDO $connection,
        private readonly string $migrationsPath
    ) {
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException(sprintf('Migrations path "%s" does not exist.', $this->migrationsPath));
        }
    }

    public function migrate(): void
    {
        $this->ensureSchemaMigrationsTable();

        $executed = $this->fetchExecutedMigrations();
        $migrations = $this->discoverMigrations();

        foreach ($migrations as $name => $sql) {
            if (in_array($name, $executed, true)) {
                continue;
            }

            $this->runMigration($name, $sql);
        }
    }

    private function ensureSchemaMigrationsTable(): void
    {
        $driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);

        $timestampColumn = $driver === 'mysql'
            ? 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            : 'TIMESTAMPTZ NOT NULL DEFAULT NOW()';

        $this->connection->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(255) PRIMARY KEY, executed_at %s)',
            $timestampColumn
        ));
    }

    /**
     * @return list<string>
     */
    private function fetchExecutedMigrations(): array
    {
        $statement = $this->connection->query('SELECT name FROM schema_migrations ORDER BY name');

        return array_map(static fn (array $row): string => $row['name'], $statement->fetchAll());
    }

    /**
     * @return array<string,string>
     */
    private function discoverMigrations(): array
    {
        $files = glob($this->migrationsPath . '/*.sql');

        if ($files === false) {
            return [];
        }

        sort($files);

        $migrations = [];

        foreach ($files as $file) {
            $name = basename($file);
            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new RuntimeException(sprintf('Unable to read migration file "%s".', $file));
            }

            $migrations[$name] = $sql;
        }

        return $migrations;
    }

    private function runMigration(string $name, string $sql): void
    {
        $this->connection->beginTransaction();

        try {
            foreach ($this->splitStatements($sql) as $statement) {
                if ($statement === '') {
                    continue;
                }

                $this->connection->exec($statement);
            }

            $statement = $this->connection->prepare('INSERT INTO schema_migrations (name) VALUES (:name)');
            $statement->execute(['name' => $name]);

            $this->connection->commit();
        } catch (\Throwable $throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw new RuntimeException(sprintf('Failed to run migration "%s": %s', $name, $throwable->getMessage()), 0, $throwable);
        }
    }

    /**
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = preg_split('/;\\s*(?=\\n|$)/', $sql) ?: [];

        return array_map(static fn (string $statement): string => trim($statement), $statements);
    }
}
