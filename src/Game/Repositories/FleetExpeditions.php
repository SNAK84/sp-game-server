<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\Table;

/** Один незавершённый результат экспедиции на Fleet; сохраняется штатным saver. */
final class FleetExpeditions extends BaseRepository
{
    public const MAX_BYTES = 262144;
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexes = [];
    protected static array $indexTables = [];
    protected static int $tableSize = 128;
    protected static string $className = 'FleetExpeditions';
    protected static string $tableName = 'fleet_expeditions';
    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'state' => ['swoole' => [Table::TYPE_STRING, self::MAX_BYTES], 'sql' => 'MEDIUMTEXT NOT NULL', 'default' => ''],
        ],
        'indexes' => [['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']]],
    ];

    public static function state(int $fleetId): ?array
    {
        $row = isset(static::$table) ? (static::$table->get((string)$fleetId) ?: null) : null;
        return $row === null ? null : json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function row(int $fleetId, array $state): array
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_BYTES) throw new \OverflowException('Состояние экспедиции не помещается в runtime запись.');
        return ['id' => $fleetId, 'state' => $json];
    }

    public static function store(int $fleetId, array $state): void
    {
        $row = self::row($fleetId, $state);
        if (static::findExistingById($fleetId) === null) static::add($row); else static::update($row);
        if (static::findExistingById($fleetId) !== $row) throw new \RuntimeException('Не удалось сохранить состояние экспедиции.');
    }
}
