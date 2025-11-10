<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Security;

final class WebhookSignatureVerifier
{
    public function __construct(private readonly string $secret)
    {
    }

    public function verify(array $server, array $query, array $post, string $rawBody = ''): bool
    {
        if ($this->secret === '') {
            return true;
        }

        $header = $server['HTTP_X_BITRIX_SIGNATURE'] ?? $server['HTTP_BITRIX_SIGNATURE'] ?? null;
        if (!is_string($header) || $header === '') {
            return false;
        }

        $payload = $query;

        if (!empty($post)) {
            $payload = array_merge($payload, $post);
        }

        $data = '';

        if (!empty($payload)) {
            ksort($payload);
            $data = http_build_query($payload);
        } elseif ($rawBody !== '') {
            $data = $rawBody;
        }

        $digest = hash_hmac('sha256', $data, $this->secret);

        return hash_equals($digest, $header);
    }
}
