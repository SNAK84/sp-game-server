<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\Table;

/** Checkpoint боя в пространстве с собственным ID, НЕ ID планеты/целевого Fleet. */
final class SpaceBattles extends BaseRepository
{
    use BattleCheckpointCleanup;

    public const MAX_STATE_BYTES = PlanetBattles::MAX_STATE_BYTES;
    // Бюджет временного набора ID на один обход. Размер боя им не ограничен.
    private const FLEET_LOOKUP_BUDGET = 4096;
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

    /** Проверка участия для серии чтений до следующего изменения боёв. */
    public static function fleetPresenceReader(): \Closure
    {
        $keys = null;
        $offset = 0;
        $members = [];
        return static function (int $fleetId) use (&$keys, &$offset, &$members): bool {
            if ($members === null) return self::forFleet($fleetId) !== null;
            if (isset($members[$fleetId])) return true;
            $keys ??= self::activeKeys();
            while (isset($keys[$offset])) {
                $row = self::findExistingById($keys[$offset]);
                if ($row !== null && $row['phase'] !== 'applied') {
                    $ids = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR)['fleets'];
                    // При ошибке чтения курсор остаётся на этой строке.
                    if (count($members) + count($ids) > self::FLEET_LOOKUP_BUDGET) {
                        $members = null;
                        unset($row, $ids);
                        return self::forFleet($fleetId) !== null;
                    }
                    foreach ($ids as $id) {
                        if (is_int($id)) $members[$id] = true;
                    }
                }
                ++$offset;
                if (isset($members[$fleetId])) return true;
            }
            return false;
        };
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

}
