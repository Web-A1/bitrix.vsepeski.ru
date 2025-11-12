<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Auth\ActorContextResolver;
use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Application\DTO\ActorContext;
use B24\Center\Modules\Hauls\Domain\Material;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Infrastructure\MaterialRepository;
use RuntimeException;

final class MaterialController
{
    public function __construct(
        private readonly MaterialRepository $repository,
        private readonly HaulRepository $hauls,
        private readonly ActorContextResolver $actorResolver,
    ) {
    }

    public function index(): Response
    {
        $items = array_map(
            fn (Material $material): array => $this->serializeMaterial($material),
            $this->repository->all()
        );

        return Response::json(['data' => $items]);
    }

    public function store(Request $request): Response
    {
        $actor = $this->resolveActor();
        if (!$this->isAdmin($actor)) {
            return Response::json(['error' => 'Недостаточно прав для изменения справочника материалов.'], 403);
        }

        $payload = $request->body();
        $name = isset($payload['name']) ? trim((string) $payload['name']) : '';

        if ($name === '') {
            return Response::json(['error' => 'Field "name" is required.'], 422);
        }

        $description = isset($payload['description']) ? $this->nullableString($payload['description']) : null;

        $material = $this->repository->create($name, $description);

        return Response::json([
            'data' => $this->serializeMaterial($material),
        ], 201);
    }

    public function update(string $materialId, Request $request): Response
    {
        $actor = $this->resolveActor();
        if (!$this->isAdmin($actor)) {
            return Response::json(['error' => 'Недостаточно прав для изменения справочника материалов.'], 403);
        }

        $material = $this->repository->find($materialId);

        if ($material === null) {
            return Response::json(['error' => 'Material not found.'], 404);
        }

        $payload = $request->body();

        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;
        $description = array_key_exists('description', $payload)
            ? $this->nullableString($payload['description'])
            : null;

        if (($name === null || $name === $material->name()) && $description === null) {
            return Response::json(['data' => $this->serializeMaterial($material)]);
        }

        if ($name !== null) {
            if ($name === '') {
                return Response::json(['error' => 'Field "name" must not be empty.'], 422);
            }
            $material->rename($name);
        }

        if ($description !== null || array_key_exists('description', $payload)) {
            $material->describe($description);
        }

        $this->repository->save($material);

        return Response::json(['data' => $this->serializeMaterial($material)]);
    }

    public function destroy(string $materialId, Request $request): Response
    {
        $actor = $this->resolveActor();
        if (!$this->isAdmin($actor)) {
            return Response::json(['error' => 'Недостаточно прав для изменения справочника материалов.'], 403);
        }

        $material = $this->repository->find($materialId);

        if ($material === null) {
            return Response::json(['error' => 'Material not found.'], 404);
        }

        $usage = $this->materialUsagePayload($materialId);

        if ($usage['count'] > 0) {
            return Response::json([
                'error' => 'Материал используется в рейсах и не может быть удалён.',
                'usage' => $usage,
            ], 409);
        }

        try {
            $this->repository->delete($materialId);
        } catch (RuntimeException) {
            return Response::json(['error' => 'Unable to delete material.'], 500);
        }

        return Response::noContent();
    }

    private function serializeMaterial(Material $material): array
    {
        return [
            'id' => $material->id(),
            'name' => $material->name(),
            'description' => $material->description(),
            'usage' => $this->materialUsagePayload($material->id()),
        ];
    }

    private function materialUsagePayload(string $materialId): array
    {
        $count = $this->hauls->countUsageByMaterial($materialId);

        if ($count === 0) {
            return ['count' => 0, 'samples' => []];
        }

        $samples = array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'deal_id' => (int) $row['deal_id'],
                'sequence' => (int) $row['sequence'],
            ],
            $this->hauls->listUsageByMaterial($materialId)
        );

        return [
            'count' => $count,
            'samples' => $samples,
        ];
    }

    private function resolveActor(): ActorContext
    {
        return $this->actorResolver->resolve('manager');
    }

    private function isAdmin(ActorContext $actor): bool
    {
        return strtolower($actor->role) === 'admin';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
