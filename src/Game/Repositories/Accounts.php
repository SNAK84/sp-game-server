<?php

namespace Game\Repositories;

use Game\Account;
use Core\Database;

class Accounts
{
    /** @var Account[] Массив всех аккаунтов по ID */
    protected static array $accounts = [];

    /**
     * Подгрузка всех аккаунтов из базы при старте сервера
     */
    public static function loadAll(): void
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll("SELECT * FROM accounts");

        foreach ($rows as $row) {
            $account = new Account($row);
            self::add($account);
        }
    }

    /**
     * Добавление аккаунта в массив
     */
    public static function add(Account $account): void
    {
        self::$accounts[$account->getId()] = $account;
    }

    /**
     * Получить аккаунт по ID
     */
    public static function get(int $id): ?Account
    {
        return self::$accounts[$id] ?? null;
    }

    /**
     * Найти аккаунт по логину
     */
    public static function findByLogin(string $login): ?Account
    {
        foreach (self::$accounts as $account) {
            if ($account->getLogin() === $login) {
                return $account;
            }
        }
        return null;
    }

    /**
     * Найти аккаунт по токену
     */
    public static function findByToken(string $token): ?Account
    {
        foreach (self::$accounts as $account) {
            if ($account->getToken() === $token) {
                return $account;
            }
        }
        return null;
    }

    /**
     * Вернуть все аккаунты
     * @return Account[]
     */
    public static function all(): array
    {
        return self::$accounts;
    }
}
