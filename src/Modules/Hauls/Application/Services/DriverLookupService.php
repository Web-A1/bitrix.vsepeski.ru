<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\Services;

use B24\Center\Infrastructure\Bitrix\BitrixRestClient;
use B24\Center\Support\FileCache;
use RuntimeException;

final class DriverLookupService
{
    public function __construct(
        private readonly BitrixRestClient $client,
        private readonly string $departmentName,
        private readonly FileCache $cache,
        private readonly int $cacheTtl = 300,
    ) {
    }

    /**
     * @return array<int,array{id:int,name:string,position:?string,phone:?string}>
     */
    public function listDrivers(): array
    {
        $departmentId = $this->resolveDepartmentId();

        return $this->cache->remember('drivers_' . $departmentId, $this->cacheTtl, function () use ($departmentId): array {
            return $this->fetchDrivers($departmentId);
        });
    }

    /**
     * @return array<int,array{id:int,name:string,position:?string,phone:?string}>
     */
    private function fetchDrivers(int $departmentId): array
    {
        $result = $this->client->call('user.get', [
            'FILTER' => [
                'UF_DEPARTMENT' => $departmentId,
                'ACTIVE' => 'Y',
            ],
            'SELECT' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PERSONAL_MOBILE', 'WORK_POSITION'],
        ]);

        $users = $result['result'] ?? [];

        if (!is_array($users)) {
            return [];
        }

        $drivers = array_filter($users, function (array $driver): bool {
            $positions = array_filter(array_map('trim', [
                $driver['WORK_POSITION'] ?? '',
                $driver['POSITION'] ?? '',
            ]));
            if (!$positions) {
                return false;
            }

            return count(array_filter($positions, static function (string $value): bool {
                return mb_strtolower($value) === mb_strtolower('Водитель');
            })) > 0;
        });

        return array_map(function (array $driver): array {
            $name = trim(sprintf('%s %s %s', $driver['LAST_NAME'] ?? '', $driver['NAME'] ?? '', $driver['SECOND_NAME'] ?? ''));
            if ($name === '') {
                $name = $driver['NAME'] ?? 'Без имени';
            }

            return [
                'id' => (int) ($driver['ID'] ?? 0),
                'name' => $name,
                'position' => $driver['WORK_POSITION'] ?? null,
                'phone' => $driver['PERSONAL_MOBILE'] ?? null,
            ];
        }, $drivers);
    }

    private function resolveDepartmentId(): int
    {
        return $this->cache->remember('department_' . md5($this->departmentName), 3600, function (): int {
        $start = 0;
        $departmentName = mb_strtolower($this->departmentName);

        while (true) {
            $response = $this->client->call('department.get', ['start' => $start]);
            $departments = $response['result'] ?? [];

            if (!is_array($departments) || !$departments) {
                break;
            }

            foreach ($departments as $department) {
                if (mb_strtolower($department['NAME'] ?? '') === $departmentName) {
                    return (int) $department['ID'];
                }
            }

            if (!isset($response['next'])) {
                break;
            }

            $start = (int) $response['next'];
        }

            throw new RuntimeException(sprintf('Department "%s" not found in Bitrix.', $this->departmentName));
        });
    }
}
