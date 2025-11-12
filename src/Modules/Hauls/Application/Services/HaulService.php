<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\Services;

use B24\Center\Modules\Hauls\Application\DTO\ActorContext;
use B24\Center\Modules\Hauls\Application\DTO\HaulData;
use B24\Center\Modules\Hauls\Application\DTO\HaulResponse;
use B24\Center\Modules\Hauls\Domain\Haul;
use B24\Center\Modules\Hauls\Domain\HaulStatus;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulChangeHistoryRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulStatusHistoryRepository;
use DateTimeImmutable;
use RuntimeException;

final class HaulService
{
    public function __construct(
        private readonly HaulRepository $repository,
        private readonly HaulStatusHistoryRepository $historyRepository,
        private readonly HaulChangeHistoryRepository $changeHistory,
    ) {
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
    public function create(int $dealId, array $payload, ?ActorContext $actor = null): array
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
            'leg_distance_km' => $haulData->legDistanceKm,
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
        $this->recordStateChanges($entity->id(), [], $this->captureState($entity), $actor);

        return HaulResponse::fromEntity($entity, $this->historyRepository->listFor($entity->id()));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function update(string $id, array $payload, ?ActorContext $actor = null): array
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            throw new RuntimeException('Haul not found.');
        }

        $sequence = $payload['sequence'] ?? $existing->sequence();
        $haulData = HaulData::fromArray($payload, $existing->dealId(), (int) $sequence);
        $previousState = $this->captureState($existing);

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
        $existing->updateLegDistance($haulData->legDistanceKm);
        $existing->replaceLoadDocuments($haulData->loadDocuments);
        $existing->updateUnloadAddress($haulData->unloadAddressText, $haulData->unloadAddressUrl);
        $existing->updateUnloadParties($haulData->unloadFromCompanyId, $haulData->unloadToCompanyId);
        $existing->updateUnloadContact($haulData->unloadContactName, $haulData->unloadContactPhone);
        $existing->updateUnloadAcceptanceTime($haulData->unloadAcceptanceTime);
        $existing->replaceUnloadDocuments($haulData->unloadDocuments);
        $existing->touch(new DateTimeImmutable());

        $this->repository->save($existing);

        $nextState = $this->captureState($existing);
        $this->recordStateChanges($existing->id(), $previousState, $nextState, $actor);

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

    public function get(string $id): array
    {
        $existing = $this->repository->find($id);

        if ($existing === null) {
            throw new RuntimeException('Haul not found.');
        }

        return HaulResponse::fromEntity($existing, $this->historyRepository->listFor($existing->id()));
    }

    /**
     * @param array<string,mixed> $context
     */
    public function transitionStatus(string $haulId, int $status, ActorContext $actor, array $context = []): array
    {
        $haul = $this->repository->find($haulId);

        if ($haul === null) {
            throw new RuntimeException('Haul not found.');
        }

        $nextStatus = HaulStatus::sanitize($status);
        $currentStatus = $haul->status();
        $before = $this->captureState($haul);

        if (!HaulStatus::canTransition($currentStatus, $nextStatus, $actor->role)) {
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
        $after = $this->captureState($haul);
        $this->recordStateChanges($haul->id(), $before, $after, $actor);
        if ($currentStatus !== $nextStatus) {
            $this->historyRepository->append($haul->id(), $nextStatus, $actor->id);
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

    /**
     * @return array<string,mixed>
     */
    private function captureState(Haul $haul): array
    {
        return [
            'responsible_id' => $haul->responsibleId(),
            'truck_id' => $haul->truckId(),
            'material_id' => $haul->materialId(),
            'status' => $haul->status(),
            'general_notes' => $haul->generalNotes(),
            'load_address_text' => $haul->loadAddressText(),
            'load_address_url' => $haul->loadAddressUrl(),
            'load_from_company_id' => $haul->loadFromCompanyId(),
            'load_to_company_id' => $haul->loadToCompanyId(),
            'load_volume' => $haul->loadVolume(),
            'load_actual_volume' => $haul->loadActualVolume(),
            'leg_distance_km' => $haul->legDistanceKm(),
            'load_documents' => $haul->loadDocuments(),
            'unload_address_text' => $haul->unloadAddressText(),
            'unload_address_url' => $haul->unloadAddressUrl(),
            'unload_from_company_id' => $haul->unloadFromCompanyId(),
            'unload_to_company_id' => $haul->unloadToCompanyId(),
            'unload_contact_name' => $haul->unloadContactName(),
            'unload_contact_phone' => $haul->unloadContactPhone(),
            'unload_acceptance_time' => $haul->unloadAcceptanceTime(),
            'unload_documents' => $haul->unloadDocuments(),
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function recordStateChanges(string $haulId, array $before, array $after, ?ActorContext $actor): void
    {
        $diff = $this->diffStates($before, $after);
        if ($diff === []) {
            return;
        }

        $actorId = $actor?->id;
        $actorName = $actor?->name;
        $actorRole = $actor?->role ?? 'system';

        foreach ($diff as $field => $values) {
            $this->changeHistory->record(
                $haulId,
                $field,
                $values['old'],
                $values['new'],
                $actorId,
                $actorName,
                $actorRole
            );
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,array{old:mixed,new:mixed}>
     */
    private function diffStates(array $before, array $after): array
    {
        $fields = array_unique(array_merge(array_keys($before), array_keys($after)));
        $changes = [];

        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($this->valuesEqual($old, $new)) {
                continue;
            }
            $changes[$field] = ['old' => $old, 'new' => $new];
        }

        return $changes;
    }

    private function valuesEqual(mixed $first, mixed $second): bool
    {
        if (is_float($first) || is_float($second)) {
            return abs((float) $first - (float) $second) < 0.0001;
        }

        if (is_array($first) || is_array($second)) {
            return $first == $second;
        }

        return $first === $second;
    }
}
