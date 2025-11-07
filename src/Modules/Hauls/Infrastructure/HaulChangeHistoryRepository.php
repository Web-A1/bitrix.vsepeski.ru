<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Infrastructure;

use PDO;

final class HaulChangeHistoryRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function record(
        string $haulId,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        ?int $actorId,
        ?string $actorName,
        string $actorRole
    ): void {
        $statement = $this->connection->prepare(
            <<<SQL
                INSERT INTO haul_change_events (
                    haul_id, field, old_value, new_value, changed_by_id, changed_by_name, actor_role
                ) VALUES (
                    :haul_id, :field, :old_value, :new_value, :changed_by_id, :changed_by_name, :actor_role
                )
            SQL
        );

        $statement->execute([
            'haul_id' => $haulId,
            'field' => $field,
            'old_value' => $this->normalizeValue($oldValue),
            'new_value' => $this->normalizeValue($newValue),
            'changed_by_id' => $actorId,
            'changed_by_name' => $actorName,
            'actor_role' => $actorRole,
        ]);
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
