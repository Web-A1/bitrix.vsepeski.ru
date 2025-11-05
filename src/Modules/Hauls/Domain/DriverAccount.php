<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Domain;

final class DriverAccount
{
    public function __construct(
        private readonly int $bitrixUserId,
        private readonly string $login,
        private readonly string $passwordHash,
        private readonly string $name,
        private readonly ?string $email,
        private readonly ?string $phone
    ) {
    }

    public function bitrixUserId(): int
    {
        return $this->bitrixUserId;
    }

    public function login(): string
    {
        return $this->login;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }
}
