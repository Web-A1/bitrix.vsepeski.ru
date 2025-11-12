<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Auth;

final class SessionAuthManager
{
    private const SESSION_KEY = 'auth_user';

    /**
     * @param array{
     *     id:int,
     *     name:string,
     *     login:string,
     *     first_name?:string,
     *     last_name?:string,
     *     email?:string|null,
     *     role?:string
     * } $user
     */
    public function login(array $user): void
    {
        $_SESSION[self::SESSION_KEY] = $user;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * @return array{
     *     id:int,
     *     name:string,
     *     login:string,
     *     first_name?:string,
     *     last_name?:string,
     *     email?:string|null,
     *     role?:string
     * }|null
     */
    public function user(): ?array
    {
        $user = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_array($user)) {
            return null;
        }

        if (!isset($user['id'], $user['name'], $user['login'])) {
            return null;
        }

        $user['id'] = (int) $user['id'];
        $user['name'] = (string) $user['name'];
        $user['login'] = (string) $user['login'];

        return $user;
    }
}
