<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\Table;
use SPGame\Core\{Environment, RuntimeMetrics};

/** Один атомарный ограниченный снимок на наблюдателя; не участвует в MySQL sync. */
final class SensorContacts
{
    public const MAX_BYTES = 16384;
    public const MAX_JSON_BYTES = 1048576;
    private static Table $table;

    /** Это число всех наблюдателей с контактами, а не online-соединений. */
    public static function capacity(): int
    {
        $budget = RuntimeCapacity::budget('SensorContacts', 1024);
        $limit = Environment::getInt('SENSOR_CONTACT_VIEWERS_LIMIT', $budget);
        if ($limit < 1 || $limit > $budget) throw new \InvalidArgumentException('Неверный лимит буфера сенсорных контактов.');
        return $limit;
    }

    public static function init(): void
    {
        $table = new Table(self::capacity(), 1.0);
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

    /** Один metadata-проход вместо прежнего viewers(): TTL освобождает места
     * до публикации новых наблюдателей. Действующие снимки не вытесняются.
     * Возвращаем удалённых владельцев для обычного обновления клиентов.
     */
    public static function sweep(float $now): array
    {
        $viewers = $expired = [];
        if (isset(self::$table)) foreach (self::$table as $id => $row) {
            if ($row['expires_at'] <= $now) {
                if (!self::$table->del((string)$id)) throw new \RuntimeException('Не удалены истёкшие сенсорные контакты.');
                $expired[] = (int)$id;
                RuntimeMetrics::increment('sensor.cacheExpired');
            } else $viewers[] = (int)$id;
        }
        return ['viewers' => $viewers, 'expired' => $expired];
    }

    public static function recordMetrics(): void
    {
        RuntimeMetrics::gauge('sensor.cacheViewers', isset(self::$table) ? self::$table->count() : 0);
        RuntimeMetrics::gauge('sensor.cacheCapacity', self::capacity());
    }

    public static function get(int $viewerId): array
    {
        $row = isset(self::$table) ? self::$table->get((string)$viewerId) : false;
        if ($row === false) return [];
        return json_decode(gzuncompress(base64_decode($row['payload'], true), self::MAX_JSON_BYTES), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function put(int $viewerId, array $contacts): bool
    {
        if (!$contacts) {
            if (isset(self::$table) && self::$table->exists((string)$viewerId) && !self::$table->del((string)$viewerId)) {
                throw new \RuntimeException('Не удалены истёкшие сенсорные контакты.');
            }
            return true;
        }
        // Ожидаемый backpressure, не ошибка мира и не повод удалить чужой
        // действующий снимок. sweep() уже выполнен сервисом до нового скана.
        if (!self::$table->exists((string)$viewerId) && self::$table->count() >= self::capacity()) {
            RuntimeMetrics::increment('sensor.cacheRejected');
            return false;
        }
        $json = json_encode($contacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > self::MAX_JSON_BYTES) {
            RuntimeMetrics::increment('sensor.cacheOversized');
            return false;
        }
        $payload = base64_encode(gzcompress($json));
        if (strlen($payload) > self::MAX_BYTES) {
            RuntimeMetrics::increment('sensor.cacheOversized');
            return false;
        }
        $row = ['expires_at' => max(array_column($contacts, 'retainUntil')), 'payload' => $payload];
        if (!self::$table->set((string)$viewerId, $row)) throw new \RuntimeException('Не сохранены сенсорные контакты.');
        return true;
    }
}
