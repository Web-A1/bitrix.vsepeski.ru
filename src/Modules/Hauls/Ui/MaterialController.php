<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Infrastructure\MaterialRepository;
use RuntimeException;

final class MaterialController
{
    public function __construct(private readonly MaterialRepository $repository)
    {
    }

    public function index(): Response
    {
        $items = array_map(
            static fn ($material): array => [
                'id' => $material->id(),
                'name' => $material->name(),
                'description' => $material->description(),
            ],
            $this->repository->all()
        );

        return Response::json(['data' => $items]);
    }

    public function store(Request $request): Response
    {
        $payload = $request->body();

        if (!isset($payload['name']) || $payload['name'] === '') {
            return Response::json(['error' => 'Field "name" is required.'], 422);
        }

        $material = $this->repository->create(
            (string) $payload['name'],
            isset($payload['description']) ? (string) $payload['description'] : null
        );

        return Response::json([
            'data' => [
                'id' => $material->id(),
                'name' => $material->name(),
                'description' => $material->description(),
            ],
        ], 201);
    }

    public function destroy(string $materialId): Response
    {
        try {
            $this->repository->delete($materialId);
        } catch (RuntimeException) {
            return Response::json(['error' => 'Unable to delete material.'], 500);
        }

        return Response::noContent();
    }
}

