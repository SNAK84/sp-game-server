<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Logger;
use SPGame\Core\Defaults;
use SPGame\Game\Services\RepositorySaver;
use Swoole\Table;

class Fleets extends BaseRepository
{
    protected static Table $table;

    protected static string $className = 'Fleets';
    protected static string $tableName = 'fleets';

    protected static array $tableSchema = [
        'columns' => [
            'id'           => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => Defaults::AUTOID],
            'owner_id'     => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) NOT NULL', 'default' => 0],

            // === Базовые связи и принадлежность ===
            'home_planet_id'      => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],
            'current_parent_id'   => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL', 'default' => 0],
            'current_parent_type' => ['swoole' => [Table::TYPE_STRING, 16], 'sql' => 'VARCHAR(16) NOT NULL DEFAULT ""', 'default' => ''], // planet, moon, debris, point
            'anchor_distance'     => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) NOT NULL DEFAULT 0.0', 'default' => 0.0],

            // === Координаты старта ===
            'start_galaxy' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) NOT NULL', 'default' => 1],
            'start_system' => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(3) NOT NULL', 'default' => 1],
            'start_orbit'  => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) NOT NULL', 'default' => 0],
            'start_object_type' => ['swoole' => [Table::TYPE_STRING, 16], 'sql' => 'VARCHAR(16) NOT NULL DEFAULT ""', 'default' => ''], // planet, moon, debris, base
            'start_distance' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'start_deg'      => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'start_type'     => ['swoole' => [Table::TYPE_STRING, 8], 'sql' => 'VARCHAR(8) NOT NULL DEFAULT "object"', 'default' => 'object'], // object | point

            // === Координаты цели ===
            'end_galaxy'   => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) NOT NULL', 'default' => 1],
            'end_system'   => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(3) NOT NULL', 'default' => 1],
            'end_orbit'    => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) NOT NULL', 'default' => 0],
            'end_object_type' => ['swoole' => [Table::TYPE_STRING, 16], 'sql' => 'VARCHAR(16) NOT NULL DEFAULT ""', 'default' => ''],
            'end_distance' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'end_deg'      => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'end_type'     => ['swoole' => [Table::TYPE_STRING, 8], 'sql' => 'VARCHAR(8) NOT NULL DEFAULT "object"', 'default' => 'object'],

            // === Текущие координаты ===
            'current_galaxy' => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) NOT NULL', 'default' => 1],
            'current_system' => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(3) NOT NULL', 'default' => 1],
            'current_distance' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'current_deg'      => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],

            // === Тайминги ===
            'start_time'  => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => Defaults::MICROTIME],
            'end_time'    => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'stay_time'   => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],

            // === Состояние и поведение ===
            'mission'     => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2)', 'default' => 0],
            'status'      => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1)', 'default' => 0],  // 0=idle,1=flying,2=battle,3=holding,4=destroyed
            'is_return'   => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1)', 'default' => 0],
            'speed'       => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],

            // === Ресурсы и топливо ===
            'fuel_total'       => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'fuel_current'     => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'fuel_consumption' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],

            // === Статистика боя / эффективности ===
            'experience'       => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 0.0],
            'morale'           => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 1.0],
            'damage_factor'    => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 1.0],
            'efficiency'       => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => 1.0],

            'create_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => Defaults::MICROTIME],
            'update_time' => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6)', 'default' => Defaults::MICROTIME],
        ],

        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_owner', 'type' => 'INDEX', 'fields' => ['owner_id']],
            ['name' => 'idx_start', 'type' => 'INDEX', 'fields' => ['start_galaxy', 'start_system']],
            ['name' => 'idx_end', 'type' => 'INDEX', 'fields' => ['end_galaxy', 'end_system']],
            ['name' => 'idx_status', 'type' => 'INDEX', 'fields' => ['status']],
        ],
    ];

    protected static array $indexes = [
        'owner_id' => ['key' => ['owner_id'], 'Unique' => false],
        'status' => ['key' => ['status'], 'Unique' => false],
        'start_coords' => ['key' => ['start_galaxy', 'start_system'], 'Unique' => false],
        'end_coords' => ['key' => ['end_galaxy', 'end_system'], 'Unique' => false],
    ];

    protected static Table $syncTable;

    public static function init(?RepositorySaver $saver = null): void
    {
        // Добавляем ресурсы (как раньше)
        $ressIDs = array_merge(Vars::$reslist['resstype'][1], Vars::$reslist['resstype'][3]);
        foreach ($ressIDs as $ResID) {
            self::$tableSchema['columns']["resource_" . Vars::$resource[$ResID]] = [
                'swoole' => [Table::TYPE_FLOAT],
                'sql'    => 'DOUBLE DEFAULT 0.0',
                'default' => 0.0,
            ];
        }

        parent::init($saver);
    }

    public static function getPlanetFleets(int $planetID): ?array
    {
        // Флот на орбите планеты — status = 0 и совпадают start координаты
        return self::findByIndex('start_coords', [$planetID, 0]);
    }

    public static function getHomeFleets(int $planetID): array
    {
        $result = [];
        foreach (self::$table as $fleet) {
            if (
                $fleet['home_planet_id'] === $planetID &&
                $fleet['status'] === 0 && // idle
                $fleet['current_parent_id'] === $planetID &&
                $fleet['current_parent_type'] === 'planet'
            ) {
                $result[$fleet['id']] = $fleet;
            }
        }
        return $result;
    }
}
