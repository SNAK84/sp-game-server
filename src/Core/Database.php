<?php

namespace SPGame\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    private bool $persistent = true;

    /**
     * Получение единственного экземпляра
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Приватный конструктор (singleton)
     */
    private function __construct() {}

    /**
     * Проверка соединения и переподключение при необходимости
     */
    private function ensureConnection(): void
    {
        if ($this->pdo === null) {
            $this->connect();
            return;
        }

        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            $this->connect();
        }
    }

    /**
     * Подключение к базе с параметрами из Environment
     */
    private function connect(): void
    {
        $host = Environment::require('DB_HOST');
        $dbname = Environment::require('DB_NAME');
        $user = Environment::require('DB_USER');
        $password = Environment::require('DB_PASS');

        try {
            $this->pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8",
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => $this->persistent,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    // =====================
    // Методы работы с базой
    // =====================

    public function query(string $sql, array $params = []): bool
    {
        $this->ensureConnection();
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->ensureConnection();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->ensureConnection();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function lastInsertId(): string
    {
        $this->ensureConnection();
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->ensureConnection();
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->ensureConnection();
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->ensureConnection();
        $this->pdo->rollBack();
    }
}
