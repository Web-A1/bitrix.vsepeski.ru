<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\Services;

use B24\Center\Infrastructure\Bitrix\BitrixRestClient;
use RuntimeException;

final class DriverLookupService
{
    public function __construct(
        private readonly BitrixRestClient $client,
        private readonly string $departmentName
    ) {
    }

    /**
     * @return array<int,array{id:int,name:string,position:?string,phone:?string}>
     */
    public function listDrivers(): array
    {
        $departmentId = $this->resolveDepartmentId();

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

        return array_map(function (array $user): array {
            $name = trim(sprintf('%s %s %s', $user['LAST_NAME'] ?? '', $user['NAME'] ?? '', $user['SECOND_NAME'] ?? ''));
            if ($name === '') {
                $name = $user['NAME'] ?? 'Без имени';
            }

            return [
                'id' => (int) ($user['ID'] ?? 0),
                'name' => $name,
                'position' => $user['WORK_POSITION'] ?? null,
                'phone' => $user['PERSONAL_MOBILE'] ?? null,
            ];
        }, $users);
    }

    private function resolveDepartmentId(): int
    {
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
    }
}
