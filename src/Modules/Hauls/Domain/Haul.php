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
        private readonly string $truckId,
        private readonly string $materialId,
        private int $sequence,
        private string $loadAddressText,
        private ?string $loadAddressUrl,
        private ?int $loadFromCompanyId,
        private ?int $loadToCompanyId,
        private ?float $loadVolume,
        private array $loadDocuments,
        private string $unloadAddressText,
        private ?string $unloadAddressUrl,
        private ?int $unloadFromCompanyId,
        private ?int $unloadToCompanyId,
        private ?string $unloadContactName,
        private ?string $unloadContactPhone,
        private array $unloadDocuments,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $deletedAt = null,
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

    public function truckId(): string
    {
        return $this->truckId;
    }

    public function materialId(): string
    {
        return $this->materialId;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function rewriteSequence(int $sequence): void
    {
        $this->sequence = $sequence;
    }

    public function loadAddressText(): string
    {
        return $this->loadAddressText;
    }

    public function loadAddressUrl(): ?string
    {
        return $this->loadAddressUrl;
    }

    public function updateLoadAddress(string $text, ?string $url): void
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

    public function unloadAddressText(): string
    {
        return $this->unloadAddressText;
    }

    public function unloadAddressUrl(): ?string
    {
        return $this->unloadAddressUrl;
    }

    public function updateUnloadAddress(string $text, ?string $url): void
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

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function markDeleted(DateTimeImmutable $timestamp): void
    {
        $this->deletedAt = $timestamp;
    }

    public function restore(): void
    {
        $this->deletedAt = null;
    }
}
