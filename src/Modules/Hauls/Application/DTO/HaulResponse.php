<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\DTO;

use B24\Center\Modules\Hauls\Domain\Haul;
use B24\Center\Modules\Hauls\Domain\HaulStatus;

final class HaulResponse
{
    /**
     * @param list<array{status:int,changed_by:?int,changed_at:string}> $history
     * @return array<string,mixed>
     */
    public static function fromEntity(Haul $haul, array $history = []): array
    {
        return [
            'id' => $haul->id(),
            'deal_id' => $haul->dealId(),
            'responsible_id' => $haul->responsibleId(),
            'truck_id' => $haul->truckId(),
            'material_id' => $haul->materialId(),
            'sequence' => $haul->sequence(),
            'status' => [
                'value' => $haul->status(),
                'label' => HaulStatus::label($haul->status()),
            ],
            'general_notes' => $haul->generalNotes(),
            'load' => [
                'address_text' => $haul->loadAddressText(),
                'address_url' => $haul->loadAddressUrl(),
                'from_company_id' => $haul->loadFromCompanyId(),
                'to_company_id' => $haul->loadToCompanyId(),
                'volume' => $haul->loadVolume(),
                'actual_volume' => $haul->loadActualVolume(),
                'documents' => $haul->loadDocuments(),
            ],
            'unload' => [
                'address_text' => $haul->unloadAddressText(),
                'address_url' => $haul->unloadAddressUrl(),
                'from_company_id' => $haul->unloadFromCompanyId(),
                'to_company_id' => $haul->unloadToCompanyId(),
                'contact_name' => $haul->unloadContactName(),
                'contact_phone' => $haul->unloadContactPhone(),
                'acceptance_time' => $haul->unloadAcceptanceTime(),
                'documents' => $haul->unloadDocuments(),
            ],
            'created_at' => $haul->createdAt()->format('c'),
            'updated_at' => $haul->updatedAt()->format('c'),
            'deleted_at' => $haul->deletedAt()?->format('c'),
            'status_history' => array_map(
                static fn (array $event): array => [
                    'status' => [
                        'value' => $event['status'],
                        'label' => HaulStatus::label($event['status']),
                    ],
                    'changed_by' => $event['changed_by'],
                    'changed_at' => $event['changed_at'],
                ],
                $history
            ),
        ];
    }
}
