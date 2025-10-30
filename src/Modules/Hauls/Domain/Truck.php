<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Domain;

final class Truck
{
    public function __construct(
        private readonly string $id,
        private string $licensePlate,
        private ?string $makeModel = null,
        private ?string $notes = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function licensePlate(): string
    {
        return $this->licensePlate;
    }

    public function changeLicensePlate(string $licensePlate): void
    {
        $this->licensePlate = $licensePlate;
    }

    public function makeModel(): ?string
    {
        return $this->makeModel;
    }

    public function updateMakeModel(?string $makeModel): void
    {
        $this->makeModel = $makeModel;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function updateNotes(?string $notes): void
    {
        $this->notes = $notes;
    }
}

