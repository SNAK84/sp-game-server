<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\Table;

/** Последний оплаченный снимок игрока, а не разрешение на слежение за Fleet. */
final class PhalanxScans
{
    public const MAX_BYTES = 16384;
    public const RETENTION = 300;
    private static Table $table;

    public static function init(): void
    {
        if (isset(self::$table)) return;
        $table = new Table(1024, 1.0);
        $table->column('expires_at', Table::TYPE_FLOAT);
        $table->column('payload', Table::TYPE_STRING, self::MAX_BYTES);
        if (!$table->create()) throw new \RuntimeException('Не удалось создать буфер снимков фаланги.');
        self::$table = $table;
    }

    public static function pack(array $scan, float $now): array
    {
        $json = json_encode($scan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > 1048576) throw new \LogicException('Слишком большой результат сканирования.');
        $payload = base64_encode(gzcompress($json));
        if (strlen($payload) > self::MAX_BYTES) throw new \LogicException('Слишком большой результат сканирования.');
        return ['expires_at' => $now + self::RETENTION, 'payload' => $payload];
    }

    public static function row(int $userId): array|false
    {
        return isset(self::$table) ? self::$table->get((string)$userId) : false;
    }

    public static function put(int $userId, array $row): void
    {
        $expired = [];
        foreach (self::$table as $key => $old) if ($old['expires_at'] <= microtime(true)) $expired[] = (string)$key;
        foreach ($expired as $key) self::$table->del($key);
        if (!self::$table->set((string)$userId, $row) || self::row($userId) !== $row) {
            throw new \RuntimeException('Не удалось сохранить снимок фаланги.');
        }
    }

    public static function restore(int $userId, array|false $row): void
    {
        $ok = $row === false ? (!self::$table->exists((string)$userId) || self::$table->del((string)$userId))
            : self::$table->set((string)$userId, $row);
        if (!$ok || self::row($userId) !== $row) throw new \RuntimeException('Не восстановлен буфер фаланги.');
    }

    private static function read(int $userId, ?float $now): ?array
    {
        $row = self::row($userId);
        if ($row === false || $row['expires_at'] <= ($now ?? microtime(true))) return null;
        $json = gzuncompress(base64_decode($row['payload'], true), 1048576);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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
