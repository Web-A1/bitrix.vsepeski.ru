<?php

declare(strict_types=1);

namespace B24\Center\Core;

use Closure;
use RuntimeException;

/**
 * Minimalistic service container to orchestrate application components.
 */
class Application
{
    /** @var array<string, Closure(self):object> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function singleton(string $id, callable $factory): void
    {
        $this->bindings[$id] = static function (self $container) use ($factory, $id): object {
            $concrete = $factory($container);

            if (!is_object($concrete)) {
                throw new RuntimeException(sprintf('Factory for "%s" must return an object.', $id));
            }

            return $concrete;
        };
    }

    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $this->instances[$id] = $this->bindings[$id]($this);

            return $this->instances[$id];
        }

        if ($id === self::class) {
            return $this;
        }

        throw new RuntimeException(sprintf('Service "%s" is not bound in the container.', $id));
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || $id === self::class;
    }
}
