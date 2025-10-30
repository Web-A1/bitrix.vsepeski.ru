<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Domain;

final class Material
{
    public function __construct(
        private readonly string $id,
        private string $name,
        private ?string $description = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function describe(?string $description): void
    {
        $this->description = $description;
    }
}

