<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Auth\ActorContextResolver;
use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Application\DTO\ActorContext;
use B24\Center\Modules\Hauls\Application\Services\HaulService;
use B24\Center\Modules\Hauls\Application\Services\DealInfoService;
use B24\Center\Modules\Hauls\Domain\HaulStatus;
use DateTimeImmutable;
use RuntimeException;

final class HaulController
{
    private const DISPATCHER_IDS = [22];

    /** @var array<int,array<string,mixed>> */
    private array $dealCache = [];

    public function __construct(
        private readonly HaulService $service,
        private readonly ActorContextResolver $actorResolver,
        private readonly DealInfoService $dealInfoService,
    ) {
    }

    public function index(int $dealId): Response
    {
        $items = $this->service->listByDeal($dealId);

        return Response::json(['data' => $items]);
    }

    public function myHauls(int $responsibleId): Response
    {
        $items = $this->service->listByResponsible($responsibleId);

        return Response::json(['data' => $items]);
    }

    public function show(string $haulId): Response
    {
        try {
            $item = $this->service->get($haulId);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 404);
        }

        return Response::json(['data' => $item]);
    }

    public function store(int $dealId, Request $request): Response
    {
        $payload = $request->body();
        $validation = $this->validatePayload($payload, false);

        if ($validation !== null) {
            return Response::json(['error' => $validation], 422);
        }

        try {
            $deal = $this->loadDeal($dealId);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }

        $status = $this->extractStatus($payload, HaulStatus::PREPARATION);
        $materialRequired = $status > HaulStatus::PREPARATION;
        $materialCheck = $this->validateMaterialSelection($payload['material_id'] ?? null, $deal, $materialRequired);
        if ($materialCheck !== null) {
            return Response::json(['error' => $materialCheck], 422);
        }

        $actor = $this->resolveActor('manager', $request);
        if (!$this->canManageDeal($deal, $actor)) {
            $this->logAccessDenied('store', $actor, ['deal_id' => $dealId]);
            return Response::json(['error' => 'Недостаточно прав для создания рейсов.'], 403);
        }
        $created = $this->service->create($dealId, $payload, $actor);

        return Response::json(['data' => $created], 201);
    }

    public function update(string $haulId, Request $request): Response
    {
        $payload = $request->body();
        $validation = $this->validatePayload($payload, false);

        if ($validation !== null) {
            return Response::json(['error' => $validation], 422);
        }

        $actor = $this->resolveActor('manager', $request);

        try {
            $existing = $this->service->get($haulId);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 404);
        }

        try {
            $deal = $this->loadDeal((int) $existing['deal_id']);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }

        if (array_key_exists('material_id', $payload)) {
            $status = $this->extractStatus($payload, (int) ($existing['status'] ?? HaulStatus::PREPARATION));
            $materialRequired = $status > HaulStatus::PREPARATION;
            $materialCheck = $this->validateMaterialSelection($payload['material_id'], $deal, $materialRequired);
            if ($materialCheck !== null) {
                return Response::json(['error' => $materialCheck], 422);
            }
        }

        if (!$this->canManageDeal($deal, $actor)) {
            $this->logAccessDenied('update', $actor, ['deal_id' => $existing['deal_id'] ?? null, 'haul_id' => $haulId]);
            return Response::json(['error' => 'Недостаточно прав для изменения рейса.'], 403);
        }

        try {
            $updated = $this->service->update($haulId, $payload, $actor);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 404);
        }

        return Response::json(['data' => $updated]);
    }

    public function transitionStatus(string $haulId, Request $request, ?ActorContext $actor = null): Response
    {
        $payload = $request->body();
        if (!isset($payload['status'])) {
            return Response::json(['error' => 'Поле status обязательно.'], 422);
        }
        $contextActor = $actor ?? $this->resolveActor('manager', $request);

        if ($actor === null) {
            try {
                $existing = $this->service->get($haulId);
            } catch (RuntimeException $exception) {
                return Response::json(['error' => $exception->getMessage()], 404);
            }

            try {
                $deal = $this->loadDeal((int) $existing['deal_id']);
            } catch (RuntimeException $exception) {
                return Response::json(['error' => $exception->getMessage()], 422);
            }

            if (!$this->canManageDeal($deal, $contextActor)) {
                $this->logAccessDenied('transition', $contextActor, ['deal_id' => $existing['deal_id'] ?? null, 'haul_id' => $haulId]);
                return Response::json(['error' => 'Недостаточно прав для изменения рейса.'], 403);
            }
        }

        try {
            $updated = $this->service->transitionStatus(
                $haulId,
                (int) $payload['status'],
                $contextActor,
                $payload
            );
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }

        return Response::json(['data' => $updated]);
    }

    public function destroy(string $haulId): Response
    {
        $actor = $this->resolveActor('manager', $request);

        try {
            $existing = $this->service->get($haulId);
        } catch (RuntimeException $exception) {
            // Нечего удалять
            return Response::noContent();
        }

        try {
            $deal = $this->loadDeal((int) $existing['deal_id']);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }

        if (!$this->canManageDeal($deal, $actor)) {
            $this->logAccessDenied('destroy', $actor, ['deal_id' => $existing['deal_id'] ?? null, 'haul_id' => $haulId]);
            return Response::json(['error' => 'Недостаточно прав для удаления рейса.'], 403);
        }

        $this->service->delete($haulId, $actor);

        return Response::noContent();
    }

    private function validatePayload(array $payload, bool $forceRequired = true): ?string
    {
        $required = ['truck_id', 'material_id', 'load_address_text', 'unload_address_text'];
        $statusValue = isset($payload['status'])
            ? HaulStatus::sanitize((int) $payload['status'])
            : HaulStatus::PREPARATION;
        $shouldRequire = $forceRequired || $statusValue > HaulStatus::PREPARATION;

        if ($shouldRequire) {
            foreach ($required as $field) {
                if (!isset($payload[$field]) || $payload[$field] === '') {
                    return match ($field) {
                        'truck_id' => 'Выберите самосвал.',
                        'material_id' => 'Выберите материал.',
                        'load_address_text' => 'Укажите адрес загрузки.',
                        'unload_address_text' => 'Укажите адрес выгрузки.',
                        default => 'Заполните обязательные поля.',
                    };
                }
            }
        }

        if (isset($payload['load_volume']) && $payload['load_volume'] !== null && !is_numeric($payload['load_volume'])) {
            return 'Поле "Объём" должно содержать число.';
        }

        return null;
    }

    private function resolveActor(string $defaultRole = 'manager', ?Request $request = null): ActorContext
    {
        unset($request);

        return $this->actorResolver->resolve($defaultRole);
    }

    private function isAdmin(ActorContext $actor): bool
    {
        return strtolower($actor->role) === 'admin';
    }

    /**
     * @param array<string,mixed> $deal
     */
    private function canManageDeal(array $deal, ActorContext $actor): bool
    {
        unset($deal, $actor);

        // На текущем этапе доступ к изменениям открыт для всех авторизованных пользователей.
        return true;
    }

    private function isDispatcher(ActorContext $actor): bool
    {
        if ($actor->id !== null && in_array($actor->id, self::DISPATCHER_IDS, true)) {
            return true;
        }

        return strtolower($actor->role) === 'dispatcher';
    }

    private function loadDeal(int $dealId): array
    {
        if (isset($this->dealCache[$dealId])) {
            return $this->dealCache[$dealId];
        }

        $deal = $this->dealInfoService->get($dealId);
        $this->dealCache[$dealId] = $deal;

        return $deal;
    }

    private function validateMaterialSelection(mixed $value, array $deal, bool $required): ?string
    {
        $materialId = $value === null ? '' : trim((string) $value);

        if ($materialId === '') {
            return $required ? 'Поле "Материал" обязательно для сохранения рейса.' : null;
        }

        $allowed = $this->allowedMaterialIds($deal);

        if ($allowed === []) {
            return 'В сделке не выбраны материалы. Укажите материалы в сделке и попробуйте снова.';
        }

        if (!in_array($materialId, $allowed, true)) {
            return 'Материал должен быть выбран в сделке.';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $deal
     * @return list<string>
     */
    private function allowedMaterialIds(array $deal): array
    {
        $materials = $deal['materials'] ?? null;
        if (!is_array($materials)) {
            return [];
        }

        $selected = $materials['selected_ids']
            ?? $materials['selected']
            ?? null;

        if (!is_array($selected)) {
            return [];
        }

        $normalized = [];
        foreach ($selected as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $normalized[] = (string) $item;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractStatus(array $payload, ?int $fallback = null): int
    {
        $raw = $payload['status'] ?? $payload['STATUS'] ?? null;
        if (is_numeric($raw)) {
            return HaulStatus::sanitize((int) $raw);
        }

        if ($fallback !== null) {
            return HaulStatus::sanitize($fallback);
        }

        return HaulStatus::PREPARATION;
    }

    private function logAccessDenied(string $action, ActorContext $actor, array $context = []): void
    {
        $entry = [
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'action' => $action,
            'actor' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
            ],
            'context' => $context,
        ];

        $root = dirname(__DIR__, 4);
        $path = $root . '/storage/logs/hauls-access.log';
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;

        @file_put_contents($path, $line, FILE_APPEND);
    }
}
