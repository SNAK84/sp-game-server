<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\Table;

/** Один текущий бой на планете. Записывает только координатор игрового цикла. */
final class PlanetBattles extends BaseRepository
{
    use BattleCheckpointCleanup;

    public const MAX_STATE_BYTES = 262144;
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static array $indexes = [];
    protected static string $className = 'PlanetBattles';
    protected static string $tableName = 'planet_battles';
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
        // До init() не существует боёв (в том числе в изолированных старых tests).
        return isset(static::$table) ? (static::$table->get((string)$id) ?: null) : null;
    }

    public static function state(int $planetId): ?array
    {
        $row = self::findById($planetId);
        return $row === null ? null : json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** Ограничение проверяется до записи: Swoole не должен молча обрезать snapshot. */
    public static function store(int $planetId, array $state): void
    {
        if ($planetId <= 0 || !in_array($state['phase'] ?? null, ['running', 'finished', 'settled', 'failed', 'applied'], true)) {
            throw new \InvalidArgumentException('Некорректное состояние боя планеты.');
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > self::MAX_STATE_BYTES) {
            throw new \OverflowException('Состояние боя превышает вместимость runtime записи.');
        }
        $before = static::findExistingById($planetId);
        // Общий путь проверяет бюджет, подтверждает байты/поколение и откатывает
        // main+marker под тем же runtime-lock, что захват/ACK штатного saver.
        static::writeRuntimeRow(['id' => $planetId, 'phase' => $state['phase'], 'state' => $encoded],
            $before, true, $before === null);
    }

    public static function active(): array
    {
        $result = [];
        if (!isset(static::$table)) return $result;
        foreach (static::$table as $row) {
            if ($row['phase'] !== 'applied') {
                $result[(int)$row['id']] = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return $result;
    }
}
