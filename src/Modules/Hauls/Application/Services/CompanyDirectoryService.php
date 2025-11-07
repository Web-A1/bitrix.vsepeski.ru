<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\Services;

use B24\Center\Infrastructure\Bitrix\BitrixRestClient;
use RuntimeException;

final class CompanyDirectoryService
{
    /**
     * @param array<string,string> $typeMap
     */
    public function __construct(
        private readonly BitrixRestClient $client,
        private readonly array $typeMap = [],
        private readonly int $maxItems = 200,
    ) {
    }

    /**
     * @return list<array{id:int,title:string,type_id:string,phone:?string}>
     */
    public function listByAlias(string $alias): array
    {
        $typeId = $this->typeMap[$alias] ?? null;

        if ($typeId === null || $typeId === '') {
            throw new RuntimeException(sprintf('Неизвестный тип компании "%s".', $alias));
        }

        $items = [];
        $start = 0;

        while (count($items) < $this->maxItems) {
            $response = $this->client->call('crm.company.list', [
                'order' => ['TITLE' => 'ASC'],
                'filter' => ['TYPE_ID' => $typeId],
                'select' => ['ID', 'TITLE', 'TYPE_ID', 'PHONE'],
                'start' => $start,
            ]);

            $batch = $response['result'] ?? [];
            foreach ($batch as $entry) {
                $items[] = [
                    'id' => isset($entry['ID']) ? (int) $entry['ID'] : 0,
                    'title' => (string) ($entry['TITLE'] ?? ''),
                    'type_id' => (string) ($entry['TYPE_ID'] ?? $typeId),
                    'phone' => $this->extractPhone($entry),
                ];

                if (count($items) >= $this->maxItems) {
                    break 2;
                }
            }

            if (!isset($response['next'])) {
                break;
            }

            $next = (int) $response['next'];
            if ($next === $start) {
                break;
            }

            $start = $next;
        }

        return array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['id'] > 0 && $item['title'] !== ''
        ));
    }

    private function extractPhone(array $entry): ?string
    {
        $phones = $entry['PHONE'] ?? null;
        if (!is_array($phones)) {
            return null;
        }

        foreach ($phones as $row) {
            if (isset($row['VALUE']) && is_string($row['VALUE']) && $row['VALUE'] !== '') {
                return $row['VALUE'];
            }
        }

        return null;
    }
}
