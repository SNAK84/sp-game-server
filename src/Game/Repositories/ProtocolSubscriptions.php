<?php

namespace SPGame\Game\Repositories;

use Swoole\Table;

/** Подписки живут только до закрытия данного WebSocket; данные мира не хранятся. */
final class ProtocolSubscriptions
{
    private const CAPACITY = 8192;
    private const PER_CONNECTION = 8;
    private static Table $table;
    /** Очередь только authority-процесса: ключ слота => время первого dirty.
     * Повторный dirty не меняет порядок; не более CAPACITY ключей, без копий DTO.
     * Подписки не переживают процесс/соединение, поэтому сохранять очередь не нужно.
     */
    private static array $pending = [];
    private static array $retryAt = [];

    public static function init(): void
    {
        self::$pending = self::$retryAt = [];
        // 1000 клиентов по 8 подписок; collision pool не должен уменьшать
        // эту вместимость при неблагоприятном распределении ключей.
        self::$table = new Table(self::CAPACITY, 1.0);
        foreach (['connection' => 32, 'id' => 36, 'name' => 64, 'parameters' => 2048, 'revision' => 20] as $key => $size) {
            self::$table->column($key, Table::TYPE_STRING, $size);
        }
        self::$table->column('fd', Table::TYPE_INT, 4);
        self::$table->column('account', Table::TYPE_INT, 8);
        self::$table->column('dirty', Table::TYPE_INT, 1);
        if (!self::$table->create()) throw new \RuntimeException('Не создан реестр подписок v2.');
    }

    public static function all(int $fd, string $connection): array
    {
        $rows = [];
        foreach (self::connectionRows($connection) as $key => $row) if ($row['fd'] === $fd) $rows[$key] = $row;
        return $rows;
    }

    /** У соединения не более 8 слотов: O(8), независимо от всего онлайна. */
    private static function connectionRows(string $connection): array
    {
        $rows = [];
        for ($slot = 0; $slot < self::PER_CONNECTION; ++$slot) {
            $key = self::slotKey($connection, $slot);
            $row = self::$table->get($key);
            if ($row !== false && $row['connection'] === $connection) $rows[$key] = $row;
        }
        return $rows;
    }

    private static function slotKey(string $connection, int $slot): string
    {
        return substr(hash('sha256', $connection . ':' . $slot), 0, 32);
    }

    public static function put(int $fd, int $account, string $connection, string $id, string $name, array $parameters): void
    {
        $existing = self::all($fd, $connection);
        $key = null;
        foreach ($existing as $candidate => $row) if ($row['id'] === $id) { $key = $candidate; break; }
        if ($key === null) {
            if (count($existing) >= self::PER_CONNECTION || self::$table->count() >= self::CAPACITY) throw new \DomainException('subscription.limit');
            for ($slot = 0; $slot < self::PER_CONNECTION; ++$slot) {
                $candidate = self::slotKey($connection, $slot);
                if (!self::$table->exists($candidate)) { $key = $candidate; break; }
            }
            if ($key === null) throw new \RuntimeException('Не найден свободный слот подписки v2.');
        }
        $payload = json_encode($parameters, JSON_THROW_ON_ERROR);
        if (strlen($payload) > 2048) throw new \DomainException('subscription.invalid_input');
        $row = compact('fd', 'account', 'connection', 'id', 'name') + ['parameters' => $payload, 'revision' => '', 'dirty' => 1];
        if (!self::$table->set($key, $row)) throw new \RuntimeException('Не сохранена подписка v2.');
        self::$pending[$key] ??= microtime(true);
        unset(self::$retryAt[$key]);
    }

    public static function delivered(string $connection, string $id, string $revision): void
    {
        foreach (self::connectionRows($connection) as $key => $row) if ($row['id'] === $id) {
            if (!self::$table->set($key, ['revision' => $revision, 'dirty' => 0])) {
                throw new \RuntimeException('Не подтверждена доставка подписки v2.');
            }
            unset(self::$pending[$key], self::$retryAt[$key]);
            return;
        }
    }

    public static function dirty(int $account, int $fd, string $connection): void
    {
        foreach (self::all($fd, $connection) as $key => $row) if ($row['account'] === $account) {
            if (!$row['dirty'] && !self::$table->set($key, ['dirty' => 1])) {
                throw new \RuntimeException('Не отмечено обновление подписки v2.');
            }
            self::$pending[$key] ??= microtime(true);
        }
    }

    public static function pendingCount(): int
    {
        return count(self::$pending);
    }

    /** Round-robin по подпискам, а не по fd: хвост не голодает при постоянном dirty. */
    public static function nextPending(): ?array
    {
        $key = array_key_first(self::$pending);
        if ($key === null) return null;
        $since = self::$pending[$key];
        unset(self::$pending[$key]);
        $row = self::$table->get($key);
        if ($row === false || !$row['dirty']) {
            unset(self::$retryAt[$key]);
            return null;
        }
        self::$pending[$key] = $since;
        return $row + ['retryAt' => self::$retryAt[$key] ?? 0.0];
    }

    /** Ошибка DTO/отправки не снимает dirty и не создаёт горячий цикл повторов. */
    public static function retryLater(string $connection, string $id): void
    {
        foreach (self::connectionRows($connection) as $key => $row) if ($row['id'] === $id) {
            self::$retryAt[$key] = microtime(true) + 1.0;
            return;
        }
    }

    /** Вызывается не чаще раза в секунду, не на каждую подписку/клиента. */
    public static function oldestPendingSeconds(): float
    {
        return self::$pending === [] ? 0.0 : max(0.0, microtime(true) - min(self::$pending));
    }

    public static function remove(string $connection, ?string $id = null): void
    {
        foreach (self::connectionRows($connection) as $key => $row) if ($id === null || $row['id'] === $id) {
            self::$table->del($key);
            unset(self::$pending[$key], self::$retryAt[$key]);
        }
    }
}
