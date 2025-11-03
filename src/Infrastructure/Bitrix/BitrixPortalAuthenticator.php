<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Bitrix;

use RuntimeException;

final class BitrixPortalAuthenticator
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
     * @return array{id:int,name:string,login:string,first_name?:string,last_name?:string,email?:string|null}
     */
    public function login(string $login, string $password): array
    {
        $login = trim($login);
        $password = (string) $password;

        if ($login === '' || trim($password) === '') {
            throw new RuntimeException('Необходимо указать логин и пароль.');
        }

        $endpoint = sprintf('%s/rest/user.login.json', $this->baseUrl);
        $payload = http_build_query([
            'USER_LOGIN' => $login,
            'USER_PASSWORD' => $password,
        ], '', '&', PHP_QUERY_RFC3986);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                ],
                'content' => $payload,
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
            $message = $decoded['error_description'] ?? $decoded['error'] ?? 'Ошибка авторизации Bitrix24';
            throw new RuntimeException('Ошибка авторизации Bitrix24: ' . (string) $message);
        }

        $result = $decoded['result'] ?? null;
        if (!is_array($result)) {
            throw new RuntimeException('Bitrix24 вернул пустой результат.');
        }

        $userId = (int) ($result['ID'] ?? $result['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Bitrix24 не вернул идентификатор пользователя.');
        }

        $userLogin = (string) ($result['LOGIN'] ?? $result['login'] ?? $login);
        $firstName = trim((string) ($result['NAME'] ?? $result['name'] ?? ''));
        $lastName = trim((string) ($result['LAST_NAME'] ?? $result['last_name'] ?? ''));
        $displayName = trim($firstName . ' ' . $lastName);
        if ($displayName === '') {
            $displayName = $userLogin;
        }

        $email = $result['EMAIL'] ?? $result['email'] ?? null;
        if ($email !== null) {
            $email = (string) $email;
        }

        return [
            'id' => $userId,
            'name' => $displayName,
            'login' => $userLogin,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ];
    }
}
