<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\DTO;

final class HaulData
{
    public function __construct(
        public readonly int $dealId,
        public readonly ?int $responsibleId,
        public readonly string $truckId,
        public readonly string $materialId,
        public readonly int $sequence,
        public readonly string $loadAddressText,
        public readonly ?string $loadAddressUrl,
        public readonly ?int $loadFromCompanyId,
        public readonly ?int $loadToCompanyId,
        public readonly ?float $loadVolume,
        /** @var list<int|string> */ public readonly array $loadDocuments,
        public readonly string $unloadAddressText,
        public readonly ?string $unloadAddressUrl,
        public readonly ?int $unloadFromCompanyId,
        public readonly ?int $unloadToCompanyId,
        public readonly ?string $unloadContactName,
        public readonly ?string $unloadContactPhone,
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
            truckId: (string) $payload['truck_id'],
            materialId: (string) $payload['material_id'],
            sequence: $sequence,
            loadAddressText: (string) $payload['load_address_text'],
            loadAddressUrl: isset($payload['load_address_url']) ? (string) $payload['load_address_url'] : null,
            loadFromCompanyId: isset($payload['load_from_company_id']) ? (int) $payload['load_from_company_id'] : null,
            loadToCompanyId: isset($payload['load_to_company_id']) ? (int) $payload['load_to_company_id'] : null,
            loadVolume: isset($payload['load_volume']) ? (float) $payload['load_volume'] : null,
            loadDocuments: self::normalizeDocuments($payload['load_documents'] ?? []),
            unloadAddressText: (string) $payload['unload_address_text'],
            unloadAddressUrl: isset($payload['unload_address_url']) ? (string) $payload['unload_address_url'] : null,
            unloadFromCompanyId: isset($payload['unload_from_company_id']) ? (int) $payload['unload_from_company_id'] : null,
            unloadToCompanyId: isset($payload['unload_to_company_id']) ? (int) $payload['unload_to_company_id'] : null,
            unloadContactName: isset($payload['unload_contact_name']) ? (string) $payload['unload_contact_name'] : null,
            unloadContactPhone: isset($payload['unload_contact_phone']) ? (string) $payload['unload_contact_phone'] : null,
            unloadDocuments: self::normalizeDocuments($payload['unload_documents'] ?? []),
        );
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
