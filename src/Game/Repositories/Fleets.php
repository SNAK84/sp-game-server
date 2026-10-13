<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use SPGame\Game\Services\RepositorySaver;
use SPGame\Game\Space\CoordinateType;
use Swoole\Table;

class Fleets extends BaseRepository
{
    protected static Table $table;

    protected static string $className = 'Fleets';
    protected static string $tableName = 'fleets';

    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'owner_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) NOT NULL', 'default' => 0],
            'home_planet_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],

            // === Координата начала текущей миссии ===
            'start_coordinate_type' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'start_galaxy' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'start_system' => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(3) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'start_orbit' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'start_object_type' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'start_distance' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'start_angle' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],

            // === Целевая координата текущей миссии ===
            'target_coordinate_type' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'target_fleet_id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'target_galaxy' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'target_system' => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(3) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'target_orbit' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'target_object_type' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'target_distance' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'target_angle' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'target_snapshot_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],

            // === Последняя зафиксированная физическая позиция ===
            'current_coordinate_type' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 1', 'default' => CoordinateType::OBJECT->value],
            'current_galaxy' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'current_system' => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(3) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'current_orbit' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'current_object_type' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0', 'default' => 0],
            'current_distance' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'current_angle' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],
            'current_position_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],

            // === Состояние и поведение ===
            'mission' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2)', 'default' => 0],
            'status' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) NOT NULL', 'default' => 0],
            'is_return' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) NOT NULL', 'default' => 0],
            'speed' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],

            // === Ресурсы и топливо ===
            'fuel_total' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'fuel_current' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],

            // === Статистика боя / эффективности ===
            'experience' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'morale' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 1.0],
            'damage_factor' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 1.0],
            'efficiency' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 1.0],

            'create_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => Defaults::MICROTIME],
            'update_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => Defaults::MICROTIME],
        ],

        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_owner', 'type' => 'INDEX', 'fields' => ['owner_id']],
            ['name' => 'idx_start_coordinate', 'type' => 'INDEX', 'fields' => ['start_coordinate_type', 'start_galaxy', 'start_system', 'start_orbit', 'start_object_type']],
            ['name' => 'idx_current_coordinate', 'type' => 'INDEX', 'fields' => ['current_coordinate_type', 'current_galaxy', 'current_system', 'current_orbit', 'current_object_type']],
            ['name' => 'idx_target_coordinate', 'type' => 'INDEX', 'fields' => ['target_coordinate_type', 'target_galaxy', 'target_system', 'target_orbit', 'target_object_type']],
            ['name' => 'idx_target_fleet', 'type' => 'INDEX', 'fields' => ['target_coordinate_type', 'target_fleet_id']],
            ['name' => 'idx_status', 'type' => 'INDEX', 'fields' => ['status']],
        ],
    ];

    protected static array $indexes = [
        'owner_id' => ['key' => ['owner_id'], 'Unique' => false],
        'status' => ['key' => ['status'], 'Unique' => false],
        'start_coordinate' => [
            'key' => ['start_coordinate_type', 'start_galaxy', 'start_system', 'start_orbit', 'start_object_type'],
            'Unique' => false,
        ],
        'current_coordinate' => [
            'key' => ['current_coordinate_type', 'current_galaxy', 'current_system', 'current_orbit', 'current_object_type'],
            'Unique' => false,
        ],
        'target_coordinate' => [
            'key' => ['target_coordinate_type', 'target_galaxy', 'target_system', 'target_orbit', 'target_object_type'],
            'Unique' => false,
        ],
        'target_fleet' => [
            'key' => ['target_coordinate_type', 'target_fleet_id'],
            'Unique' => false,
        ],
        'owner_id_home_planet_id_status' => [
            'key' => ['owner_id', 'home_planet_id', 'status'],
            'Unique' => false,
        ],
    ];

    protected static Table $syncTable;

    public static function init(?RepositorySaver $saver = null): void
    {
        $ressIDs = array_merge(Vars::$reslist['resstype'][1], Vars::$reslist['resstype'][3]);
        foreach ($ressIDs as $ResID) {
            self::$tableSchema['columns']["resource_" . Vars::$resource[$ResID]] = [
                'swoole' => [Table::TYPE_FLOAT],
                'sql' => 'DOUBLE DEFAULT 0.0',
                'default' => 0.0,
            ];
        }

        parent::init($saver);
    }

    public static function getHomeFleets(int $planetID): array
    {
        $result = [];
        foreach (self::$table as $fleet) {
            if (
                $fleet['home_planet_id'] === $planetID &&
                $fleet['status'] === 0
            ) {
                $result[$fleet['id']] = $fleet;
            }
        }
        return $result;
    }

    public static function getById(int $fleetId): ?array
    {
        if ($fleetId <= 0) {
            return null;
        }

        $fleet = static::$table->get((string) $fleetId);
        return $fleet ?: null;
    }
}
