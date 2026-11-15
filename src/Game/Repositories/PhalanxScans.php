<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\Table;

/** Последний оплаченный снимок игрока, а не разрешение на слежение за Fleet. */
final class PhalanxScans
{
    public const MAX_BYTES = 16384;
    public const MAX_JSON_BYTES = 1048576;
    public const RETENTION = 300;
    private static Table $table;
    private static int $capacity = 0;

    /** После init бюджет соответствует уже выделенной Table. */
    public static function capacity(): int
    {
        return self::$capacity > 0 ? self::$capacity : RuntimeCapacity::budget('PhalanxScans', 1024);
    }

    public static function init(): void
    {
        if (isset(self::$table)) return;
        $capacity = self::capacity();
        $table = new Table($capacity, 1.0);
        $table->column('expires_at', Table::TYPE_FLOAT);
        $table->column('payload', Table::TYPE_STRING, self::MAX_BYTES);
        if (!$table->create()) throw new \RuntimeException('Не удалось создать буфер снимков фаланги.');
        self::$capacity = $capacity;
        self::$table = $table;
    }

    public static function pack(array $scan, float $now): array
    {
        $json = json_encode($scan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_finite($now)) throw new \InvalidArgumentException('Некорректное время сканирования.');
        if (strlen($json) > self::MAX_JSON_BYTES) throw new \LogicException('Слишком большой результат сканирования.');
        $compressed = gzcompress($json);
        if ($compressed === false) throw new \RuntimeException('Не упакован снимок фаланги.');
        $payload = base64_encode($compressed);
        if (strlen($payload) > self::MAX_BYTES) throw new \LogicException('Слишком большой результат сканирования.');
        return ['expires_at' => $now + self::RETENTION, 'payload' => $payload];
    }

    public static function row(int $userId): array|false
    {
        return isset(self::$table) ? self::$table->get((string)$userId) : false;
    }

    /** Только single-authority игровой процесс; подготовка до списания дейтерия. */
    public static function assertWritable(int $userId): void
    {
        if ($userId <= 0) throw new \InvalidArgumentException('Некорректный владелец снимка фаланги.');
        // Один снимок на аккаунт: обновлению не нужно новое место. TTL всё
        // равно проверяется при чтении; физически освобождаем истёкшие строки
        // только когда новому владельцу не хватает места, а не на каждом скане.
        if (self::$table->exists((string)$userId) || self::$table->count() < self::capacity()) return;
        $expired = [];
        $now = microtime(true);
        // Не копируем до16KiB payload каждой живой записи только ради TTL.
        for (self::$table->rewind(); self::$table->valid(); self::$table->next()) {
            $key = (string)self::$table->key();
            $expires = self::$table->get($key, 'expires_at');
            if ($expires !== false && $expires <= $now) $expired[] = $key;
        }
        // Удаляем после обхода: удаление головы collision-цепочки сдвигает строки.
        foreach ($expired as $key) if (!self::$table->del($key)) throw new \RuntimeException('Не удалён истёкший снимок фаланги.');
        if (!self::$table->exists((string)$userId) && self::$table->count() >= self::capacity()) {
            throw new \OverflowException('Буфер снимков фаланги занят. Повторите сканирование позже.');
        }
    }

    public static function put(int $userId, array $row): void
    {
        self::validateRow($row);
        self::assertWritable($userId);
        $before = self::row($userId);
        try {
            if (!self::$table->set((string)$userId, $row) || self::row($userId) !== $row) {
                throw new \RuntimeException('Не удалось сохранить снимок фаланги.');
            }
        } catch (\Throwable $error) {
            self::restore($userId, $before);
            throw $error;
        }
    }

    private static function validateRow(array $row): void
    {
        if (!is_float($row['expires_at'] ?? null) || !is_finite($row['expires_at'])
            || !is_string($row['payload'] ?? null) || strlen($row['payload']) > self::MAX_BYTES
            || count($row) !== 2) throw new \InvalidArgumentException('Некорректная запись снимка фаланги.');
    }

    public static function restore(int $userId, array|false $row): void
    {
        if ($userId <= 0) throw new \InvalidArgumentException('Некорректный владелец снимка фаланги.');
        if ($row !== false) self::validateRow($row);
        $ok = $row === false ? (!self::$table->exists((string)$userId) || self::$table->del((string)$userId))
            : self::$table->set((string)$userId, $row);
        if (!$ok || self::row($userId) !== $row) throw new \RuntimeException('Не восстановлен буфер фаланги.');
    }

    private static function read(int $userId, ?float $now): ?array
    {
        $row = self::row($userId);
        if ($row === false || $row['expires_at'] <= ($now ?? microtime(true))) return null;
        $compressed = base64_decode($row['payload'], true);
        $json = $compressed === false ? false : @gzuncompress($compressed, self::MAX_JSON_BYTES);
        if ($json === false) throw new \UnexpectedValueException('Повреждён снимок фаланги.');
        $scan = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($scan)) throw new \UnexpectedValueException('Некорректный формат снимка фаланги.');
        return $scan;
    }

    /** Обычный Page/ActualData никогда не выдаёт внутреннюю физическую траекторию. */
    public static function get(int $userId, ?float $now = null): ?array
    {
        $scan = self::read($userId, $now);
        if ($scan !== null) unset($scan['_observations']);
        return $scan;
    }

    public static function observation(int $userId, int $fleetId, float $now): ?array
    {
        $scan = self::read($userId, $now);
        $observation = $scan['_observations'][$fleetId] ?? null;
        return $observation === null ? null : $observation + ['freshUntil' => $scan['scannedAt'] + self::RETENTION];
    }
}
