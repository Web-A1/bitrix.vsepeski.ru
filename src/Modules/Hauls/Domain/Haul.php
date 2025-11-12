<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Domain;

use DateTimeImmutable;

final class Haul
{
    /**
     * @param int[]|string[] $loadDocuments
     * @param int[]|string[] $unloadDocuments
     */
    public function __construct(
        private readonly string $id,
        private readonly int $dealId,
        private ?int $responsibleId,
        private ?string $truckId,
        private ?string $materialId,
        private int $sequence,
        private int $status,
        private ?string $generalNotes,
        private ?string $loadAddressText,
        private ?string $loadAddressUrl,
        private ?int $loadFromCompanyId,
        private ?int $loadToCompanyId,
        private ?float $loadVolume,
        private ?float $loadActualVolume,
        private ?float $legDistanceKm,
        private array $loadDocuments,
        private ?string $unloadAddressText,
        private ?string $unloadAddressUrl,
        private ?int $unloadFromCompanyId,
        private ?int $unloadToCompanyId,
        private ?string $unloadContactName,
        private ?string $unloadContactPhone,
        private ?string $unloadAcceptanceTime,
        private array $unloadDocuments,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function dealId(): int
    {
        return $this->dealId;
    }

    public function responsibleId(): ?int
    {
        return $this->responsibleId;
    }

    public function assignResponsible(?int $responsibleId): void
    {
        $this->responsibleId = $responsibleId;
    }

    public function truckId(): ?string
    {
        return $this->truckId;
    }

    public function assignTruck(?string $truckId): void
    {
        $this->truckId = $truckId;
    }

    public function materialId(): ?string
    {
        return $this->materialId;
    }

    public function assignMaterial(?string $materialId): void
    {
        $this->materialId = $materialId;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function rewriteSequence(int $sequence): void
    {
        $this->sequence = $sequence;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function updateStatus(int $status): void
    {
        $this->status = $status;
    }

    public function generalNotes(): ?string
    {
        return $this->generalNotes;
    }

    public function updateGeneralNotes(?string $notes): void
    {
        $this->generalNotes = $notes;
    }

    public function loadAddressText(): ?string
    {
        return $this->loadAddressText;
    }

    public function loadAddressUrl(): ?string
    {
        return $this->loadAddressUrl;
    }

    public function updateLoadAddress(?string $text, ?string $url): void
    {
        $this->loadAddressText = $text;
        $this->loadAddressUrl = $url;
    }

    public function loadFromCompanyId(): ?int
    {
        return $this->loadFromCompanyId;
    }

    public function loadToCompanyId(): ?int
    {
        return $this->loadToCompanyId;
    }

    public function updateLoadParties(?int $fromCompanyId, ?int $toCompanyId): void
    {
        $this->loadFromCompanyId = $fromCompanyId;
        $this->loadToCompanyId = $toCompanyId;
    }

    public function loadVolume(): ?float
    {
        return $this->loadVolume;
    }

    public function updateLoadVolume(?float $volume): void
    {
        $this->loadVolume = $volume;
    }

    public function loadActualVolume(): ?float
    {
        return $this->loadActualVolume;
    }

    public function updateLoadActualVolume(?float $volume): void
    {
        $this->loadActualVolume = $volume;
    }

    public function legDistanceKm(): ?float
    {
        return $this->legDistanceKm;
    }

    public function updateLegDistance(?float $distance): void
    {
        $this->legDistanceKm = $distance;
    }

    /**
     * @return int[]|string[]
     */
    public function loadDocuments(): array
    {
        return $this->loadDocuments;
    }

    /**
     * @param int[]|string[] $documents
     */
    public function replaceLoadDocuments(array $documents): void
    {
        $this->loadDocuments = $documents;
    }

    public function unloadAddressText(): ?string
    {
        return $this->unloadAddressText;
    }

    public function unloadAddressUrl(): ?string
    {
        return $this->unloadAddressUrl;
    }

    public function updateUnloadAddress(?string $text, ?string $url): void
    {
        $this->unloadAddressText = $text;
        $this->unloadAddressUrl = $url;
    }

    public function unloadFromCompanyId(): ?int
    {
        return $this->unloadFromCompanyId;
    }

    public function unloadToCompanyId(): ?int
    {
        return $this->unloadToCompanyId;
    }

    public function updateUnloadParties(?int $fromCompanyId, ?int $toCompanyId): void
    {
        $this->unloadFromCompanyId = $fromCompanyId;
        $this->unloadToCompanyId = $toCompanyId;
    }

    public function unloadContactName(): ?string
    {
        return $this->unloadContactName;
    }

    public function unloadContactPhone(): ?string
    {
        return $this->unloadContactPhone;
    }

    public function updateUnloadContact(?string $name, ?string $phone): void
    {
        $this->unloadContactName = $name;
        $this->unloadContactPhone = $phone;
    }

    public function unloadAcceptanceTime(): ?string
    {
        return $this->unloadAcceptanceTime;
    }

    public function updateUnloadAcceptanceTime(?string $value): void
    {
        $this->unloadAcceptanceTime = $value;
    }

    /**
     * @return int[]|string[]
     */
    public function unloadDocuments(): array
    {
        return $this->unloadDocuments;
    }

    /**
     * @param int[]|string[] $documents
     */
    public function replaceUnloadDocuments(array $documents): void
    {
        $this->unloadDocuments = $documents;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(DateTimeImmutable $timestamp): void
    {
        $this->updatedAt = $timestamp;
    }

}
