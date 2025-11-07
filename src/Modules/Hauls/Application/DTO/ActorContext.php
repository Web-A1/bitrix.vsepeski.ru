<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Application\DTO;

final class ActorContext
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?string $name,
        public readonly string $role,
    ) {
    }
}
