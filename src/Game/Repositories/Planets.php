<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Logger;
use SPGame\Core\Defaults;

use SPGame\Game\Services\Lang;
use SPGame\Game\Services\GalaxyGenerator;

use Swoole\Table;

const ORBIT_STEP = 400;

class Planets extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'Planets';

    protected static string $tableName = 'planets';
    protected static array $tableSchema = [
        'columns' => [
            'id'           => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'default' => Defaults::AUTOID],
            'owner_id'     => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT(20) NOT NULL', 'default' => 0],
            'name'         => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => 'VARCHAR(32) NOT NULL', 'default' => 'Planet'],
            'galaxy'       => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) NOT NULL', 'default' => 1],
            'system'       => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(3) NOT NULL', 'default' => 1],
            'planet'       => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) NOT NULL', 'default' => 1],
            'planet_type'  => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) NOT NULL DEFAULT 1', 'default' => 1],
            'create_time'  => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => Defaults::TIME],
            'update_time'  => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) DEFAULT 0.0', 'default' => Defaults::TIME],
            'size'         => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => 0],
            'fields'       => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'SMALLINT UNSIGNED NOT NULL', 'default' => 0],
            'type'         => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => 'VARCHAR(32) NOT NULL DEFAULT ""', 'default' => ''],
            'image'        => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => 'VARCHAR(32) NOT NULL DEFAULT ""', 'default' => ''],
            'speed'        => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => 0],
            'distance'     => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => 0],
            'deg'          => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(9,6) NOT NULL', 'default' => 0.0],
            'rotation'     => ['swoole' => [Table::TYPE_INT, 1], 'sql' => "TINYINT(1) NOT NULL DEFAULT '0'", 'default' => [Defaults::RAND, 0, 1]],
            'temp_min'     => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(4) NOT NULL', 'default' => 0],
            'temp_max'     => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(4) NOT NULL', 'default' => 0],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'idx_owner_id', 'type' => 'INDEX', 'fields' => ['owner_id']],
        ],
    ];

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    /** Индексы Swoole */
    protected static array $indexes = [
        'owner_id' => ['key' => ['owner_id'], 'Unique' => false],
        'galaxy_system' => ['key' => ['galaxy', 'system'], 'Unique' => false]
    ];

    /** @var Table */
    protected static Table $syncTable;


    public static function findAll(): array
    {

        $Planets = [];
        foreach (self::$table as $row) {
            $Planets[$row['id']] = $row;
        }

        return $Planets;
    }

    /**
     * Получить запись по ID
     */
    public static function findById(int $id): ?array
    {
        $mainRow = static::$table->get((string)$id);
        return $mainRow !== false ? $mainRow : null;
    }

    public static function findByUser(array &$user): array
    {

        $planet = null;

        // 1. ищем по current_planet
        if (!empty($user['current_planet']) && $user['current_planet'] > 0) {
            $planet = self::findById((int)$user['current_planet']);
        }

        // 2. если не нашли, ищем по main_planet
        if (!$planet && !empty($user['main_planet']) && $user['main_planet'] > 0) {
            $planet = self::findById((int)$user['main_planet']);
            if ($planet) {
                $user['current_planet'] = $planet['id'];
                Users::update($user);
            }
        }

        // 3. если нет планеты — создаём
        if (!$planet) {
            $planet = GalaxyGenerator::CreatePlanet($user['id'], Lang::get($user['lang'], "HomeworldName"), true);
            $user['main_planet'] = $planet['id'];
            $user['current_planet'] = $planet['id'];
            // обновляем пользователя
            Users::update($user);
        }

        return $planet;
    }

    public static function getAllPlanets(int $userId): array
    {
        $Planets = [];
        foreach (self::$table as $row) {
            if ($row['owner_id'] == $userId)
                $Planets[$row['id']] = $row;
        }
        return $Planets;
    }

    public static function getPlanetsList($User): array
    {

        $sortField = (int)($User['planet_sort'] ?? 0);
        $sortOrder = (int)($User['planet_sort_order'] ?? 0);

        $Planets = [];
        foreach (self::$table as $row) {
            if ((int)$row['owner_id'] === (int)$User['id'])
                $Planets[] = [
                    'id'            => $row['id'],
                    'name'          => $row['name'],
                    'planet_type'   => $row['planet_type'],
                    'image'         => $row['image'],
                    'galaxy'        => $row['galaxy'],
                    'system'        => $row['system'],
                    'planet'        => $row['planet'],
                    'create_time'   => $row['create_time'],
                ];
        }

        usort($Planets, function ($a, $b) use ($sortField, $sortOrder) {
            $result = 0;

            switch ($sortField) {
                case 0: // create_time
                    $result = $a['create_time'] <=> $b['create_time'];
                    break;

                case 1: // name
                    $result = strcasecmp($a['name'], $b['name']);
                    break;

                case 2: // galaxy + system + planet_type
                    if ($a['galaxy'] !== $b['galaxy']) {
                        $result = $a['galaxy'] <=> $b['galaxy'];
                    } elseif ($a['system'] !== $b['system']) {
                        $result = $a['system'] <=> $b['system'];
                    } else {
                        $result = $a['planet_type'] <=> $b['planet_type'];
                    }
                    break;
            }

            // если порядок убывающий — инвертируем
            return $sortOrder === 1 ? -$result : $result;
        });

        return $Planets;
    }
}
