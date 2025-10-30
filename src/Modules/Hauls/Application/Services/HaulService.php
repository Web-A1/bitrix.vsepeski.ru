<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\Services;

use B24\Center\Modules\Hauls\Application\DTO\HaulData;
use B24\Center\Modules\Hauls\Application\DTO\HaulResponse;
use B24\Center\Modules\Hauls\Domain\Haul;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use DateTimeImmutable;
use RuntimeException;

final class HaulService
{
    public function __construct(private readonly HaulRepository $repository)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listByDeal(int $dealId): array
    {
        return array_map(
            static fn (Haul $haul): array => HaulResponse::fromEntity($haul),
            $this->repository->findByDeal($dealId)
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function create(int $dealId, array $payload): array
    {
        $sequence = $payload['sequence'] ?? $this->repository->nextSequence($dealId);
        $haulData = HaulData::fromArray($payload, $dealId, (int) $sequence);

        $entity = $this->repository->create([
            'deal_id' => $haulData->dealId,
            'responsible_id' => $haulData->responsibleId,
            'truck_id' => $haulData->truckId,
            'material_id' => $haulData->materialId,
            'sequence' => $haulData->sequence,
            'load_address_text' => $haulData->loadAddressText,
            'load_address_url' => $haulData->loadAddressUrl,
            'load_from_company_id' => $haulData->loadFromCompanyId,
            'load_to_company_id' => $haulData->loadToCompanyId,
            'load_volume' => $haulData->loadVolume,
            'load_documents' => $haulData->loadDocuments,
            'unload_address_text' => $haulData->unloadAddressText,
            'unload_address_url' => $haulData->unloadAddressUrl,
            'unload_from_company_id' => $haulData->unloadFromCompanyId,
            'unload_to_company_id' => $haulData->unloadToCompanyId,
            'unload_contact_name' => $haulData->unloadContactName,
            'unload_contact_phone' => $haulData->unloadContactPhone,
            'unload_documents' => $haulData->unloadDocuments,
        ]);

        return HaulResponse::fromEntity($entity);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function update(string $id, array $payload): array
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            throw new RuntimeException('Haul not found.');
        }

        $sequence = $payload['sequence'] ?? $existing->sequence();
        $haulData = HaulData::fromArray($payload, $existing->dealId(), (int) $sequence);

        $existing->assignResponsible($haulData->responsibleId);
        $existing->rewriteSequence($haulData->sequence);
        $existing->updateLoadAddress($haulData->loadAddressText, $haulData->loadAddressUrl);
        $existing->updateLoadParties($haulData->loadFromCompanyId, $haulData->loadToCompanyId);
        $existing->updateLoadVolume($haulData->loadVolume);
        $existing->replaceLoadDocuments($haulData->loadDocuments);
        $existing->updateUnloadAddress($haulData->unloadAddressText, $haulData->unloadAddressUrl);
        $existing->updateUnloadParties($haulData->unloadFromCompanyId, $haulData->unloadToCompanyId);
        $existing->updateUnloadContact($haulData->unloadContactName, $haulData->unloadContactPhone);
        $existing->replaceUnloadDocuments($haulData->unloadDocuments);
        $existing->touch(new DateTimeImmutable());

        $this->repository->save($existing);

        return HaulResponse::fromEntity($existing);
    }

    public function delete(string $id): void
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            return;
        }

        $existing->markDeleted(new DateTimeImmutable());
        $this->repository->save($existing);
    }

    public function restore(string $id): array
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            throw new RuntimeException('Haul not found.');
        }

        $existing->restore();
        $existing->touch(new DateTimeImmutable());

        $this->repository->save($existing);

        return HaulResponse::fromEntity($existing);
    }

    public function get(string $id): array
    {
        $existing = $this->repository->find($id);

        if ($existing === null || $existing->deletedAt() !== null) {
            throw new RuntimeException('Haul not found.');
        }

        return HaulResponse::fromEntity($existing);
    }
}
