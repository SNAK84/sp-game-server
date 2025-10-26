<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Logger;
use SPGame\Core\Defaults;

use SPGame\Game\Services\GalaxyGenerator;

use Swoole\Table;

class Galaxy extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'Galaxy';

    protected static string $tableName = 'galaxy';
    protected static array $tableSchema = [
        'columns' => [
            'id'           => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'galaxy'       => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'system'       => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'star_type'    => ['swoole' => [Table::TYPE_STRING, 8], 'sql' => 'VARCHAR(8) NOT NULL', 'default' => 'G'],
            'star_size'    => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'min_distance' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'max_distance' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'max_orbits'   => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_galaxy_system', 'type' => 'INDEX', 'fields' => ['galaxy', 'system']],
        ],
    ];

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    /** Индексы Swoole */
    protected static array $indexes = [
        'galaxy_system' => ['key' => ['galaxy', 'system'], 'Unique' => false]
    ];

    /** @var Table */
    protected static Table $syncTable;

    public static array $starTypes = [
        'O' => ['color' => 'lightblue',   'size' => [25, 30], 'range' => [800, 12000], 'max_orbits' => 15],
        'B' => ['color' => 'deepskyblue', 'size' => [22, 26], 'range' => [700, 10000], 'max_orbits' => 13],
        'A' => ['color' => 'white',       'size' => [18, 23], 'range' => [600, 9000],  'max_orbits' => 11],
        'F' => ['color' => 'beige',       'size' => [16, 20], 'range' => [600, 8000],  'max_orbits' => 9],
        'G' => ['color' => 'yellow',      'size' => [14, 18], 'range' => [600, 7000],  'max_orbits' => 7],
        'K' => ['color' => 'orange',      'size' => [12, 16], 'range' => [600, 6000],  'max_orbits' => 6],
        'M' => ['color' => 'red',         'size' => [10, 14], 'range' => [600, 5000],  'max_orbits' => 5],
    ];

    /**
     * Получить конкретную систему
     */
    public static function getSystem(int $galaxy, int $system): ?array
    {
        $star = self::findByIndex('galaxy_system', [$galaxy, $system]);
        if (!$star) $star[] = GalaxyGenerator::generateSystem($galaxy, $system);
        
        //GalaxyOrbits::findByIndex('galaxy_system', [$galaxy, $system]);

        return $star[0];
    }

}
