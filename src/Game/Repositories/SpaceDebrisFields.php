<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use SPGame\Game\Space\{Coordinate, CoordinateType};
use Swoole\Table;

/** Обломки в неподвижной POINT боя; не привязаны к планете или орбите. */
final class SpaceDebrisFields extends BaseRepository
{
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static string $className = 'SpaceDebrisFields';
    protected static string $tableName = 'space_debris_fields';
    protected static int $tableSize = 32768;
    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'battle_id' => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => 'CHAR(32) NOT NULL', 'default' => ''],
            'galaxy' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'system' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'distance' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
            'angle' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
            'metal' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL DEFAULT 0', 'default' => 0.0],
            'crystal' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL DEFAULT 0', 'default' => 0.0],
            'update_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'uniq_space_debris_battle', 'type' => 'UNIQUE', 'fields' => ['battle_id']],
            ['name' => 'idx_space_debris_system', 'type' => 'INDEX', 'fields' => ['galaxy', 'system']],
        ],
    ];
    protected static array $indexes = [
        'battle_id' => ['key' => ['battle_id'], 'Unique' => true],
    ];

    public static function findById(int $id): ?array
    {
        return isset(static::$table) ? (static::$table->get((string)$id) ?: null) : null;
    }

    public static function point(array $row): Coordinate
    {
        return Coordinate::point((int)$row['galaxy'], (int)$row['system'], (float)$row['distance'], (float)$row['angle']);
    }

    public static function inSystem(int $galaxy, int $system): array
    {
        $result = [];
        // В одной системе количество полей не ограничено числом орбит.
        // Общий BaseRepository non-unique index имеет лишь 2048 байт на список
        // IDs: для истории множества боёв он может молча усечь этот список.
        if (isset(static::$table)) foreach (static::$table as $row) {
            if ((int)$row['galaxy'] === $galaxy && (int)$row['system'] === $system && $row['metal'] + $row['crystal'] > 0) {
                $result[(int)$row['id']] = $row;
            }
        }
        return $result;
    }

    public static function atPoint(Coordinate $point): array
    {
        if ($point->getType() !== CoordinateType::POINT) return [];
        $x = $point->getDistance() * cos(deg2rad($point->getAngle()));
        $y = $point->getDistance() * sin(deg2rad($point->getAngle()));
        return array_filter(self::inSystem($point->getGalaxy(), $point->getSystem()), static fn($row) => hypot(
            $x - $row['distance'] * cos(deg2rad($row['angle'])), $y - $row['distance'] * sin(deg2rad($row['angle']))) <= .05);
    }

    /** Только публичная карта: без battle_id, участников и состояния боя. */
    public static function publicInSystem(int $galaxy, int $system): array
    {
        return array_values(array_map(static fn(array $row): array => [
            'id' => (int)$row['id'], 'coordinate' => self::point($row)->toArray(),
            'metal' => (float)$row['metal'], 'crystal' => (float)$row['crystal'],
        ], self::inSystem($galaxy, $system)));
    }

    /** Нулевой остаток сохраняется: повторный бой/flush не создаёт ресурс заново. */
    public static function subtract(int $id, float $metal, float $crystal, float $time): void
    {
        $row = self::findById($id);
        if ($row === null || !is_finite($metal) || !is_finite($crystal) || !is_finite($time)
            || $time <= 0 || $metal < 0 || $crystal < 0 || $metal > $row['metal'] || $crystal > $row['crystal']) {
            throw new \LogicException('Некорректное списание обломков в пространстве.');
        }
        if ($metal + $crystal === 0.0) return;
        $row['metal'] = max(0.0, $row['metal'] - $metal);
        $row['crystal'] = max(0.0, $row['crystal'] - $crystal);
        $row['update_time'] = $time;
        self::update($row);
        if (self::findById($id) !== $row || !in_array($id, self::getPendingSync()['update'], true)) {
            throw new \RuntimeException('Не сохранён остаток обломков в пространстве.');
        }
    }
}
