<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Infrastructure\TruckRepository;
use RuntimeException;

final class TruckController
{
    public function __construct(private readonly TruckRepository $repository)
    {
    }

    public function index(): Response
    {
        $items = array_map(
            static fn ($truck): array => [
                'id' => $truck->id(),
                'license_plate' => $truck->licensePlate(),
                'make_model' => $truck->makeModel(),
                'notes' => $truck->notes(),
            ],
            $this->repository->all()
        );

        return Response::json(['data' => $items]);
    }

    public function store(Request $request): Response
    {
        $payload = $request->body();

        if (!isset($payload['license_plate']) || $payload['license_plate'] === '') {
            return Response::json(['error' => 'Field "license_plate" is required.'], 422);
        }

        $truck = $this->repository->create(
            (string) $payload['license_plate'],
            isset($payload['make_model']) ? (string) $payload['make_model'] : null,
            isset($payload['notes']) ? (string) $payload['notes'] : null
        );

        return Response::json([
            'data' => [
                'id' => $truck->id(),
                'license_plate' => $truck->licensePlate(),
                'make_model' => $truck->makeModel(),
                'notes' => $truck->notes(),
            ],
        ], 201);
    }

    public function destroy(string $truckId): Response
    {
        try {
            $this->repository->delete($truckId);
        } catch (RuntimeException) {
            return Response::json(['error' => 'Unable to delete truck.'], 500);
        }

        return Response::noContent();
    }
}
