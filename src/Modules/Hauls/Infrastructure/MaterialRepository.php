<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Infrastructure;

use B24\Center\Modules\Hauls\Domain\Material;
use B24\Center\Support\Uuid;
use PDO;

final class MaterialRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $name, ?string $description = null): Material
    {
        $id = Uuid::v4();

        $statement = $this->connection->prepare(
            'INSERT INTO materials (id, name, description) VALUES (:id, :name, :description)'
        );

        $statement->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
        ]);

        return new Material($id, $name, $description);
    }

    /**
     * @return Material[]
     */
    public function all(): array
    {
        $statement = $this->connection->query('SELECT id, name, description FROM materials ORDER BY LOWER(name)');

        return array_map(
            static fn (array $row): Material => new Material($row['id'], $row['name'], $row['description']),
            $statement->fetchAll()
        );
    }

    public function find(string $id): ?Material
    {
        $statement = $this->connection->prepare('SELECT id, name, description FROM materials WHERE id = :id');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return new Material($row['id'], $row['name'], $row['description']);
    }

    public function save(Material $material): void
    {
        $statement = $this->connection->prepare(
            'UPDATE materials SET name = :name, description = :description, updated_at = NOW() WHERE id = :id'
        );

        $statement->execute([
            'id' => $material->id(),
            'name' => $material->name(),
            'description' => $material->description(),
        ]);
    }

    public function delete(string $id): void
    {
        $statement = $this->connection->prepare('DELETE FROM materials WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
