<?php

declare(strict_types=1);

namespace B24\Center\Tests\Unit;

use B24\Center\Infrastructure\Security\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureVerifierTest extends TestCase
{
    public function testValidSignaturePasses(): void
    {
        $secret = 'topsecret';
        $query = ['foo' => 'bar'];
        $post = ['baz' => 'qux'];
        $data = array_merge($query, $post);
        ksort($data);
        $payload = http_build_query($data);
        $signature = hash_hmac('sha256', $payload, $secret);

        $verifier = new WebhookSignatureVerifier($secret);

        self::assertTrue(
            $verifier->verify(['HTTP_X_BITRIX_SIGNATURE' => $signature], $query, $post)
        );
    }

    public function testInvalidSignatureFails(): void
    {
        $secret = 'topsecret';
        $query = ['foo' => 'bar'];
        $post = [];

        $verifier = new WebhookSignatureVerifier($secret);

        self::assertFalse(
            $verifier->verify(['HTTP_X_BITRIX_SIGNATURE' => 'invalid'], $query, $post)
        );
    }
}
