<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use Swoole\Table;

/** Общая цель/срок и явные участники САБ; команды и корабли остаются в своих repositories. */
final class FleetAttackGroups extends BaseRepository
{
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static string $className = 'FleetAttackGroups';
    protected static string $tableName = 'fleet_attack_groups';
    protected static int $tableSize = 1024;
    protected static array $indexes = [];
    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'default' => Defaults::AUTOID],
            'owner_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'target_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'execute_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE NOT NULL', 'default' => 0.0],
            'users' => ['swoole' => [Table::TYPE_STRING, 1024], 'sql' => 'TEXT NOT NULL', 'default' => '[]'],
            'fleets' => ['swoole' => [Table::TYPE_STRING, 2048], 'sql' => 'TEXT NOT NULL', 'default' => '[]'],
        ],
        'indexes' => [['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']]],
    ];

    public static function rows(): array
    {
        return isset(static::$table) ? (static::getAll() ?? []) : [];
    }

    public static function store(array $row): void
    {
        $row = static::castRowToSchema($row, true);
        if (strlen($row['users']) >= 1024 || strlen($row['fleets']) >= 2048) {
            throw new \OverflowException('Переполнен список участников групповой атаки.');
        }
        if (static::findExistingById((int)$row['id']) === null) static::add($row);
        else static::update($row);
        if (static::findExistingById((int)$row['id']) !== $row) {
            throw new \RuntimeException('Не удалось сохранить групповую атаку в runtime.');
        }
    }
}
