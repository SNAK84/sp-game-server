<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Logger;
use SPGame\Core\Defaults;

use SPGame\Game\Services\RepositorySaver;

use Swoole\Table;

class FleetsShips extends BaseRepository
{
    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'FleetsShips';
    protected static string $tableName = 'fleets_ships';

    /** @var array Схема таблицы */
    protected static array $tableSchema = [
        'columns' => [
            'id'        => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'fleet_id'  => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) NOT NULL', 'default' => 0],
            'owner_id'  => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) NOT NULL DEFAULT 0', 'default' => 0,],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_fleet', 'type' => 'INDEX', 'fields' => ['fleet_id']],
        ],
    ];

    /** Индексы для Swoole */
    protected static array $indexes = [
        'owner_id' => ['key' => ['owner_id'], 'Unique' => false],
        'fleet_id' => ['key' => ['fleet_id'], 'Unique' => false],
    ];

    /** Таблица для синхронизации */
    protected static Table $syncTable;

    public static function init(?RepositorySaver $saver = null): void
    {

        foreach (Vars::$reslist['fleet'] as $ResID) {
            $name = Vars::$resource[$ResID];
            self::$tableSchema['columns']["{$name}_count"] = [
                'swoole' => [Table::TYPE_INT, 8],
                'sql'    => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
                'default' => 0,
            ];
            self::$tableSchema['columns']["{$name}_damage"] = [
                'swoole' => [Table::TYPE_FLOAT, 8],
                'sql'    => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0',
                'default' => 0.0,
            ];
            self::$tableSchema['columns']["{$name}_experience"] = [
                'swoole' => [Table::TYPE_FLOAT, 8],
                'sql'    => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0',
                'default' => 0.0,
            ];
            self::$tableSchema['columns']["{$name}_morale"] = [
                'swoole' => [Table::TYPE_FLOAT, 8],
                'sql'    => 'DOUBLE(17,6) NOT NULL DEFAULT 1.0',
                'default' => 1.0,
            ];
            self::$tableSchema['columns']["{$name}_status"] = [
                'swoole' => [Table::TYPE_INT, 1],
                'sql'    => 'TINYINT(1) NOT NULL DEFAULT 0', // 0 — в строю, 1 — повреждён, 2 — уничтожен
                'default' => 0,
            ];
        }

        parent::init($saver);
    }
}
