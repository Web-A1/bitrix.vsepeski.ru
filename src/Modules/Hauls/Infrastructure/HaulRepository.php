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
     *     load_address_text:string,
     *     load_address_url?:string|null,
     *     load_from_company_id?:int|null,
     *     load_to_company_id?:int|null,
     *     load_volume?:float|null,
     *     load_documents?:array,
     *     unload_address_text:string,
     *     unload_address_url?:string|null,
     *     unload_from_company_id?:int|null,
     *     unload_to_company_id?:int|null,
     *     unload_contact_name?:string|null,
     *     unload_contact_phone?:string|null,
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
                    id, deal_id, responsible_id, truck_id, material_id, sequence,
                    load_address_text, load_address_url, load_from_company_id, load_to_company_id, load_volume, load_documents,
                    unload_address_text, unload_address_url, unload_from_company_id, unload_to_company_id,
                    unload_contact_name, unload_contact_phone, unload_documents
                ) VALUES (
                    :id, :deal_id, :responsible_id, :truck_id, :material_id, :sequence,
                    :load_address_text, :load_address_url, :load_from_company_id, :load_to_company_id, :load_volume, :load_documents,
                    :unload_address_text, :unload_address_url, :unload_from_company_id, :unload_to_company_id,
                    :unload_contact_name, :unload_contact_phone, :unload_documents
                )
                RETURNING *
            SQL
        );

        $statement->execute([
            'id' => $id,
            'deal_id' => $payload['deal_id'],
            'responsible_id' => $payload['responsible_id'] ?? null,
            'truck_id' => $payload['truck_id'],
            'material_id' => $payload['material_id'],
            'sequence' => $sequence,
            'load_address_text' => $payload['load_address_text'],
            'load_address_url' => $payload['load_address_url'] ?? null,
            'load_from_company_id' => $payload['load_from_company_id'] ?? null,
            'load_to_company_id' => $payload['load_to_company_id'] ?? null,
            'load_volume' => $payload['load_volume'] ?? null,
            'load_documents' => json_encode($payload['load_documents'] ?? [], JSON_THROW_ON_ERROR),
            'unload_address_text' => $payload['unload_address_text'],
            'unload_address_url' => $payload['unload_address_url'] ?? null,
            'unload_from_company_id' => $payload['unload_from_company_id'] ?? null,
            'unload_to_company_id' => $payload['unload_to_company_id'] ?? null,
            'unload_contact_name' => $payload['unload_contact_name'] ?? null,
            'unload_contact_phone' => $payload['unload_contact_phone'] ?? null,
            'unload_documents' => json_encode($payload['unload_documents'] ?? [], JSON_THROW_ON_ERROR),
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            throw new RuntimeException('Failed to insert haul.');
        }

        return $this->hydrate($row);
    }

    /**
     * @return Haul[]
     */
    public function findByDeal(int $dealId): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM hauls WHERE deal_id = :deal_id AND deleted_at IS NULL ORDER BY sequence'
        );
        $statement->execute(['deal_id' => $dealId]);

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
                    load_address_text = :load_address_text,
                    load_address_url = :load_address_url,
                    load_from_company_id = :load_from_company_id,
                    load_to_company_id = :load_to_company_id,
                    load_volume = :load_volume,
                    load_documents = :load_documents,
                    unload_address_text = :unload_address_text,
                    unload_address_url = :unload_address_url,
                    unload_from_company_id = :unload_from_company_id,
                    unload_to_company_id = :unload_to_company_id,
                    unload_contact_name = :unload_contact_name,
                    unload_contact_phone = :unload_contact_phone,
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
            'load_address_text' => $haul->loadAddressText(),
            'load_address_url' => $haul->loadAddressUrl(),
            'load_from_company_id' => $haul->loadFromCompanyId(),
            'load_to_company_id' => $haul->loadToCompanyId(),
            'load_volume' => $haul->loadVolume(),
            'load_documents' => json_encode($haul->loadDocuments(), JSON_THROW_ON_ERROR),
            'unload_address_text' => $haul->unloadAddressText(),
            'unload_address_url' => $haul->unloadAddressUrl(),
            'unload_from_company_id' => $haul->unloadFromCompanyId(),
            'unload_to_company_id' => $haul->unloadToCompanyId(),
            'unload_contact_name' => $haul->unloadContactName(),
            'unload_contact_phone' => $haul->unloadContactPhone(),
            'unload_documents' => json_encode($haul->unloadDocuments(), JSON_THROW_ON_ERROR),
        ]);
    }

    public function delete(string $id): void
    {
        $statement = $this->connection->prepare('UPDATE hauls SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
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
            $row['load_address_text'],
            $row['load_address_url'],
            $row['load_from_company_id'] !== null ? (int) $row['load_from_company_id'] : null,
            $row['load_to_company_id'] !== null ? (int) $row['load_to_company_id'] : null,
            $row['load_volume'] !== null ? (float) $row['load_volume'] : null,
            $this->decodeJsonColumn($row['load_documents']),
            $row['unload_address_text'],
            $row['unload_address_url'],
            $row['unload_from_company_id'] !== null ? (int) $row['unload_from_company_id'] : null,
            $row['unload_to_company_id'] !== null ? (int) $row['unload_to_company_id'] : null,
            $row['unload_contact_name'],
            $row['unload_contact_phone'],
            $this->decodeJsonColumn($row['unload_documents']),
            new DateTimeImmutable($row['created_at']),
            new DateTimeImmutable($row['updated_at']),
            $row['deleted_at'] !== null ? new DateTimeImmutable($row['deleted_at']) : null,
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
