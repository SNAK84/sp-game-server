<?php

namespace SPGame\Core;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?self $instance = null;
    private static bool $runtimeSqlForbidden = false;
    private static int $persistenceDepth = 0;

    /** В игровом/сетевом/боевом процессе SQL после bootstrap запрещён. */
    public static function forbidRuntimeSql(bool $forbid = true): void { self::$runtimeSqlForbidden = $forbid; }

    public static function persistence(callable $operation): mixed
    {
        ++self::$persistenceDepth;
        try { return $operation(); }
        finally { --self::$persistenceDepth; }
    }
    private ?PDO $pdo = null;
    private bool $persistent = false;
    private int $pid = 0;
    private bool $transactionOpen = false;
    private float $lastPing = 0.0;
    private static ?\WeakMap $instances = null;

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

    /** Отдельное соединение: служебный commit не участвует в игровой транзакции. */
    public static function independent(): self
    {
        $database = new self();
        $database->persistent = false;
        return $database;
    }

    /**
     * Приватный конструктор (singleton)
     */
    private function __construct()
    {
        self::$instances ??= new \WeakMap();
        self::$instances[$this] = true;
    }

    /** Закрываем PDO до fork: каждому игровому/сетевому/боевому процессу свой TCP. */
    public static function beforeFork(): void
    {
        foreach (self::$instances ?? [] as $database => $unused) {
            if ($database->transactionOpen) throw new \LogicException('Нельзя выполнить fork внутри SQL-транзакции.');
            $database->pdo = null;
            $database->pid = 0;
        }
    }

    /**
     * Проверка соединения и переподключение при необходимости
     */
    private function ensureConnection(): void
    {
        if (self::$runtimeSqlForbidden && self::$persistenceDepth === 0) {
            RuntimeMetrics::increment('storage.runtimeSqlRejected');
            throw new \LogicException('SQL в игровом runtime запрещён; используйте RAM-репозиторий.');
        }
        if ($this->pid !== 0 && $this->pid !== getmypid()) {
            if ($this->transactionOpen) throw new \RuntimeException('Транзакция принадлежит другому процессу.');
            $this->pdo = null;
        }
        if ($this->pdo === null) {
            if ($this->transactionOpen) throw new \RuntimeException('Соединение транзакции потеряно; переподключение запрещено.');
            $this->connect();
            return;
        }

        // В транзакции не заменяем соединение и не продолжаем её в autocommit.
        if ($this->transactionOpen || microtime(true) - $this->lastPing < 1.0) return;

        try {
            $this->pdo->query('SELECT 1');
            $this->lastPing = microtime(true);
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

        // ATTR_TIMEOUT ограничивает подключение, но не чтение ответа mysqlnd.
        // Без этого потерянный SQL-сокет может удерживать world/save locks сутки.
        $readTimeout = max(1, min(30, Environment::getInt('DB_READ_TIMEOUT', 5)));
        if (extension_loaded('mysqlnd') && ini_set('mysqlnd.net_read_timeout', (string)$readTimeout) === false) {
            throw new RuntimeException('Не задан таймаут чтения MySQL.');
        }

        try {
            $this->pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => $this->persistent,
                    PDO::ATTR_TIMEOUT => 3,
                ]
            );
            $this->pid = getmypid();
            $this->lastPing = microtime(true);
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

    /** Ограниченные janitor-пачки прекращаются сразу, когда backlog исчерпан. */
    public function executeAffected(string $sql, array $params = []): int
    {
        $this->ensureConnection();
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
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

    public function insert(string $sql, array $params = []): string
    {
        $this->ensureConnection();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->ensureConnection();
        $this->pdo->beginTransaction();
        $this->transactionOpen = true;
    }

    public function commit(): void
    {
        $this->ensureConnection();
        $this->pdo->commit();
        $this->transactionOpen = false;
    }

    public function rollBack(): void
    {
        try {
            $this->ensureConnection();
            $this->pdo->rollBack();
        } catch (\Throwable $error) {
            $this->pdo = null;
            throw $error;
        } finally { $this->transactionOpen = false; }
    }
}
