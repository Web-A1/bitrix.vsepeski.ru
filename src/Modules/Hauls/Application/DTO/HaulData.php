<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\DTO;

final class HaulData
{
    public function __construct(
        public readonly int $dealId,
        public readonly ?int $responsibleId,
        public readonly ?string $truckId,
        public readonly ?string $materialId,
        public readonly int $sequence,
        public readonly int $status,
        public readonly ?string $generalNotes,
        public readonly ?string $loadAddressText,
        public readonly ?string $loadAddressUrl,
        public readonly ?int $loadFromCompanyId,
        public readonly ?int $loadToCompanyId,
        public readonly ?float $loadVolume,
        public readonly ?float $loadActualVolume,
        public readonly ?float $legDistanceKm,
        /** @var list<int|string> */ public readonly array $loadDocuments,
        public readonly ?string $unloadAddressText,
        public readonly ?string $unloadAddressUrl,
        public readonly ?int $unloadFromCompanyId,
        public readonly ?int $unloadToCompanyId,
        public readonly ?string $unloadContactName,
        public readonly ?string $unloadContactPhone,
        public readonly ?string $unloadAcceptanceTime,
        /** @var list<int|string> */ public readonly array $unloadDocuments,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload, int $dealId, int $sequence): self
    {
        return new self(
            dealId: $dealId,
            responsibleId: isset($payload['responsible_id']) ? (int) $payload['responsible_id'] : null,
            truckId: self::nullableString($payload['truck_id'] ?? null),
            materialId: self::nullableString($payload['material_id'] ?? null),
            sequence: $sequence,
            status: isset($payload['status']) ? (int) $payload['status'] : 0,
            generalNotes: isset($payload['general_notes']) ? (string) $payload['general_notes'] : null,
            loadAddressText: self::nullableString($payload['load_address_text'] ?? null),
            loadAddressUrl: isset($payload['load_address_url']) ? (string) $payload['load_address_url'] : null,
            loadFromCompanyId: isset($payload['load_from_company_id']) ? (int) $payload['load_from_company_id'] : null,
            loadToCompanyId: isset($payload['load_to_company_id']) ? (int) $payload['load_to_company_id'] : null,
            loadVolume: isset($payload['load_volume']) ? (float) $payload['load_volume'] : null,
            loadActualVolume: isset($payload['load_actual_volume']) ? (float) $payload['load_actual_volume'] : null,
            legDistanceKm: isset($payload['leg_distance_km']) ? (float) $payload['leg_distance_km'] : null,
            loadDocuments: self::normalizeDocuments($payload['load_documents'] ?? []),
            unloadAddressText: self::nullableString($payload['unload_address_text'] ?? null),
            unloadAddressUrl: isset($payload['unload_address_url']) ? (string) $payload['unload_address_url'] : null,
            unloadFromCompanyId: isset($payload['unload_from_company_id']) ? (int) $payload['unload_from_company_id'] : null,
            unloadToCompanyId: isset($payload['unload_to_company_id']) ? (int) $payload['unload_to_company_id'] : null,
            unloadContactName: isset($payload['unload_contact_name']) ? (string) $payload['unload_contact_name'] : null,
            unloadContactPhone: isset($payload['unload_contact_phone']) ? (string) $payload['unload_contact_phone'] : null,
            unloadAcceptanceTime: isset($payload['unload_acceptance_time']) ? (string) $payload['unload_acceptance_time'] : null,
            unloadDocuments: self::normalizeDocuments($payload['unload_documents'] ?? []),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param mixed $documents
     * @return list<int|string>
     */
    private static function normalizeDocuments(mixed $documents): array
    {
        if (!is_array($documents)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($value) => is_numeric($value) ? (int) $value : (string) $value, $documents),
            static fn ($value) => $value !== ''
        ));
    }
}
