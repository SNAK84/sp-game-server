<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Logger;
use SPGame\Core\Defaults;

use Swoole\Table;

class Planets extends BaseRepository
{

    /** @var Table Основная таблица */
    protected static Table $table;

    protected static string $className = 'Planets';

    protected static string $tableName = 'planets';
    protected static array $tableSchema = [
        'columns' => [
            'id'           => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT', 'default' => 0],
            'owner_id'     => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => 0],
            'name'         => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => 'VARCHAR(32) NOT NULL', 'default' => 'Planet'],
            'create_time'  => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => Defaults::TIME],
            'update_time'  => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(17,6) DEFAULT 0.0', 'default' => 0.0],
            'planet_type'  => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(1) NOT NULL DEFAULT 1', 'default' => 1],
            'size'         => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => 0],
            'fields'       => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'SMALLINT UNSIGNED NOT NULL', 'default' => 0],
            'type'         => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => 'VARCHAR(32) NOT NULL DEFAULT ""', 'default' => ''],
            'image'        => ['swoole' => [Table::TYPE_STRING, 32], 'sql' => 'VARCHAR(32) NOT NULL DEFAULT ""', 'default' => ''],
            'speed'        => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => 0],
            'distance'     => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => 0],
            'deg'          => ['swoole' => [Table::TYPE_FLOAT, 8], 'sql' => 'DOUBLE(9,6) NOT NULL', 'default' => 0.0],
            'rotation'     => ['swoole' => [Table::TYPE_INT, 1], 'sql' => "TINYINT(1) NOT NULL DEFAULT '0'", 'default' => [Defaults::RAND, 0, 1]],
            'galaxy'       => ['swoole' => [Table::TYPE_INT, 1], 'sql' => 'TINYINT(2) NOT NULL', 'default' => 1],
            'system'       => ['swoole' => [Table::TYPE_INT, 2], 'sql' => 'SMALLINT(3) NOT NULL', 'default' => 1],
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
        'owner_id' => ['key' => ['owner_id'], 'Unique' => false]
    ];

    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsTable;
    /** @var Table Список изменённых ID для синхронизации */
    protected static Table $dirtyIdsDelTable;


    /**
     * Получить запись по ID
     */
    public static function findById(int $id): ?array
    {
        $mainRow = static::$table->get((string)$id);
        return $mainRow !== false ? $mainRow : null;
    }

    public static function findByUserId(int $userId): array
    {
        $user = Users::findById($userId);
        if (!$user) {
            throw new \RuntimeException("User $userId not found");
        }

        $planet = null;

        // 1. ищем по current_planet
        if (!empty($user['current_planet'])) {
            $planet = self::findById((int)$user['current_planet']);
        }

        // 2. если не нашли, ищем по main_planet
        if (!$planet && !empty($user['main_planet'])) {
            $planet = self::findById((int)$user['main_planet']);
            if ($planet) {
                // обновляем current_planet у пользователя
                Users::update([
                    'id'             => (int) $userId,
                    'current_planet' => (int) $planet['id'],
                ]);
            }
        }

        // 3. если нет планеты — создаём
        if (!$planet) {
            $planet = self::CreatePlanet($userId, 'Homeworld');

            // обновляем пользователя
            Users::update([
                'id'             => (int) $userId,
                'main_planet'    => (int) $planet['id'],
                'current_planet' => (int) $planet['id'],
            ]);
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

    public static function getPlanetsList(array $User ): array
    {

        $sortField = (int)($User['planet_sort'] ?? 0);
        $sortOrder = (int)($User['planet_sort_order'] ?? 0);

        $Planets = [];
        foreach (self::$table as $row) {
            if ($row['owner_id'] == $User['id'])
                $Planets[] = [
                    'id'            => $row['id'],
                    'name'          => $row['name'],
                    'planet_type'   => $row['planet_type'],
                    'image'         => $row['image'],
                    'galaxy'        => $row['galaxy'],
                    'system'        => $row['system'],
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

    public static function CreatePlanet(int $userId, string $NamePlanet): array
    {
        $Position = self::GetRandPos();
        $PlanetRandom = array_merge($Position, self::PlanetRandomiser($Position, true));
        $PlanetRandom['owner_id'] = $userId;
        $PlanetRandom['create_time'] = time();
        $PlanetRandom['update_time'] = microtime(true);
        $PlanetRandom['name'] = $NamePlanet;
        $PlanetRandom['rotation'] = rand(0, 1);



        $db = Database::getInstance();

        // Берём все колонки, которые есть в MySQL
        $mysqlColumns = [];
        foreach (self::$tableSchema['columns'] as $col => $def) {
            if (!isset($def['sql'])) continue; // этой колонки нет в MySQL
            if ($col === 'id') continue;       // id автоинкремент, вставлять не надо
            if (isset($PlanetRandom[$col])) {
                $mysqlColumns[] = $col;
                $dataToInsert[$col] = $PlanetRandom[$col];
            }
        }
        // Плейсхолдеры
        $placeholders = array_map(fn($c) => ":$c", $mysqlColumns);

        // SQL
        $sql = "INSERT INTO `" . self::$tableName . "` (" . implode(',', $mysqlColumns) . ")
        VALUES (" . implode(',', $placeholders) . ")";

        $PlanetRandom['id'] = $db->insert($sql, $dataToInsert);

        Logger::getInstance()->info("PlanetRandom", $PlanetRandom);

        self::add($PlanetRandom);
        self::$logger->info("Created new planet id={$PlanetRandom['id']} owner_id={$PlanetRandom['owner_id']}");

        return $PlanetRandom;
    }

    private static function getRandPos(): array
    {
        $position = [
            'galaxy' => (int)Config::getValue('LastGalaxyPos'),
            'system' => (int)Config::getValue('LastSystemPos'),
            'planet_type' => 1
        ];

        if ($position['galaxy'] > (int)Config::getValue('MaxGalaxy')) $position['galaxy'] = 1;
        if ($position['system'] > (int)Config::getValue('MaxSystem')) $position['system'] = 1;

        // Ищем свободный слот в системе
        do {
            $count = 0;
            foreach (self::$table as $planet) {
                if ((int)$planet['galaxy'] === $position['galaxy'] && (int)$planet['system'] === $position['system'] && (int)$planet['planet_type'] === 1) {
                    $count++;
                }
            }

            if ($count > 2) {
                $position['system']++;
                if ($position['system'] > (int)Config::getValue('MaxSystem')) {
                    $position['system'] = 1;
                    $position['galaxy']++;
                    if ($position['galaxy'] > (int)Config::getValue('MaxGalaxy')) $position['galaxy'] = 1;
                }
            }
        } while ($count > 2);

        do {
            $distance = mt_rand(50, 5000);
        } while (!self::isDistanceFree($position['galaxy'], $position['system'], $distance));

        $position['distance'] = $distance;
        Config::setValue('LastGalaxyPos', $position['galaxy']);
        Config::setValue('LastSystemPos', $position['system']);

        return $position;
    }

    private static function isDistanceFree(int $galaxy, int $system, int $distance, int $range = 40): bool
    {
        foreach (self::$table as $planet) {
            if ((int)$planet['galaxy'] === $galaxy && (int)$planet['system'] === $system && (int)$planet['planet_type'] === 1) {
                $planetDistance = (int)$planet['distance'];
                if ($planetDistance > $distance - $range && $planetDistance < $distance + $range) {
                    return false; // найдено пересечение
                }
            }
        }
        return true; // свободно
    }

    private static function PlanetRandomiser($Position, $HomeWorld = false)
    {

        $Planet['size'] = random_int(2000, 380000);
        $Planet['deg'] = random_int(0, 359);
        $Planet['speed'] = random_int(260, 460);
        $Planet['temp_min'] = round((5500 - $Position['distance']) / 6.6);
        $Planet['temp_max'] = round($Planet['temp_min'] + random_int(0, max(20, ceil($Position['distance'] / 100))));
        $Planet['fields'] = round(pow($Planet['size'], 1 / 2.12));

        $Planet['temp_min'] -= 273;
        $Planet['temp_max'] -= 273;

        if ($Planet['temp_max'] < 100 & $Planet['temp_min'] > -100) {
            $Type = mt_rand(1, 4);
        } else {
            $Type = mt_rand(1, 2);
        }

        switch ($Type) {
            case 1:
                $Planet['type'] = 'metal';
                if ($Planet['temp_max'] < 0) $Planet['image'] = 'res_ice_' . mt_rand(1, 6);
                if ($Planet['temp_max'] < 100 & $Planet['temp_min'] > -100) $Planet['image'] = 'res_' . mt_rand(1, 6);
                if ($Planet['temp_max'] >= 100) $Planet['image'] = 'res_hot_' . mt_rand(1, 6);
                break;
            case 2:
                $Planet['type'] = 'crystal';
                if ($Planet['temp_max'] < 0) $Planet['image'] = 'res_ice_' . mt_rand(1, 6);
                if ($Planet['temp_max'] < 100 & $Planet['temp_min'] > -100) $Planet['image'] = 'res_' . mt_rand(1, 6);
                if ($Planet['temp_max'] >= 100) $Planet['image'] = 'res_hot_' . mt_rand(1, 6);
                break;
            case 3:
                $Planet['type'] = 'dirt';
                $Planet['image'] = 'dirt_' . mt_rand(1, 6);
                break;
            case 4:
                $Planet['type'] = 'water';
                $Planet['image'] = 'water_' . mt_rand(1, 6);
                break;
        }

        return $Planet;
    }
}
