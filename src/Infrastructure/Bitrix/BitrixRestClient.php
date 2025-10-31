<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Bitrix;

use RuntimeException;

final class BitrixRestClient
{
    public function __construct(private readonly string $webhookBaseUrl)
    {
        if ($this->webhookBaseUrl === '') {
            throw new RuntimeException('Bitrix webhook URL is not configured.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function call(string $method, array $payload = []): array
    {
        $url = sprintf('%s/%s', $this->webhookBaseUrl, $method);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new RuntimeException('Bitrix REST request failed: ' . ($error['message'] ?? 'unknown error'));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from Bitrix REST.');
        }

        if (isset($decoded['error'])) {
            $message = is_string($decoded['error_description'] ?? null)
                ? $decoded['error_description']
                : ($decoded['error'] ?? 'Bitrix error');
            throw new RuntimeException('Bitrix REST error: ' . $message);
        }

        return $decoded;
    }
}
