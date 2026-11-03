<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use Swoole\Table;

/**
 * Постоянные добываемые объекты на орбитах системы.
 *
 * Газовый гигант занимает sector=0. Пояс астероидов разбивается на
 * независимые секторы 1..N, которые имеют общую орбитальную скорость и
 * направление, но отдельные запасы, качество и будущий контроль флота.
 */
class OrbitalResourceSites extends BaseRepository
{
    public const GAS_GIANT = 1;
    public const ASTEROID_SECTOR = 2;

    protected static Table $table;
    protected static Table $syncTable;

    protected static string $className = 'OrbitalResourceSites';
    protected static string $tableName = 'orbital_resource_sites';
    protected static int $tableSize = 32768;

    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'galaxy' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'system' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'orbit' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL', 'default' => 0],
            'site_type' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT UNSIGNED NOT NULL', 'default' => 0],
            'sector' => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT UNSIGNED NOT NULL', 'default' => 0],

            'anchor_angle' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(9,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'arc_width' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(9,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'speed' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(12,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'rotation' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1', 'default' => 1],
            'position_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => Defaults::MICROTIME],

            'size' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'visual_variant' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'ring_variant' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],

            'quality' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(8,6) NOT NULL DEFAULT 1.0', 'default' => 1.0],
            'survey_difficulty' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'capacity_hours' => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],

            'metal_rate' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'crystal_rate' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'deuterium_rate' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'metal_capacity' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'crystal_capacity' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'deuterium_capacity' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'metal_stock' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'crystal_stock' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'deuterium_stock' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'resource_update_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => Defaults::MICROTIME],

            'controller_fleet_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'uniq_orbital_resource_site', 'type' => 'UNIQUE', 'fields' => ['galaxy', 'system', 'orbit', 'sector']],
            ['name' => 'idx_orbital_resource_system', 'type' => 'INDEX', 'fields' => ['galaxy', 'system']],
            ['name' => 'idx_orbital_resource_orbit', 'type' => 'INDEX', 'fields' => ['galaxy', 'system', 'orbit']],
            ['name' => 'idx_orbital_resource_controller', 'type' => 'INDEX', 'fields' => ['controller_fleet_id']],
        ],
    ];

    protected static array $indexTables = [];
    protected static array $indexes = [
        'galaxy_system_orbit_sector' => [
            'key' => ['galaxy', 'system', 'orbit', 'sector'],
            'Unique' => true,
        ],
        'galaxy_system_orbit' => [
            'key' => ['galaxy', 'system', 'orbit'],
            'Unique' => false,
        ],
        'galaxy_system' => [
            'key' => ['galaxy', 'system'],
            'Unique' => false,
        ],
    ];
}
