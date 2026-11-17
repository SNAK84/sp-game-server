<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\Table;

/** Checkpoint боя в пространстве с собственным ID, НЕ ID планеты/целевого Fleet. */
final class SpaceBattles extends BaseRepository
{
    use BattleCheckpointCleanup;

    public const MAX_STATE_BYTES = PlanetBattles::MAX_STATE_BYTES;
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static array $indexes = [];
    protected static string $className = 'SpaceBattles';
    protected static string $tableName = 'space_battles';
    protected static int $tableSize = 128;
    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'phase' => ['swoole' => [Table::TYPE_STRING, 16], 'sql' => 'VARCHAR(16) NOT NULL', 'default' => 'running'],
            'state' => ['swoole' => [Table::TYPE_STRING, self::MAX_STATE_BYTES], 'sql' => 'MEDIUMTEXT NOT NULL', 'default' => ''],
        ],
        'indexes' => [['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']]],
    ];

    public static function findById(int $id): ?array
    {
        return isset(static::$table) ? (static::$table->get((string)$id) ?: null) : null;
    }

    public static function state(int $key): ?array
    {
        $row = self::findById($key);
        return $row === null ? null : json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function store(int $key, array $state): void
    {
        if ($key <= 0 || !in_array($state['phase'] ?? null, ['running', 'finished', 'settled', 'failed', 'applied'], true)) {
            throw new \InvalidArgumentException('Некорректное состояние боя в пространстве.');
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > self::MAX_STATE_BYTES) throw new \OverflowException('Состояние боя превышает вместимость runtime записи.');
        $before = static::findExistingById($key);
        static::writeRuntimeRow(['id' => $key, 'phase' => $state['phase'], 'state' => $encoded],
            $before, true, $before === null);
    }

    public static function active(): array
    {
        $result = [];
        if (isset(static::$table)) foreach (static::$table as $row) if ($row['phase'] !== 'applied') {
            $result[(int)$row['id']] = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $result;
    }

    public static function forFleet(int $fleetId): ?array
    {
        foreach (self::active() as $key => $state) if (in_array($fleetId, $state['fleets'], true)) return ['key' => $key, 'state' => $state];
        return null;
    }

}
