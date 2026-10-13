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
            'id'           => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'galaxy'       => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'system'       => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'star_type'    => ['swoole' => [Table::TYPE_STRING, 8], 'sql' => 'VARCHAR(8) NOT NULL', 'default' => 'G'],
            'star_size'    => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'min_distance' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'max_distance' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'max_orbits'   => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 16],
            'rotation'     => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1', 'default' => 1],
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

    /**
     * Единый профиль звёзд для генератора и представления галактики.
     * mass/radius указаны в долях Солнца, temperature — в Кельвинах.
     */
    public static array $starTypes = [
        'O' => ['color' => 'lightblue',   'size' => [25, 30], 'temperature' => [30000, 45000], 'mass' => [16.0, 40.0], 'radius' => [6.6, 12.0], 'max_orbits' => 15],
        'B' => ['color' => 'deepskyblue', 'size' => [22, 26], 'temperature' => [10000, 30000], 'mass' => [2.1, 16.0],  'radius' => [1.8, 6.6],  'max_orbits' => 13],
        'A' => ['color' => 'white',       'size' => [18, 23], 'temperature' => [7500, 10000],  'mass' => [1.4, 2.1],   'radius' => [1.4, 1.8],  'max_orbits' => 11],
        'F' => ['color' => 'beige',       'size' => [16, 20], 'temperature' => [6000, 7500],   'mass' => [1.04, 1.4],  'radius' => [1.15, 1.4], 'max_orbits' => 9],
        'G' => ['color' => 'yellow',      'size' => [14, 18], 'temperature' => [5200, 6000],   'mass' => [0.8, 1.04],  'radius' => [0.96, 1.15], 'max_orbits' => 7],
        'K' => ['color' => 'orange',      'size' => [12, 16], 'temperature' => [3700, 5200],   'mass' => [0.45, 0.8],  'radius' => [0.7, 0.96], 'max_orbits' => 6],
        'M' => ['color' => 'red',         'size' => [10, 14], 'temperature' => [2400, 3700],   'mass' => [0.08, 0.45], 'radius' => [0.1, 0.7],  'max_orbits' => 5],
    ];

    /**
     * Получить конкретную систему
     */
    public static function getSystem(int $galaxy, int $system): ?array
    {
        $star = self::findByIndex('galaxy_system', [$galaxy, $system]);
        if (!$star) $star[] = GalaxyGenerator::generateSystem($galaxy, $system);

        GalaxyGenerator::ensureSpecialOrbits($star[0]);

        return $star[0];
    }

}
