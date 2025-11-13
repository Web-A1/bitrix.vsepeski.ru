<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Infrastructure;

use B24\Center\Modules\Hauls\Domain\Truck;
use B24\Center\Support\Uuid;
use PDO;

final class TruckRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function create(string $licensePlate, ?string $makeModel = null, ?float $bodyVolume = null, ?string $notes = null): Truck
    {
        $id = Uuid::v4();

        $statement = $this->connection->prepare(
            'INSERT INTO trucks (id, license_plate, make_model, body_volume, notes) VALUES (:id, :license_plate, :make_model, :body_volume, :notes)'
        );

        $statement->execute([
            'id' => $id,
            'license_plate' => $licensePlate,
            'make_model' => $makeModel,
            'body_volume' => $bodyVolume,
            'notes' => $notes,
        ]);

        return new Truck($id, $licensePlate, $makeModel, $notes, $bodyVolume);
    }

    /**
     * @return Truck[]
     */
    public function all(): array
    {
        $statement = $this->connection->query(
            'SELECT id, license_plate, make_model, body_volume, notes FROM trucks ORDER BY LOWER(license_plate)'
        );

        return array_map(
            static fn (array $row): Truck => new Truck(
                $row['id'],
                $row['license_plate'],
                $row['make_model'],
                $row['notes'],
                isset($row['body_volume']) ? (float) $row['body_volume'] : null,
            ),
            $statement->fetchAll()
        );
    }

    public function find(string $id): ?Truck
    {
        $statement = $this->connection->prepare(
            'SELECT id, license_plate, make_model, body_volume, notes FROM trucks WHERE id = :id'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return new Truck(
            $row['id'],
            $row['license_plate'],
            $row['make_model'],
            $row['notes'],
            isset($row['body_volume']) ? (float) $row['body_volume'] : null
        );
    }

    public function findByLicensePlate(string $licensePlate): ?Truck
    {
        $statement = $this->connection->prepare(
            'SELECT id, license_plate, make_model, body_volume, notes FROM trucks WHERE license_plate = :license_plate LIMIT 1'
        );
        $statement->execute(['license_plate' => $licensePlate]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return new Truck(
            $row['id'],
            $row['license_plate'],
            $row['make_model'],
            $row['notes'],
            isset($row['body_volume']) ? (float) $row['body_volume'] : null
        );
    }

    public function save(Truck $truck): void
    {
        $statement = $this->connection->prepare(
            'UPDATE trucks SET license_plate = :license_plate, make_model = :make_model, body_volume = :body_volume, notes = :notes, updated_at = NOW() WHERE id = :id'
        );

        $statement->execute([
            'id' => $truck->id(),
            'license_plate' => $truck->licensePlate(),
            'make_model' => $truck->makeModel(),
            'body_volume' => $truck->bodyVolume(),
            'notes' => $truck->notes(),
        ]);
    }

    public function delete(string $id): void
    {
        $statement = $this->connection->prepare('DELETE FROM trucks WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
