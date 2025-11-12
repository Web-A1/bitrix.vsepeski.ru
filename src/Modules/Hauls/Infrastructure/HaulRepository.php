<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Infrastructure;

use B24\Center\Modules\Hauls\Domain\Haul;
use B24\Center\Support\Uuid;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class HaulRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @param array{
     *     deal_id:int,
     *     responsible_id?:int|null,
     *     truck_id:string,
     *     material_id:string,
     *     sequence?:int,
     *     status?:int,
     *     load_address_text:string,
     *     load_address_url?:string|null,
     *     load_from_company_id?:int|null,
     *     load_to_company_id?:int|null,
     *     load_volume?:float|null,
     *     load_actual_volume?:float|null,
     *     load_documents?:array,
     *     leg_distance_km?:float|null,
     *     general_notes?:string|null,
     *     unload_address_text:string,
     *     unload_address_url?:string|null,
     *     unload_from_company_id?:int|null,
     *     unload_to_company_id?:int|null,
     *     unload_contact_name?:string|null,
     *     unload_contact_phone?:string|null,
     *     unload_acceptance_time?:string|null,
     *     unload_documents?:array
     * } $payload
     */
    public function create(array $payload): Haul
    {
        $id = Uuid::v4();
        $sequence = $payload['sequence'] ?? $this->nextSequence($payload['deal_id']);

        $statement = $this->connection->prepare(
            <<<SQL
                INSERT INTO hauls (
                    id, deal_id, responsible_id, truck_id, material_id, sequence, status, general_notes,
                    load_address_text, load_address_url, load_from_company_id, load_to_company_id, load_volume, load_actual_volume, leg_distance_km, load_documents,
                    unload_address_text, unload_address_url, unload_from_company_id, unload_to_company_id,
                    unload_contact_name, unload_contact_phone, unload_acceptance_time, unload_documents
                ) VALUES (
                    :id, :deal_id, :responsible_id, :truck_id, :material_id, :sequence, :status, :general_notes,
                    :load_address_text, :load_address_url, :load_from_company_id, :load_to_company_id, :load_volume, :load_actual_volume, :leg_distance_km, :load_documents,
                    :unload_address_text, :unload_address_url, :unload_from_company_id, :unload_to_company_id,
                    :unload_contact_name, :unload_contact_phone, :unload_acceptance_time, :unload_documents
                )
            SQL
        );

        $statement->execute([
            'id' => $id,
            'deal_id' => $payload['deal_id'],
            'responsible_id' => $payload['responsible_id'] ?? null,
            'truck_id' => $payload['truck_id'],
            'material_id' => $payload['material_id'],
            'sequence' => $sequence,
            'status' => $payload['status'] ?? 0,
            'general_notes' => $payload['general_notes'] ?? null,
            'load_address_text' => $payload['load_address_text'],
            'load_address_url' => $payload['load_address_url'] ?? null,
            'load_from_company_id' => $payload['load_from_company_id'] ?? null,
            'load_to_company_id' => $payload['load_to_company_id'] ?? null,
            'load_volume' => $payload['load_volume'] ?? null,
            'load_actual_volume' => $payload['load_actual_volume'] ?? null,
            'leg_distance_km' => $payload['leg_distance_km'] ?? null,
            'load_documents' => json_encode($payload['load_documents'] ?? [], JSON_THROW_ON_ERROR),
            'unload_address_text' => $payload['unload_address_text'],
            'unload_address_url' => $payload['unload_address_url'] ?? null,
            'unload_from_company_id' => $payload['unload_from_company_id'] ?? null,
            'unload_to_company_id' => $payload['unload_to_company_id'] ?? null,
            'unload_contact_name' => $payload['unload_contact_name'] ?? null,
            'unload_contact_phone' => $payload['unload_contact_phone'] ?? null,
            'unload_acceptance_time' => $payload['unload_acceptance_time'] ?? null,
            'unload_documents' => json_encode($payload['unload_documents'] ?? [], JSON_THROW_ON_ERROR),
        ]);

        $entity = $this->find($id);

        if ($entity === null) {
            throw new RuntimeException('Failed to insert haul.');
        }

        return $entity;
    }

    /**
     * @return Haul[]
     */
    public function findByDeal(int $dealId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM hauls WHERE deal_id = :deal_id ORDER BY sequence'
        );
        $statement->execute(['deal_id' => $dealId]);

        return array_map(fn (array $row): Haul => $this->hydrate($row), $statement->fetchAll());
    }

    /**
     * @return Haul[]
     */
    public function findByResponsible(int $responsibleId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM hauls WHERE responsible_id = :responsible_id ORDER BY updated_at DESC, created_at DESC'
        );
        $statement->execute(['responsible_id' => $responsibleId]);

        return array_map(fn (array $row): Haul => $this->hydrate($row), $statement->fetchAll());
    }

    public function find(string $id): ?Haul
    {
        $statement = $this->connection->prepare('SELECT * FROM hauls WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function save(Haul $haul): void
    {
        $statement = $this->connection->prepare(
            <<<SQL
                UPDATE hauls SET
                    responsible_id = :responsible_id,
                    truck_id = :truck_id,
                    material_id = :material_id,
                    sequence = :sequence,
                    status = :status,
                    general_notes = :general_notes,
                    load_address_text = :load_address_text,
                    load_address_url = :load_address_url,
                    load_from_company_id = :load_from_company_id,
                    load_to_company_id = :load_to_company_id,
                    load_volume = :load_volume,
                    load_actual_volume = :load_actual_volume,
                    leg_distance_km = :leg_distance_km,
                    load_documents = :load_documents,
                    unload_address_text = :unload_address_text,
                    unload_address_url = :unload_address_url,
                    unload_from_company_id = :unload_from_company_id,
                    unload_to_company_id = :unload_to_company_id,
                    unload_contact_name = :unload_contact_name,
                    unload_contact_phone = :unload_contact_phone,
                    unload_acceptance_time = :unload_acceptance_time,
                    unload_documents = :unload_documents,
                    updated_at = NOW()
                WHERE id = :id
            SQL
        );

        $statement->execute([
            'id' => $haul->id(),
            'responsible_id' => $haul->responsibleId(),
            'truck_id' => $haul->truckId(),
            'material_id' => $haul->materialId(),
            'sequence' => $haul->sequence(),
            'status' => $haul->status(),
            'general_notes' => $haul->generalNotes(),
            'load_address_text' => $haul->loadAddressText(),
            'load_address_url' => $haul->loadAddressUrl(),
            'load_from_company_id' => $haul->loadFromCompanyId(),
            'load_to_company_id' => $haul->loadToCompanyId(),
            'load_volume' => $haul->loadVolume(),
            'load_actual_volume' => $haul->loadActualVolume(),
            'leg_distance_km' => $haul->legDistanceKm(),
            'load_documents' => json_encode($haul->loadDocuments(), JSON_THROW_ON_ERROR),
            'unload_address_text' => $haul->unloadAddressText(),
            'unload_address_url' => $haul->unloadAddressUrl(),
            'unload_from_company_id' => $haul->unloadFromCompanyId(),
            'unload_to_company_id' => $haul->unloadToCompanyId(),
            'unload_contact_name' => $haul->unloadContactName(),
            'unload_contact_phone' => $haul->unloadContactPhone(),
            'unload_acceptance_time' => $haul->unloadAcceptanceTime(),
            'unload_documents' => json_encode($haul->unloadDocuments(), JSON_THROW_ON_ERROR),
        ]);
    }

    public function delete(string $id): void
    {
        $statement = $this->connection->prepare('DELETE FROM hauls WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function nextSequence(int $dealId): int
    {
        $statement = $this->connection->prepare('SELECT COALESCE(MAX(sequence), 0) + 1 as next_sequence FROM hauls WHERE deal_id = :deal_id');
        $statement->execute(['deal_id' => $dealId]);

        $row = $statement->fetch();

        if ($row === false) {
            return 1;
        }

        return (int) $row['next_sequence'];
    }

    public function countUsageByMaterial(string $materialId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM hauls WHERE material_id = :material_id'
        );
        $statement->execute(['material_id' => $materialId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<int,array{id:string,deal_id:int,sequence:int}>
     */
    public function listUsageByMaterial(string $materialId, int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, deal_id, sequence FROM hauls WHERE material_id = :material_id ORDER BY updated_at DESC LIMIT :limit'
        );
        $statement->bindValue(':material_id', $materialId);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countUsageByTruck(string $truckId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM hauls WHERE truck_id = :truck_id'
        );
        $statement->execute(['truck_id' => $truckId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<int,array{id:string,deal_id:int,sequence:int}>
     */
    public function listUsageByTruck(string $truckId, int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, deal_id, sequence FROM hauls WHERE truck_id = :truck_id ORDER BY updated_at DESC LIMIT :limit'
        );
        $statement->bindValue(':truck_id', $truckId);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): Haul
    {
        return new Haul(
            $row['id'],
            (int) $row['deal_id'],
            $row['responsible_id'] !== null ? (int) $row['responsible_id'] : null,
            $row['truck_id'],
            $row['material_id'],
            (int) $row['sequence'],
            (int) $row['status'],
            $row['general_notes'],
            $row['load_address_text'],
            $row['load_address_url'],
            $row['load_from_company_id'] !== null ? (int) $row['load_from_company_id'] : null,
            $row['load_to_company_id'] !== null ? (int) $row['load_to_company_id'] : null,
            $row['load_volume'] !== null ? (float) $row['load_volume'] : null,
            $row['load_actual_volume'] !== null ? (float) $row['load_actual_volume'] : null,
            $row['leg_distance_km'] !== null ? (float) $row['leg_distance_km'] : null,
            $this->decodeJsonColumn($row['load_documents']),
            $row['unload_address_text'],
            $row['unload_address_url'],
            $row['unload_from_company_id'] !== null ? (int) $row['unload_from_company_id'] : null,
            $row['unload_to_company_id'] !== null ? (int) $row['unload_to_company_id'] : null,
            $row['unload_contact_name'],
            $row['unload_contact_phone'],
            $row['unload_acceptance_time'],
            $this->decodeJsonColumn($row['unload_documents']),
            new DateTimeImmutable($row['created_at']),
            new DateTimeImmutable($row['updated_at']),
        );
    }

    /**
     * @return array<int,int|string>
     */
    private function decodeJsonColumn(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
