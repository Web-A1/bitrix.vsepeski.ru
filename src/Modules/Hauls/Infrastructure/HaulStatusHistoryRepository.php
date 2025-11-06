<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Infrastructure;

use PDO;

final class HaulStatusHistoryRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function append(string $haulId, int $status, ?int $changedBy = null): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO haul_status_events (haul_id, status, changed_by) VALUES (:haul_id, :status, :changed_by)'
        );

        $statement->execute([
            'haul_id' => $haulId,
            'status' => $status,
            'changed_by' => $changedBy,
        ]);
    }

    /**
     * @return list<array{status:int,changed_by:?int,changed_at:string}>
     */
    public function listFor(string $haulId): array
    {
        $statement = $this->connection->prepare(
            'SELECT status, changed_by, changed_at FROM haul_status_events WHERE haul_id = :haul_id ORDER BY changed_at ASC, id ASC'
        );
        $statement->execute(['haul_id' => $haulId]);

        return array_map(
            static fn (array $row): array => [
                'status' => (int) $row['status'],
                'changed_by' => $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
                'changed_at' => (string) $row['changed_at'],
            ],
            $statement->fetchAll() ?: []
        );
    }

    /**
     * @param list<string> $haulIds
     * @return array<string,list<array{status:int,changed_by:?int,changed_at:string}>>
     */
    public function listForMany(array $haulIds): array
    {
        if ($haulIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($haulIds), '?'));
        $statement = $this->connection->prepare(
            sprintf(
                'SELECT haul_id, status, changed_by, changed_at FROM haul_status_events WHERE haul_id IN (%s) ORDER BY changed_at ASC, id ASC',
                $placeholders
            )
        );
        $statement->execute($haulIds);

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $key = (string) $row['haul_id'];
            $result[$key][] = [
                'status' => (int) $row['status'],
                'changed_by' => $row['changed_by'] !== null ? (int) $row['changed_by'] : null,
                'changed_at' => (string) $row['changed_at'],
            ];
        }

        return $result;
    }
}
