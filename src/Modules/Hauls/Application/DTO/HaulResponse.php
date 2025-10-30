<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\DTO;

use B24\Center\Modules\Hauls\Domain\Haul;

final class HaulResponse
{
    /**
     * @return array<string,mixed>
     */
    public static function fromEntity(Haul $haul): array
    {
        return [
            'id' => $haul->id(),
            'deal_id' => $haul->dealId(),
            'responsible_id' => $haul->responsibleId(),
            'truck_id' => $haul->truckId(),
            'material_id' => $haul->materialId(),
            'sequence' => $haul->sequence(),
            'load' => [
                'address_text' => $haul->loadAddressText(),
                'address_url' => $haul->loadAddressUrl(),
                'from_company_id' => $haul->loadFromCompanyId(),
                'to_company_id' => $haul->loadToCompanyId(),
                'volume' => $haul->loadVolume(),
                'documents' => $haul->loadDocuments(),
            ],
            'unload' => [
                'address_text' => $haul->unloadAddressText(),
                'address_url' => $haul->unloadAddressUrl(),
                'from_company_id' => $haul->unloadFromCompanyId(),
                'to_company_id' => $haul->unloadToCompanyId(),
                'contact_name' => $haul->unloadContactName(),
                'contact_phone' => $haul->unloadContactPhone(),
                'documents' => $haul->unloadDocuments(),
            ],
            'created_at' => $haul->createdAt()->format('c'),
            'updated_at' => $haul->updatedAt()->format('c'),
            'deleted_at' => $haul->deletedAt()?->format('c'),
        ];
    }
}
