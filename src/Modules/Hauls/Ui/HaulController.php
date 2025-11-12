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
                    return sprintf('Field "%s" is required.', $field);
                }
            }
        }

        if (isset($payload['load_volume']) && $payload['load_volume'] !== null && !is_numeric($payload['load_volume'])) {
            return 'Field "load_volume" must be numeric.';
        }

        return null;
    }

    private function resolveActor(string $defaultRole = 'manager', ?Request $request = null): ActorContext
    {
        $resolved = $this->actorResolver->resolve($defaultRole);

        if ($request === null) {
            return $resolved;
        }

        $hasSessionActor = $resolved->id !== null
            || $resolved->name !== null
            || $resolved->role !== $defaultRole;

        if ($hasSessionActor) {
            return $resolved;
        }

        $headerRole = $request->header('x-actor-role');
        if (!$headerRole) {
            return $resolved;
        }

        $role = strtolower(trim($headerRole));
        $headerId = $request->header('x-actor-id');
        $numericId = $headerId !== null ? filter_var($headerId, FILTER_VALIDATE_INT) : false;
        $id = $numericId !== false ? (int) $numericId : null;
        $name = $request->header('x-actor-name') ?? null;

        return new ActorContext($id, $name, $role ?: $defaultRole);
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
        if ($this->isAdmin($actor) || $this->isDispatcher($actor)) {
            return true;
        }

        $responsible = $deal['responsible'] ?? null;
        $responsibleId = is_array($responsible) && isset($responsible['id'])
            ? (int) $responsible['id']
            : null;
        if ($responsibleId === null || $actor->id === null) {
            return false;
        }

        return $responsibleId === $actor->id;
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
