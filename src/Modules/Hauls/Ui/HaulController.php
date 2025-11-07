<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Application\DTO\ActorContext;
use B24\Center\Modules\Hauls\Application\Services\HaulService;
use RuntimeException;

final class HaulController
{
    public function __construct(private readonly HaulService $service)
    {
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
        $validation = $this->validatePayload($payload);

        if ($validation !== null) {
            return Response::json(['error' => $validation], 422);
        }

        $actor = $this->resolveActor($request);
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

        $actor = $this->resolveActor($request);

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
        $contextActor = $actor ?? $this->resolveActor($request);

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
        $this->service->delete($haulId);

        return Response::noContent();
    }

    private function validatePayload(array $payload, bool $requireAll = true): ?string
    {
        $required = ['truck_id', 'material_id', 'load_address_text', 'unload_address_text'];

        if ($requireAll) {
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

    private function resolveActor(Request $request, string $defaultRole = 'manager'): ActorContext
    {
        $idHeader = $request->header('x-actor-id');
        $nameHeader = $request->header('x-actor-name');
        $roleHeader = $request->header('x-actor-role') ?? $defaultRole;

        $id = null;
        if ($idHeader !== null && $idHeader !== '') {
            $numeric = filter_var($idHeader, FILTER_VALIDATE_INT);
            if ($numeric !== false) {
                $id = (int) $numeric;
            }
        }

        $name = $nameHeader !== null && $nameHeader !== '' ? $nameHeader : null;
        $role = $roleHeader !== '' ? $roleHeader : $defaultRole;

        return new ActorContext($id, $name, $role);
    }
}
