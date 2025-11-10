<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Bitrix\Install;

use B24\Center\Infrastructure\Http\Response;

final class InstallResult
{
    private function __construct(
        private readonly ?Response $response,
        private readonly ?array $jsonPayload,
        private readonly int $statusCode,
        private readonly array $headers
    ) {
    }

    public static function json(array $payload, int $statusCode = 200, array $headers = []): self
    {
        $headers = array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers);

        return new self(null, $payload, $statusCode, $headers);
    }

    public static function html(Response $response): self
    {
        return new self($response, null, 200, []);
    }

    public function isHtml(): bool
    {
        return $this->response !== null;
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getJsonPayload(): ?array
    {
        return $this->jsonPayload;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if ($this->response !== null) {
            $this->response->send();
            return;
        }

        foreach ($this->headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value));
        }

        http_response_code($this->statusCode);
        echo json_encode($this->jsonPayload ?? [], JSON_UNESCAPED_UNICODE);
    }
}
