<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\Services;

use B24\Center\Modules\Hauls\Application\DTO\HaulData;
use B24\Center\Modules\Hauls\Application\DTO\HaulResponse;
use B24\Center\Modules\Hauls\Domain\Haul;
use B24\Center\Modules\Hauls\Domain\HaulStatus;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulStatusHistoryRepository;
use DateTimeImmutable;
use RuntimeException;

final class HaulService
{
    public function __construct(
        private readonly HaulRepository $repository,
        private readonly HaulStatusHistoryRepository $historyRepository,
    )
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listByDeal(int $dealId): array
    {
        return $this->mapResponses($this->repository->findByDeal($dealId));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listByResponsible(int $responsibleId): array
    {
        return $this->mapResponses($this->repository->findByResponsible($responsibleId));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function create(int $dealId, array $payload): array
    {
        $sequence = $payload['sequence'] ?? $this->repository->nextSequence($dealId);
        $haulData = HaulData::fromArray($payload, $dealId, (int) $sequence);

        $status = HaulStatus::sanitize($haulData->status);

        $entity = $this->repository->create([
            'deal_id' => $haulData->dealId,
            'responsible_id' => $haulData->responsibleId,
            'truck_id' => $haulData->truckId,
            'material_id' => $haulData->materialId,
            'sequence' => $haulData->sequence,
            'status' => $status,
            'general_notes' => $haulData->generalNotes,
            'load_address_text' => $haulData->loadAddressText,
            'load_address_url' => $haulData->loadAddressUrl,
            'load_from_company_id' => $haulData->loadFromCompanyId,
            'load_to_company_id' => $haulData->loadToCompanyId,
            'load_volume' => $haulData->loadVolume,
            'load_actual_volume' => $haulData->loadActualVolume,
            'load_documents' => $haulData->loadDocuments,
            'unload_address_text' => $haulData->unloadAddressText,
            'unload_address_url' => $haulData->unloadAddressUrl,
            'unload_from_company_id' => $haulData->unloadFromCompanyId,
            'unload_to_company_id' => $haulData->unloadToCompanyId,
            'unload_contact_name' => $haulData->unloadContactName,
            'unload_contact_phone' => $haulData->unloadContactPhone,
            'unload_acceptance_time' => $haulData->unloadAcceptanceTime,
            'unload_documents' => $haulData->unloadDocuments,
        ]);

        $this->historyRepository->append($entity->id(), $entity->status(), null);

        return HaulResponse::fromEntity($entity, $this->historyRepository->listFor($entity->id()));
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
        $previousStatus = $existing->status();
        $nextStatus = HaulStatus::sanitize($haulData->status);
        $existing->updateStatus($nextStatus);
        $existing->updateGeneralNotes($haulData->generalNotes);
        $existing->updateLoadAddress($haulData->loadAddressText, $haulData->loadAddressUrl);
        $existing->updateLoadParties($haulData->loadFromCompanyId, $haulData->loadToCompanyId);
        $existing->updateLoadVolume($haulData->loadVolume);
        $existing->updateLoadActualVolume($haulData->loadActualVolume);
        $existing->replaceLoadDocuments($haulData->loadDocuments);
        $existing->updateUnloadAddress($haulData->unloadAddressText, $haulData->unloadAddressUrl);
        $existing->updateUnloadParties($haulData->unloadFromCompanyId, $haulData->unloadToCompanyId);
        $existing->updateUnloadContact($haulData->unloadContactName, $haulData->unloadContactPhone);
        $existing->updateUnloadAcceptanceTime($haulData->unloadAcceptanceTime);
        $existing->replaceUnloadDocuments($haulData->unloadDocuments);
        $existing->touch(new DateTimeImmutable());

        $this->repository->save($existing);

        if ($previousStatus !== $nextStatus) {
            $this->historyRepository->append($existing->id(), $nextStatus, null);
        }

        return HaulResponse::fromEntity($existing, $this->historyRepository->listFor($existing->id()));
    }

    public function delete(string $id): void
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            return;
        }

        $this->repository->delete($existing->id());
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

        return HaulResponse::fromEntity($existing, $this->historyRepository->listFor($existing->id()));
    }

    public function get(string $id): array
    {
        $existing = $this->repository->find($id);

        if ($existing === null || $existing->deletedAt() !== null) {
            throw new RuntimeException('Haul not found.');
        }

        return HaulResponse::fromEntity($existing, $this->historyRepository->listFor($existing->id()));
    }

    /**
     * @param array<string,mixed> $context
     */
    public function transitionStatus(string $haulId, int $status, ?int $actorId, string $actorRole, array $context = []): array
    {
        $haul = $this->repository->find($haulId);

        if ($haul === null || $haul->deletedAt() !== null) {
            throw new RuntimeException('Haul not found.');
        }

        $nextStatus = HaulStatus::sanitize($status);
        $currentStatus = $haul->status();

        if (!HaulStatus::canTransition($currentStatus, $nextStatus, $actorRole)) {
            throw new RuntimeException('Недопустимый переход статуса.');
        }

        if (array_key_exists('load_actual_volume', $context)) {
            $value = $context['load_actual_volume'];
            $haul->updateLoadActualVolume($value !== null && $value !== '' ? (float) $value : null);
        }

        if ($currentStatus !== $nextStatus) {
            $haul->updateStatus($nextStatus);
        }
        $haul->touch(new DateTimeImmutable());

        $this->repository->save($haul);
        if ($currentStatus !== $nextStatus) {
            $this->historyRepository->append($haul->id(), $nextStatus, $actorId);
        }

        return HaulResponse::fromEntity($haul, $this->historyRepository->listFor($haul->id()));
    }

    /**
     * @param list<Haul> $hauls
     * @return list<array<string,mixed>>
     */
    private function mapResponses(array $hauls): array
    {
        if ($hauls === []) {
            return [];
        }

        $histories = $this->historyRepository->listForMany(array_map(static fn (Haul $haul): string => $haul->id(), $hauls));

        return array_map(
            static fn (Haul $haul): array => HaulResponse::fromEntity($haul, $histories[$haul->id()] ?? []),
            $hauls
        );
    }
}
