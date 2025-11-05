<?php

declare(strict_types=1);

namespace B24\Center\Modules\Hauls\Infrastructure;

use B24\Center\Modules\Hauls\Domain\DriverAccount;
use PDO;

final class DriverAccountRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function findByLogin(string $login): ?DriverAccount
    {
        $statement = $this->connection->prepare(
            <<<SQL
                SELECT bitrix_user_id, login, password_hash, name, email, phone
                FROM driver_accounts
                WHERE LOWER(login) = :login
                LIMIT 1
            SQL
        );

        $statement->execute(['login' => mb_strtolower($login)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByBitrixUserId(int $bitrixUserId): ?DriverAccount
    {
        $statement = $this->connection->prepare(
            <<<SQL
                SELECT bitrix_user_id, login, password_hash, name, email, phone
                FROM driver_accounts
                WHERE bitrix_user_id = :bitrix_user_id
                LIMIT 1
            SQL
        );

        $statement->execute(['bitrix_user_id' => $bitrixUserId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @param array{bitrix_user_id:int|string,login:string,password_hash:string,name:string,email:?string,phone:?string} $row
     */
    private function hydrate(array $row): DriverAccount
    {
        return new DriverAccount(
            (int) $row['bitrix_user_id'],
            (string) $row['login'],
            (string) $row['password_hash'],
            (string) $row['name'],
            isset($row['email']) ? (string) $row['email'] : null,
            isset($row['phone']) ? (string) $row['phone'] : null
        );
    }
}
