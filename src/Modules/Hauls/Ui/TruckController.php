<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Ui;

use B24\Center\Infrastructure\Auth\ActorContextResolver;
use B24\Center\Infrastructure\Http\Request;
use B24\Center\Infrastructure\Http\Response;
use B24\Center\Modules\Hauls\Application\DTO\ActorContext;
use B24\Center\Modules\Hauls\Domain\Truck;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Infrastructure\TruckRepository;
use RuntimeException;

final class TruckController
{
    public function __construct(
        private readonly TruckRepository $repository,
        private readonly HaulRepository $hauls,
        private readonly ActorContextResolver $actorResolver,
    ) {
    }

    public function index(): Response
    {
        $items = array_map(
            fn (Truck $truck): array => $this->serializeTruck($truck),
            $this->repository->all()
        );

        return Response::json(['data' => $items]);
    }

    public function store(Request $request): Response
    {
        $actor = $this->resolveActor();
        if (!$this->isAdmin($actor)) {
            return Response::json(['error' => 'Недостаточно прав для изменения справочника самосвалов.'], 403);
        }

        $payload = $request->body();

        $licensePlate = isset($payload['license_plate'])
            ? $this->normalizeLicensePlate((string) $payload['license_plate'])
            : '';

        if ($licensePlate === '') {
            return Response::json(['error' => 'Field "license_plate" is required.'], 422);
        }

        if ($this->repository->findByLicensePlate($licensePlate) !== null) {
            return Response::json(['error' => 'Самосвал с таким госномером уже существует.'], 409);
        }

        $makeModel = isset($payload['make_model']) ? $this->nullableString($payload['make_model']) : null;
        $notes = isset($payload['notes']) ? $this->nullableString($payload['notes']) : null;

        $truck = $this->repository->create($licensePlate, $makeModel, $notes);

        return Response::json([
            'data' => $this->serializeTruck($truck),
        ], 201);
    }

    public function update(string $truckId, Request $request): Response
    {
        $actor = $this->resolveActor();
        if (!$this->isAdmin($actor)) {
            return Response::json(['error' => 'Недостаточно прав для изменения справочника самосвалов.'], 403);
        }

        $truck = $this->repository->find($truckId);

        if ($truck === null) {
            return Response::json(['error' => 'Truck not found.'], 404);
        }

        $payload = $request->body();

        $plateProvided = array_key_exists('license_plate', $payload);
        $makeProvided = array_key_exists('make_model', $payload);
        $notesProvided = array_key_exists('notes', $payload);

        if (!$plateProvided && !$makeProvided && !$notesProvided) {
            return Response::json(['data' => $this->serializeTruck($truck)]);
        }

        if ($plateProvided) {
            $nextPlate = $this->normalizeLicensePlate((string) $payload['license_plate']);
            if ($nextPlate === '') {
                return Response::json(['error' => 'Field "license_plate" must not be empty.'], 422);
            }
            if ($nextPlate !== $truck->licensePlate()) {
                $existing = $this->repository->findByLicensePlate($nextPlate);
                if ($existing !== null && $existing->id() !== $truckId) {
                    return Response::json(['error' => 'Самосвал с таким госномером уже существует.'], 409);
                }
                $truck->changeLicensePlate($nextPlate);
            }
        }

        if ($makeProvided) {
            $truck->updateMakeModel($this->nullableString($payload['make_model']));
        }

        if ($notesProvided) {
            $truck->updateNotes($this->nullableString($payload['notes']));
        }

        $this->repository->save($truck);

        return Response::json(['data' => $this->serializeTruck($truck)]);
    }

    public function destroy(string $truckId, Request $request): Response
    {
        $actor = $this->resolveActor();
        if (!$this->isAdmin($actor)) {
            return Response::json(['error' => 'Недостаточно прав для изменения справочника самосвалов.'], 403);
        }

        $truck = $this->repository->find($truckId);

        if ($truck === null) {
            return Response::json(['error' => 'Truck not found.'], 404);
        }

        $usage = $this->truckUsagePayload($truckId);

        if ($usage['count'] > 0) {
            return Response::json([
                'error' => 'Самосвал используется в рейсах и не может быть удалён.',
                'usage' => $usage,
            ], 409);
        }

        try {
            $this->repository->delete($truckId);
        } catch (RuntimeException) {
            return Response::json(['error' => 'Unable to delete truck.'], 500);
        }

        return Response::noContent();
    }

    private function serializeTruck(Truck $truck): array
    {
        return [
            'id' => $truck->id(),
            'license_plate' => $truck->licensePlate(),
            'make_model' => $truck->makeModel(),
            'notes' => $truck->notes(),
            'usage' => $this->truckUsagePayload($truck->id()),
        ];
    }

    private function truckUsagePayload(string $truckId): array
    {
        $count = $this->hauls->countUsageByTruck($truckId);

        if ($count === 0) {
            return ['count' => 0, 'samples' => []];
        }

        $samples = array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'deal_id' => (int) $row['deal_id'],
                'sequence' => (int) $row['sequence'],
            ],
            $this->hauls->listUsageByTruck($truckId)
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

    private function normalizeLicensePlate(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        $upper = mb_strtoupper($trimmed, 'UTF-8');

        return preg_replace('/\s+/u', ' ', $upper);
    }
}
