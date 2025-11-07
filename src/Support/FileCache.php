<?php

declare(strict_types=1);

namespace B24\Center\Support;

use RuntimeException;

final class FileCache
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Failed to create cache directory "%s".', $this->directory));
        }
    }

    /**
     * @template TValue
     * @param callable():TValue $resolver
     * @return TValue
     */
    public function remember(string $key, int $ttlSeconds, callable $resolver)
    {
        $path = $this->pathFor($key);
        $now = time();

        if (is_file($path)) {
            $payload = json_decode((string) file_get_contents($path), true);
            if (is_array($payload) && isset($payload['stored_at'], $payload['value'])) {
                if ($now - (int) $payload['stored_at'] < $ttlSeconds) {
                    /** @var TValue $value */
                    $value = $payload['value'];
                    return $value;
                }
            }
        }

        /** @var TValue $value */
        $value = $resolver();
        $this->store($path, $value, $now);

        return $value;
    }

    private function pathFor(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\\-]/', '_', $key);
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private function store(string $path, mixed $value, int $timestamp): void
    {
        $payload = json_encode([
            'stored_at' => $timestamp,
            'value' => $value,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Failed to encode cache payload.');
        }

        file_put_contents($path, $payload, LOCK_EX);
    }
}
