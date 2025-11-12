<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Http;

final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        private readonly array $body,
        private readonly array $rawServer,
    ) {
    }

    public static function fromGlobals(array $server): self
    {
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $uri = $server['REQUEST_URI'] ?? '/';
        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '/';

        parse_str($parsed['query'] ?? '', $query);

        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }

        $raw = file_get_contents('php://input');
        $body = self::parseBody($raw, $_POST);

        return new self($method, $path, $query, $headers, $body, $server);
    }

    /**
     * Helper factory for tests to avoid relying on superglobals.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     * @param array<string,mixed> $server
     */
    public static function fake(
        string $method = 'GET',
        string $path = '/',
        array $body = [],
        array $headers = [],
        array $query = [],
        array $server = [],
    ): self {
        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[strtolower($name)] = $value;
        }

        return new self(
            strtoupper($method),
            $path,
            $query,
            $normalizedHeaders,
            $body,
            $server
        );
    }

    /**
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private static function parseBody(string|false $raw, array $fallback): array
    {
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if ($fallback !== []) {
            return self::normalizeInputArray($fallback);
        }

        return [];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private static function normalizeInputArray(array $input): array
    {
        $normalized = [];

        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = self::normalizeInputArray($value);
                continue;
            }

            if (is_object($value)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string,mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string,mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);

        return $this->headers[$key] ?? $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function server(): array
    {
        return $this->rawServer;
    }
}
