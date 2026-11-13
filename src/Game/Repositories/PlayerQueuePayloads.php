<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Logger;
use Swoole\{Lock, Table};

/** Ограниченный общий буфер больших событий, без MySQL и RepositorySaver. */
final class PlayerQueuePayloads
{
    public const MAX_BYTES = 16384;
    public const MAX_PENDING = 1024;
    public const MAX_PER_ACCOUNT = 4;
    public const TTL_SECONDS = 120;

    private static Table $table;
    private static Table $accounts;
    private static Lock $lock;
    private const IDENTITY = ['id', 'account_id', 'user_id', 'planet_id', 'action'];

    /** Вызывается до запуска WebSocket workers: Table и mutex должны быть общими. */
    public static function init(): void
    {
        if (isset(self::$table)) return;
        // Полный резерв коллизий позволяет вместить MAX_PENDING разных ключей.
        $table = new Table(self::MAX_PENDING, 1.0);
        foreach (['id', 'account_id', 'user_id', 'planet_id'] as $name) $table->column($name, Table::TYPE_INT, 4);
        $table->column('action', Table::TYPE_STRING, 32);
        $table->column('expires_at', Table::TYPE_FLOAT);
        $table->column('data', Table::TYPE_STRING, self::MAX_BYTES);
        $accounts = new Table(self::MAX_PENDING, 1.0);
        $accounts->column('pending', Table::TYPE_INT, 4);
        if (!$table->create() || !$accounts->create()) throw new \RuntimeException('Не удалось создать буфер PlayerQueue.');
        self::$lock = new Lock(SWOOLE_MUTEX);
        self::$accounts = $accounts;
        self::$table = $table;
    }

    public static function put(array $event, string $payload, ?float $ttlSeconds = null): void
    {
        $ttlSeconds ??= self::TTL_SECONDS;
        if (!is_finite($ttlSeconds) || $ttlSeconds <= 0 || $ttlSeconds > self::TTL_SECONDS) {
            throw new \InvalidArgumentException('Некорректный срок ожидания команды.');
        }
        if (strlen($payload) > self::MAX_BYTES) throw new \InvalidArgumentException('Команда слишком велика для очереди событий.');
        $rollbackFailure = null;
        self::acquire();
        try {
            $key = (string)$event['id'];
            $accountKey = (string)$event['account_id'];
            $before = self::$accounts->get($accountKey);
            $pending = (int)($before['pending'] ?? 0);
            if (self::$table->count() >= self::MAX_PENDING || $pending >= self::MAX_PER_ACCOUNT) {
                throw new \LogicException('Буфер команд занят. Дождитесь обработки предыдущих команд.');
            }
            if (self::$table->exists($key)) throw new \LogicException('Событие с таким ID уже ожидает обработки.');
            $row = self::identity($event) + ['expires_at' => microtime(true) + $ttlSeconds, 'data' => $payload];
            try {
                if (!self::$table->set($key, $row) || self::$table->get($key) !== $row
                    || !self::$accounts->set($accountKey, ['pending' => $pending + 1])) {
                    throw new \LogicException('Не удалось сохранить команду во временном буфере.');
                }
            } catch (\Throwable $error) {
                $removed = !self::$table->exists($key) || self::$table->del($key);
                $restored = $before === false
                    ? (!self::$accounts->exists($accountKey) || self::$accounts->del($accountKey))
                    : self::$accounts->set($accountKey, $before);
                if (!$removed || !$restored) $rollbackFailure = ['queueId' => $event['id'], 'originalMessage' => $error->getMessage()];
                throw $error;
            }
        } finally {
            self::$lock->unlock();
            if ($rollbackFailure !== null) {
                try { Logger::getInstance()->error('CRITICAL: rollback буфера PlayerQueue не выполнен', $rollbackFailure); }
                catch (\Throwable) { error_log('CRITICAL: rollback буфера PlayerQueue, queueId=' . $event['id']); }
            }
        }
    }

    /** Чтение не потребляет payload: очередь читают также проверки повторной отправки. */
    public static function read(array $event): string
    {
        return self::snapshot($event)['data'];
    }

    /** Полный payload и срок ожидания для финального снимка очереди. */
    public static function snapshot(array $event): array
    {
        self::acquire();
        try {
            $row = self::$table->get((string)$event['id']);
            if ($row === false || self::identity($row) !== self::identity($event)) {
                throw new \UnexpectedValueException('Временные данные события отсутствуют или принадлежат другому событию.');
            }
            if ($row['expires_at'] <= microtime(true)) throw new \UnexpectedValueException('Истёк срок ожидания команды.');
            return $row;
        } finally { self::$lock->unlock(); }
    }

    public static function release(array $event): void
    {
        if (!isset(self::$table) || !self::$table->exists((string)$event['id'])) return;
        self::acquire();
        try {
            $row = self::$table->get((string)$event['id']);
            if ($row !== false && self::identity($row) === self::identity($event)) self::removeLocked($row);
        } finally { self::$lock->unlock(); }
    }

    /** Возвращает только метаданные, чтобы удалить также просроченные ссылки из очереди. */
    public static function deleteExpired(float $now): array
    {
        if (!isset(self::$table)) return [];
        self::acquire();
        try {
            $expired = [];
            foreach (self::$table as $row) {
                if ($row['expires_at'] > $now) continue;
                $expired[] = self::identity($row);
            }
            // Удаление во время обхода Swoole Table может пропустить строки коллизий.
            foreach ($expired as $row) self::removeLocked($row);
            return $expired;
        } finally { self::$lock->unlock(); }
    }

    public static function count(): int
    {
        return isset(self::$table) ? self::$table->count() : 0;
    }

    private static function identity(array $event): array
    {
        $identity = [];
        foreach (self::IDENTITY as $field) $identity[$field] = $event[$field] ?? null;
        return $identity;
    }

    private static function acquire(): void
    {
        if (!isset(self::$table)) throw new \LogicException('Временный буфер PlayerQueue не инициализирован.');
        if (!self::$lock->lockwait(1.0)) throw new \LogicException('Не удалось заблокировать временный буфер команд. Повторите попытку.');
    }

    /** Вызывается только под общим mutex. */
    private static function removeLocked(array $row): void
    {
        if (!self::$table->del((string)$row['id'])) throw new \RuntimeException('Не удалось освободить временный payload.');
        $key = (string)$row['account_id'];
        $account = self::$accounts->get($key);
        $pending = (int)($account['pending'] ?? 0) - 1;
        $ok = $pending > 0 ? self::$accounts->set($key, ['pending' => $pending]) : self::$accounts->del($key);
        if (!$ok) throw new \RuntimeException('Не удалось освободить квоту временного буфера.');
    }
}
