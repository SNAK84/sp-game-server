<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Logger;
use SPGame\Core\Defaults;

use SPGame\Game\Services\Lang;

use Swoole\Table;

class GalaxyOrbits extends BaseRepository
{
    
    public const EMPTY         = 0;
    public const ASTEROID_BELT = 1;
    public const GAS_GIANT     = 2;
    public const PLANET        = 5;

    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'GalaxyOrbits';

    protected static string $tableName = 'galaxy_orbits';
    protected static array $tableSchema = [
        'columns' => [
            'id'           => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'galaxy'       => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'system'       => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'orbit'        => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'distance'     => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'type'         => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_galaxy_system_orbit', 'type' => 'INDEX', 'fields' => ['galaxy', 'system', 'orbit']],
        ],
    ];

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    /** Индексы Swoole */
    protected static array $indexes = [
        'galaxy_system_orbit' => ['key' => ['galaxy', 'system', 'orbit'], 'Unique' => false],
        'galaxy_system' => ['key' => ['galaxy', 'system'], 'Unique' => false]
    ];

    /** @var Table */
    protected static Table $syncTable;
}
