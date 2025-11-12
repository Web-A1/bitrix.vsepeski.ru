<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\Services;

use B24\Center\Infrastructure\Bitrix\BitrixRestClient;
use RuntimeException;

final class DealInfoService
{
    public function __construct(private readonly BitrixRestClient $client)
    {
    }

    /**
     * @return array{
     *     id:int,
     *     title:string,
     *     stage?:string|null,
     *     category_id?:int|null,
     *     company?:array{id:int,title:string}|null,
     *     contact?:array{id:int,name:string,phone:?string}|null,
     *     responsible?:array{id:int,name?:string}|null
     * }
     */
    public function get(int $dealId): array
    {
        if ($dealId <= 0) {
            throw new RuntimeException('Некорректный ID сделки.');
        }

        $response = $this->client->call('crm.deal.get', [
            'id' => $dealId,
            'select' => [
                'ID',
                'TITLE',
                'STAGE_ID',
                'CATEGORY_ID',
                'COMPANY_ID',
                'COMPANY_TITLE',
                'CONTACT_ID',
                'CONTACT_NAME',
                'CONTACT_LAST_NAME',
                'CONTACT_SECOND_NAME',
                'CONTACT_PHONE',
                'ASSIGNED_BY_ID',
                'ASSIGNED_BY_NAME',
                'ASSIGNED_BY_LAST_NAME',
                'ASSIGNED_BY_SECOND_NAME',
            ],
        ]);
        $deal = $response['result'] ?? $response['deal'] ?? null;

        if (!is_array($deal)) {
            throw new RuntimeException('Сделка не найдена.');
        }

        $companyId = isset($deal['COMPANY_ID']) ? (int) $deal['COMPANY_ID'] : null;
        $contactId = isset($deal['CONTACT_ID']) ? (int) $deal['CONTACT_ID'] : null;

        return [
            'id' => $dealId,
            'title' => (string) ($deal['TITLE'] ?? ''),
            'stage' => $deal['STAGE_ID'] ?? null,
            'category_id' => isset($deal['CATEGORY_ID']) ? (int) $deal['CATEGORY_ID'] : null,
            'company' => $companyId
                ? [
                    'id' => $companyId,
                    'title' => (string) ($deal['COMPANY_TITLE'] ?? ''),
                ]
                : null,
            'contact' => $contactId
                ? [
                    'id' => $contactId,
                    'name' => $this->formatContactName($deal),
                    'phone' => $this->extractContactPhone($deal),
                ]
                : null,
            'responsible' => $this->extractResponsible($deal),
        ];
    }

    private function formatContactName(array $deal): string
    {
        $parts = [
            $deal['CONTACT_LAST_NAME'] ?? null,
            $deal['CONTACT_NAME'] ?? null,
            $deal['CONTACT_SECOND_NAME'] ?? null,
        ];

        return trim(implode(' ', array_filter(array_map(
            static fn ($value) => is_string($value) ? trim($value) : '',
            $parts
        ))));
    }

    private function extractContactPhone(array $deal): ?string
    {
        if (!isset($deal['CONTACT_PHONE']) || !is_array($deal['CONTACT_PHONE'])) {
            return null;
        }

        foreach ($deal['CONTACT_PHONE'] as $row) {
            if (isset($row['VALUE']) && is_string($row['VALUE']) && $row['VALUE'] !== '') {
                return $row['VALUE'];
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $deal
     * @return array{id:int,name?:string}|null
     */
    private function extractResponsible(array $deal): ?array
    {
        if (!isset($deal['ASSIGNED_BY_ID'])) {
            return null;
        }

        $id = (int) $deal['ASSIGNED_BY_ID'];
        if ($id <= 0) {
            return null;
        }

        $nameParts = [
            $deal['ASSIGNED_BY_LAST_NAME'] ?? null,
            $deal['ASSIGNED_BY_NAME'] ?? null,
            $deal['ASSIGNED_BY_SECOND_NAME'] ?? null,
        ];
        $name = trim(implode(' ', array_filter(array_map(
            static fn ($value) => is_string($value) ? trim($value) : '',
            $nameParts
        ))));

        return $name !== ''
            ? ['id' => $id, 'name' => $name]
            : ['id' => $id];
    }
}
