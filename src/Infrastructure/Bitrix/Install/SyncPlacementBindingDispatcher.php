<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Bitrix\Install;

use Psr\Log\LoggerInterface;

final class SyncPlacementBindingDispatcher implements PlacementBindingDispatcher
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param list<string> $placements
     * @param array<string,mixed> $options
     */
    public function dispatch(
        string $domain,
        string $token,
        string $handlerUri,
        array $placements,
        array $options = []
    ): array {
        $results = [];

        foreach ($placements as $placement) {
            $results[$placement] = [
                'unbind' => $this->callBitrix($domain, 'placement.unbind.json', [
                    'auth' => $token,
                    'PLACEMENT' => $placement,
                    'HANDLER' => $handlerUri,
                ]),
                'bind' => $this->callBitrix($domain, 'placement.bind.json', array_merge([
                    'auth' => $token,
                    'PLACEMENT' => $placement,
                    'HANDLER' => $handlerUri,
                ], $options)),
            ];
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private function callBitrix(string $domain, string $method, array $params): array
    {
        $url = sprintf('https://%s/rest/%s', $domain, $method);
        $query = http_build_query($params);
        $hasComplexParams = preg_match('/%5B.+%5D=/', $query) === 1;

        $endpoint = $url;
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['error' => 'curl_init_failed', 'url' => $endpoint];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'vsepeski-local-app-install',
        ];

        $options[CURLOPT_URL] = $hasComplexParams ? $endpoint : $endpoint . '?' . $query;

        if ($hasComplexParams) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $query;
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch) ?: 'curl_exec_failed';
            curl_close($ch);
            $this->logger->error('bitrix placement call failed', ['method' => $method, 'error' => $error]);
            return ['error' => $error, 'url' => $endpoint];
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            $this->logger->error('bitrix placement call returned invalid json', [
                'method' => $method,
                'http_status' => $status,
                'body' => $body,
            ]);

            return [
                'error' => 'invalid_json',
                'http_status' => $status,
                'raw' => $body,
                'url' => $endpoint,
            ];
        }

        $decoded['http_status'] = $status;
        $decoded['url'] = $endpoint;

        return $decoded;
    }
}
