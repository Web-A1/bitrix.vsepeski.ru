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
        $body = [];

        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self($method, $path, $query, $headers, $body, $server);
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

