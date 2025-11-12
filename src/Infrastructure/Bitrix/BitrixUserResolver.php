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

        $user = $this->callUserCurrent($authToken);

        if (!$this->hasAdminMarker($user)) {
            $isAdmin = $this->fetchAdminFlag($authToken);
            if ($isAdmin) {
                $user['ADMIN'] = $user['ADMIN'] ?? 'Y';
                $user['IS_ADMIN'] = $user['IS_ADMIN'] ?? 'Y';
                $user['is_admin'] = $user['is_admin'] ?? 'Y';
            }
        }

        return $user;
    }

    /**
     * @return array<string,mixed>
     */
    private function callUserCurrent(string $authToken): array
    {
        $selectFields = [
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
        ];
        $query = http_build_query(['auth' => $authToken], '', '&', PHP_QUERY_RFC3986);
        foreach ($selectFields as $field) {
            $query .= '&select[]=' . rawurlencode($field);
        }

        $decoded = $this->performRequest('user.current', $query);
        $result = $decoded['result'] ?? null;

        if (!is_array($result)) {
            throw new RuntimeException('Bitrix24 не вернул данные пользователя.');
        }

        if (!isset($result['ID'])) {
            throw new RuntimeException('Bitrix24 ответ не содержит идентификатора пользователя.');
        }

        return $result;
    }

    private function fetchAdminFlag(string $authToken): bool
    {
        $query = http_build_query(['auth' => $authToken], '', '&', PHP_QUERY_RFC3986);
        try {
            $decoded = $this->performRequest('user.admin', $query);
        } catch (RuntimeException) {
            return false;
        }

        $value = $decoded['result'] ?? $decoded;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['y', 'yes', 'true', '1'], true);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function performRequest(string $method, string $query): array
    {
        $endpoint = sprintf('%s/rest/%s.json', $this->baseUrl, $method);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                'ignore_errors' => true,
                'timeout' => 10,
                'content' => $query,
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
        if (!is_array($result) && $method === 'user.current') {
            throw new RuntimeException('Bitrix24 не вернул данные пользователя.');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function hasAdminMarker(array $user): bool
    {
        $keys = [
            'ADMIN',
            'admin',
            'IS_ADMIN',
            'is_admin',
            'IS_ADMINISTRATOR',
            'is_administrator',
            'IS_SUPER_ADMIN',
            'is_super_admin',
            'IS_PORTAL_ADMIN',
            'is_portal_admin',
        ];

        foreach ($keys as $key) {
            if (!empty($user[$key])) {
                return true;
            }
        }

        $rights = $user['RIGHTS'] ?? null;
        if (is_array($rights)) {
            $lowered = array_map(
                static fn ($value): string => is_string($value) ? strtolower($value) : '',
                $rights
            );

            return in_array('admin', $lowered, true);
        }

        return false;
    }
}
