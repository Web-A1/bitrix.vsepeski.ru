<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\Services;

use B24\Center\Infrastructure\Bitrix\BitrixRestClient;
use RuntimeException;

final class DealInfoService
{
    private ?array $materialFieldOptions = null;
    private ?string $materialsField;

    public function __construct(
        private readonly BitrixRestClient $client,
        ?string $materialsField = null,
    ) {
        $normalized = $materialsField !== null ? trim($materialsField) : '';
        $this->materialsField = $normalized !== '' ? $normalized : null;
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

        $select = [
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
        ];

        if ($this->materialsField !== null && $this->materialsField !== '') {
            $select[] = $this->materialsField;
        }

        $response = $this->client->call('crm.deal.get', [
            'id' => $dealId,
            'select' => $select,
        ]);
        $deal = $response['result'] ?? $response['deal'] ?? null;

        if (!is_array($deal)) {
            throw new RuntimeException('Сделка не найдена.');
        }

        $companyId = isset($deal['COMPANY_ID']) ? (int) $deal['COMPANY_ID'] : null;
        $company = null;
        if ($companyId && $companyId > 0) {
            $company = [
                'id' => $companyId,
                'title' => (string) ($deal['COMPANY_TITLE'] ?? ''),
            ];

            if ($company['title'] === '') {
                $companyDetails = $this->fetchCompany($companyId);
                if ($companyDetails !== null) {
                    $company['title'] = $companyDetails['title'];
                }
            }
        }
        $contactId = isset($deal['CONTACT_ID']) ? (int) $deal['CONTACT_ID'] : null;

        return [
            'id' => $dealId,
            'title' => (string) ($deal['TITLE'] ?? ''),
            'stage' => $deal['STAGE_ID'] ?? null,
            'category_id' => isset($deal['CATEGORY_ID']) ? (int) $deal['CATEGORY_ID'] : null,
            'company' => $company,
            'contact' => $contactId
                ? [
                    'id' => $contactId,
                    'name' => $this->formatContactName($deal),
                    'phone' => $this->extractContactPhone($deal),
                ]
                : null,
            'responsible' => $this->extractResponsible($deal),
            'materials' => $this->extractDealMaterials($deal),
        ];
    }

    private function extractDealMaterials(array $deal): ?array
    {
        if ($this->materialsField === null || $this->materialsField === '') {
            return null;
        }

        if (!array_key_exists($this->materialsField, $deal)) {
            return null;
        }

        $raw = $deal[$this->materialsField];
        $selected = $this->normalizeSelectedMaterials($raw);

        if ($selected === []) {
            return null;
        }

        $labels = $this->resolveMaterialLabels($selected);
        if ($labels === []) {
            $labels = $selected;
        }

        return [
            'field' => $this->materialsField,
            'selected_ids' => $selected,
            'labels' => $labels,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeSelectedMaterials(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $normalized = [];
        foreach ($raw as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $normalized[] = (string) $value;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<string> $selected
     * @return list<string>
     */
    private function resolveMaterialLabels(array $selected): array
    {
        if ($selected === []) {
            return [];
        }

        $options = $this->loadMaterialFieldOptions();
        if ($options === []) {
            return [];
        }

        $labels = [];
        foreach ($selected as $value) {
            if (isset($options[$value])) {
                $labels[] = $options[$value];
            }
        }

        return $labels;
    }

    /**
     * @return array<string,string>
     */
    private function loadMaterialFieldOptions(): array
    {
        if ($this->materialsField === null || $this->materialsField === '') {
            return [];
        }

        if ($this->materialFieldOptions !== null) {
            return $this->materialFieldOptions;
        }

        try {
            $response = $this->client->call('crm.deal.userfield.list', [
                'filter' => [
                    'FIELD_NAME' => $this->materialsField,
                ],
            ]);
        } catch (RuntimeException) {
            $this->materialFieldOptions = [];
            return $this->materialFieldOptions;
        }

        $items = $response['result'] ?? null;
        if (!is_array($items)) {
            $this->materialFieldOptions = [];
            return $this->materialFieldOptions;
        }

        foreach ($items as $item) {
            if (!is_array($item) || ($item['FIELD_NAME'] ?? null) !== $this->materialsField) {
                continue;
            }
            if (!isset($item['LIST']) || !is_array($item['LIST'])) {
                continue;
            }
            $options = [];
            foreach ($item['LIST'] as $option) {
                if (!isset($option['ID'], $option['VALUE'])) {
                    continue;
                }
                $options[(string) $option['ID']] = (string) $option['VALUE'];
            }
            $this->materialFieldOptions = $options;
            return $this->materialFieldOptions;
        }

        $this->materialFieldOptions = [];
        return $this->materialFieldOptions;
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

    private function fetchCompany(int $companyId): ?array
    {
        try {
            $response = $this->client->call('crm.company.get', ['id' => $companyId]);
        } catch (RuntimeException) {
            return null;
        }

        if (!isset($response['result']) || !is_array($response['result'])) {
            return null;
        }

        return [
            'id' => $companyId,
            'title' => (string) ($response['result']['TITLE'] ?? ''),
        ];
    }
}
