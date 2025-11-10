<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Bitrix\Install;

interface PlacementBindingDispatcher
{
    /**
     * @param list<string> $placements
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function dispatch(
        string $domain,
        string $token,
        string $handlerUri,
        array $placements,
        array $options = []
    ): array;
}
