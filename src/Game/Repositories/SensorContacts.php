<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\Table;

/** Один атомарный ограниченный снимок на наблюдателя; не участвует в MySQL sync. */
final class SensorContacts
{
    public const MAX_BYTES = 16384;
    private static Table $table;

    public static function init(): void
    {
        $table = new Table(1024, 1.0);
        $table->column('expires_at', Table::TYPE_FLOAT);
        $table->column('payload', Table::TYPE_STRING, self::MAX_BYTES);
        if (!$table->create()) throw new \RuntimeException('Не создан буфер сенсорных контактов.');
        self::$table = $table;
    }

    public static function viewers(): array
    {
        $ids = [];
        if (isset(self::$table)) foreach (self::$table as $id => $row) $ids[] = (int)$id;
        return $ids;
    }

    public static function get(int $viewerId): array
    {
        $row = isset(self::$table) ? self::$table->get((string)$viewerId) : false;
        if ($row === false) return [];
        return json_decode(gzuncompress(base64_decode($row['payload'], true), 1048576), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function put(int $viewerId, array $contacts): void
    {
        if (!$contacts) {
            if (isset(self::$table) && self::$table->exists((string)$viewerId) && !self::$table->del((string)$viewerId)) {
                throw new \RuntimeException('Не удалены истёкшие сенсорные контакты.');
            }
            return;
        }
        $json = json_encode($contacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > 1048576) throw new \LogicException('Слишком большой снимок сенсорных контактов.');
        $payload = base64_encode(gzcompress($json));
        if (strlen($payload) > self::MAX_BYTES) throw new \LogicException('Переполнен снимок сенсорных контактов.');
        $row = ['expires_at' => max(array_column($contacts, 'retainUntil')), 'payload' => $payload];
        if (!self::$table->set((string)$viewerId, $row)) throw new \RuntimeException('Не сохранены сенсорные контакты.');
    }
}
