<?php

declare(strict_types=1);

namespace B24\Center\Infrastructure\Auth;

use B24\Center\Modules\Hauls\Infrastructure\DriverAccountRepository;
use RuntimeException;

final class LocalDriverAuthenticator
{
    public function __construct(private readonly DriverAccountRepository $repository)
    {
    }

    /**
     * @return array{id:int,name:string,login:string,email?:string|null}
     */
    public function login(string $login, string $password): array
    {
        $login = trim(mb_strtolower($login));

        if ($login === '' || trim($password) === '') {
            throw new RuntimeException('Укажите логин и пароль.');
        }

        $account = $this->repository->findByLogin($login);

        if ($account === null || !$account->verifyPassword($password)) {
            throw new RuntimeException('Неверный логин или пароль.');
        }

        return [
            'id' => $account->bitrixUserId(),
            'name' => $account->name(),
            'login' => $account->login(),
            'email' => $account->email(),
        ];
    }
}
