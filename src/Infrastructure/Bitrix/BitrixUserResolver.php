<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Bitrix;

use RuntimeException;

class BitrixUserResolver
{
    private string $baseUrl;

    public function __construct(string $portalUrl)
    {
        $portalUrl = trim($portalUrl);

        if ($portalUrl === '') {
            throw new RuntimeException('Bitrix portal URL is not configured.');
        }

        $this->baseUrl = rtrim($portalUrl, '/');
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve(string $authToken): array
    {
        $authToken = trim($authToken);

        if ($authToken === '') {
            throw new RuntimeException('Bitrix auth token is empty.');
        }

        $query = http_build_query(
            [
                'auth' => $authToken,
                'select' => [
                    'ID',
                    'NAME',
                    'LAST_NAME',
                    'SECOND_NAME',
                    'EMAIL',
                    'LOGIN',
                    'PERSONAL_PROFESSION',
                    'WORK_POSITION',
                    'POSITION',
                    'ADMIN',
                    'IS_ADMIN',
                    'IS_ADMINISTRATOR',
                    'IS_SUPER_ADMIN',
                    'IS_PORTAL_ADMIN',
                    'RIGHTS',
                ],
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $endpoint = sprintf('%s/rest/user.current.json?%s', $this->baseUrl, $query);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'Accept: application/json',
                ],
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $response = file_get_contents($endpoint, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new RuntimeException('Не удалось выполнить запрос к Bitrix24: ' . ($error['message'] ?? 'unknown error'));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Bitrix24 вернул некорректный ответ.');
        }

        if (isset($decoded['error'])) {
            $message = $decoded['error_description'] ?? $decoded['error'] ?? 'Bitrix24 error';
            throw new RuntimeException('Bitrix24: ' . (string) $message);
        }

        $result = $decoded['result'] ?? null;
        if (!is_array($result)) {
            throw new RuntimeException('Bitrix24 не вернул данные пользователя.');
        }

        if (!isset($result['ID'])) {
            throw new RuntimeException('Bitrix24 ответ не содержит идентификатора пользователя.');
        }

        return $result;
    }
}
