<?php

declare(strict_types=1);

namespace B24\Center\Tests\Feature;

use B24\Center\Modules\Hauls\Application\DTO\ActorContext;
use B24\Center\Modules\Hauls\Application\Services\HaulService;
use B24\Center\Modules\Hauls\Infrastructure\HaulChangeHistoryRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulRepository;
use B24\Center\Modules\Hauls\Infrastructure\HaulStatusHistoryRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HaulDeletionAuthorizationTest extends TestCase
{
    private PDO $pdo;
    private HaulService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();

        $repository = new HaulRepository($this->pdo);
        $statusHistory = new HaulStatusHistoryRepository($this->pdo);
        $changeHistory = new HaulChangeHistoryRepository($this->pdo);

        $this->service = new HaulService($repository, $statusHistory, $changeHistory);
    }

    public function testAdminCanDeleteHaul(): void
    {
        $this->insertHaul('haul-admin', 10);

        $actor = new ActorContext(1, 'Admin', 'admin');
        $this->service->delete('haul-admin', $actor);

        self::assertFalse($this->haulExists('haul-admin'));
    }

    public function testResponsibleCanDeleteOwnHaul(): void
    {
        $this->insertHaul('haul-owner', 55);

        $actor = new ActorContext(55, 'Driver', 'driver');
        $this->service->delete('haul-owner', $actor);

        self::assertFalse($this->haulExists('haul-owner'));
    }

    public function testOtherUserCannotDeleteForeignHaul(): void
    {
        $this->insertHaul('haul-foreign', 77);

        $actor = new ActorContext(10, 'Manager', 'manager');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Недостаточно прав для удаления рейса.');

        $this->service->delete('haul-foreign', $actor);
    }

    private function haulExists(string $id): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(1) FROM hauls WHERE id = :id');
        $statement->execute(['id' => $id]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function insertHaul(string $id, ?int $responsibleId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            <<<SQL
                INSERT INTO hauls (
                    id, deal_id, responsible_id, truck_id, material_id, sequence, status, general_notes,
                    load_address_text, load_address_url, load_from_company_id, load_to_company_id,
                    load_volume, load_actual_volume, leg_distance_km, load_documents,
                    unload_address_text, unload_address_url, unload_from_company_id, unload_to_company_id,
                    unload_contact_name, unload_contact_phone, unload_acceptance_time, unload_documents,
                    created_at, updated_at
                ) VALUES (
                    :id, :deal_id, :responsible_id, :truck_id, :material_id, :sequence, :status, :general_notes,
                    :load_address_text, :load_address_url, :load_from_company_id, :load_to_company_id,
                    :load_volume, :load_actual_volume, :leg_distance_km, :load_documents,
                    :unload_address_text, :unload_address_url, :unload_from_company_id, :unload_to_company_id,
                    :unload_contact_name, :unload_contact_phone, :unload_acceptance_time, :unload_documents,
                    :created_at, :updated_at
                )
            SQL
        );

        $statement->execute([
            'id' => $id,
            'deal_id' => 100,
            'responsible_id' => $responsibleId,
            'truck_id' => 'truck',
            'material_id' => 'material',
            'sequence' => 1,
            'status' => 0,
            'general_notes' => null,
            'load_address_text' => 'from',
            'load_address_url' => null,
            'load_from_company_id' => null,
            'load_to_company_id' => null,
            'load_volume' => null,
            'load_actual_volume' => null,
            'leg_distance_km' => null,
            'load_documents' => json_encode([], JSON_THROW_ON_ERROR),
            'unload_address_text' => 'to',
            'unload_address_url' => null,
            'unload_from_company_id' => null,
            'unload_to_company_id' => null,
            'unload_contact_name' => null,
            'unload_contact_phone' => null,
            'unload_acceptance_time' => null,
            'unload_documents' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            <<<SQL
                CREATE TABLE hauls (
                    id TEXT PRIMARY KEY,
                    deal_id INTEGER NOT NULL,
                    responsible_id INTEGER NULL,
                    truck_id TEXT,
                    material_id TEXT,
                    sequence INTEGER NOT NULL,
                    status INTEGER NOT NULL,
                    general_notes TEXT,
                    load_address_text TEXT,
                    load_address_url TEXT,
                    load_from_company_id INTEGER,
                    load_to_company_id INTEGER,
                    load_volume REAL,
                    load_actual_volume REAL,
                    leg_distance_km REAL,
                    load_documents TEXT,
                    unload_address_text TEXT,
                    unload_address_url TEXT,
                    unload_from_company_id INTEGER,
                    unload_to_company_id INTEGER,
                    unload_contact_name TEXT,
                    unload_contact_phone TEXT,
                    unload_acceptance_time TEXT,
                    unload_documents TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )
            SQL
        );
    }
}
