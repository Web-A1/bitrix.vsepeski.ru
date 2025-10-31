<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Http;

class Response
{
    public function __construct(
        private string $body,
        private int $status = 200,
        private array $headers = []
    ) {
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $headers = array_merge(['Content-Type' => 'application/json'], $headers);

        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $status, $headers);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }
}
