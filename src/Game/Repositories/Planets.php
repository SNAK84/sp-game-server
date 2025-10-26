<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Logger;
use SPGame\Core\Defaults;

use SPGame\Game\Services\Lang;

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
            'id'           => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT', 'default' => Defaults::AUTOID],
            'owner_id'     => ['swoole' => [Table::TYPE_INT, 4], 'sql' => 'INT(11) NOT NULL', 'default' => 0],
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
            $planet = self::CreatePlanet($user['id'], Lang::get($user['lang'], "HomeworldName"), true);
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

    /**
     * Получить все планеты для системы (реальные + виртуальные)
     *
     * @param int $galaxy
     * @param int $system
     * @return array
     */
    public static function getSystemPlanetsVisual(int $galaxy, int $system): array
    {
        // --- 1. Получаем реальные планеты игроков ---
        $realPlanetsRaw = self::findByIndex('galaxy_system', [$galaxy, $system]);

        $realPlanets = [];
        $usedDistances = [];

        foreach ($realPlanetsRaw as $planet) {
            $realPlanets[] = [
                'id'        => $planet['id'],
                'name'      => $planet['name'],
                'size'      => $planet['size'],
                'distance'  => $planet['distance'],
                'color'     => $planet['color'] ?? 'white',
                'image'     => $planet['image'],
                'deg'       => $planet['deg'],
                'speed'     => $planet['speed'] ?: round(100000 / max(100, $planet['distance']), 2),
                'temp_min'  => $planet['temp_min'] ?? 0,
                'temp_max'  => $planet['temp_max'] ?? 0,
                'is_real'   => true,
            ];
            $usedDistances[] = (int)$planet['distance'];
        }

        // --- 2. Диапазон и интервалы ---
        $distanceRange = self::getDistanceRange(false);
        $minDistance = $distanceRange['min'];
        $maxDistance = $distanceRange['max'];
        $minGap = 350; // минимальный интервал между орбитами

        // --- 3. Определяем общее число орбит (до 15, но в рамках диапазона) ---
        $maxPlanets = min(15, floor(($maxDistance - $minDistance) / $minGap));

        // --- 4. Расставляем орбиты (реальные + виртуальные) ---
        $planets = $realPlanets;
        $orbit = $minDistance;

        for ($i = 0; $i < $maxPlanets; $i++) {
            // если уже занято реальной планетой — пропускаем
            foreach ($usedDistances as $dist) {
                if (abs($orbit - $dist) < $minGap * 0.6) {
                    $orbit += $minGap;
                    continue 2;
                }
            }

            // создаём виртуальную планету
            $size = random_int(5000, 150000);
            $deg = random_int(0, 359);
            $tempKelvin = max(50, 3000 / sqrt(max(1, $orbit)));
            $tempMin = round($tempKelvin - random_int(20, 80) - 273);
            $tempMax = round($tempKelvin + random_int(10, 50) - 273);

            $planets[] = [
                'id'        => null,
                'name'      => 'Неопределено',
                'size'      => $size,
                'distance'  => $orbit,
                'color'     => 'gray',
                //'image'     => 'unknown_' . random_int(1, 6),
                'deg'       => $deg,
                'speed'     => round(100000 / max(100, $orbit), 2),
                'temp_min'  => $tempMin,
                'temp_max'  => $tempMax,
                'is_real'   => false,
            ];

            $orbit += $minGap + random_int(40, 100); // немного случайности между орбитами
        }

        // --- 5. Сортировка по дистанции ---
        usort($planets, fn($a, $b) => $a['distance'] <=> $b['distance']);

        return $planets;
    }



    public static function CreatePlanet(int $userId, string $NamePlanet, bool $HomeWorld = false): array
    {
        $Position = self::GetRandPos($HomeWorld);
        $PlanetRandom = array_merge($Position, self::PlanetRandomiser($Position, $HomeWorld));
        $PlanetRandom['owner_id'] = $userId;
        $PlanetRandom['create_time'] = time();
        $PlanetRandom['update_time'] = microtime(true);
        $PlanetRandom['name'] = $NamePlanet;
        $PlanetRandom['rotation'] = rand(0, 1);

        $PlanetRandom = static::castRowToSchema($PlanetRandom, true);

        Logger::getInstance()->info("PlanetRandom", $PlanetRandom);

        self::add($PlanetRandom);
        self::$logger->info("Created new planet id={$PlanetRandom['id']} owner_id={$PlanetRandom['owner_id']}");

        return $PlanetRandom;
    }

    private static function getRandPos(bool $HomeWorld = false, ?string $preferredType = null): array
    {
        $maxGalaxy = (int)Config::getValue('MaxGalaxy');
        $maxSystem = (int)Config::getValue('MaxSystem');

        $startGalaxy = (int)Config::getValue('LastGalaxyPos');
        $startSystem = (int)Config::getValue('LastSystemPos');

        $galaxy = $startGalaxy;
        $system = $startSystem;

        $fullCycle = false;


        while (true) {
            // 1) Получаем/создаём систему
            $System = Galaxy::getSystem($galaxy, $system);

            // 2) Определяем число орбит по типу звезды
            $orbitsByType = [
                'O' => 10,
                'B' => 9,
                'A' => 8,
                'F' => 7,
                'G' => 6,
                'K' => 5,
                'M' => 4,
            ];

            $maxOrbits = $orbitsByType[$System['star_type']] ?? 6;

            // 3) Собираем занятые орбиты и считаем реальные планеты в системе
            $usedOrbits = [];
            $planetCount = 0;
            foreach (self::$table as $planetRow) {
                if ((int)$planetRow['galaxy'] === $galaxy && (int)$planetRow['system'] === $system) {
                    // учёт только планет (planet_type == 1) для лимита 4
                    if ((int)$planetRow['planet_type'] === 1) {
                        $planetCount++;
                    }
                    // если в таблице ещё нет поля 'planet' (миграция), игнорируем
                    if (!empty($planetRow['planet'])) {
                        $usedOrbits[] = (int)$planetRow['planet'];
                    }
                }
            }

            // 4) Если homeworld — переводим desired distance-range в диапазон орбит
            $distanceRange = self::getDistanceRange($HomeWorld, $preferredType);
            $minOrbitByRange = max(1, (int)ceil($distanceRange['min'] / ORBIT_STEP));
            $maxOrbitByRange = max(1, (int)floor($distanceRange['max'] / ORBIT_STEP));

            // Но нельзя выходить за пределы возможных орбит системы
            $minOrbit = 1;
            $maxOrbit = min($maxOrbits, $maxOrbitByRange); // при обычной генерации учитываем верхнюю границу зоны
            if ($HomeWorld) {
                // Для домашней планеты строго ограничиваем нижней и верхней границей зоны обитаемости
                $minOrbit = max($minOrbit, $minOrbitByRange);
                $maxOrbit = min($maxOrbits, $maxOrbitByRange);
            } else {
                // для не-homeworld можно дать чуть больший диапазон (1..maxOrbits)
                $minOrbit = 1;
                $maxOrbit = $maxOrbits;
            }

            // Если диапазон орбит стал неверным — пропускаем систему
            if ($minOrbit <= $maxOrbit) {
                // 5) Формируем возможные свободные орбиты в диапазоне и выбираем одну
                $candidateOrbits = [];
                for ($i = $minOrbit; $i <= $maxOrbit; $i++) {
                    if (!in_array($i, $usedOrbits)) {
                        $candidateOrbits[] = $i;
                    }
                }

                // Если есть свободные орбиты и количество планет < 4 -> выбираем орбиту
                if (!empty($candidateOrbits) && $planetCount < 4) {
                    // можно выбирать первую свободную (плотная генерация) или случайную — тут возьмём случайную для равномерности
                    $orbit = $candidateOrbits[array_rand($candidateOrbits)];

                    // Сохраняем last pos и возвращаем позицию (с полем planet — номер орбиты)
                    Config::setValue('LastGalaxyPos', $galaxy);
                    Config::setValue('LastSystemPos', $system);

                    return [
                        'galaxy' => $galaxy,
                        'system' => $system,
                        'planet' => $orbit,
                        'planet_type' => 1,
                    ];
                }
            }

            // 6) Переходим к следующей системе
            $system++;
            if ($system > $maxSystem) {
                $system = 1;
                $galaxy++;
                if ($galaxy > $maxGalaxy) $galaxy = 1;
            }

            // 7) Проверка на полный круг
            if ($galaxy === $startGalaxy && $system === $startSystem) {
                if ($fullCycle) {
                    throw new \RuntimeException('getRandPos(): полный круг завершён, свободных систем не найдено');
                }
                $fullCycle = true;
            }
        }
        //return $position;
    }

    private static function getDistanceRange(bool $HomeWorld = false, ?string $preferredType = null): array
    {
        // Значения по умолчанию
        $range = ['min' => 50, 'max' => 5000];

        if ($HomeWorld || in_array($preferredType, ['water', 'dirt'])) {
            $range = ['min' => 1200, 'max' => 2800]; // зона обитаемости
        } elseif ($preferredType === 'res_hot') {
            $range = ['min' => 100, 'max' => 800];
        } elseif ($preferredType === 'res') {
            $range = ['min' => 800, 'max' => 2000];
        } elseif ($preferredType === 'res_ice') {
            $range = ['min' => 3000, 'max' => 5000];
        }

        return $range;
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
        // --- ФИЗИКА И ОСНОВНЫЕ ПАРАМЕТРЫ ---
        $Planet = [];

        // Берём орбиту (номер) — дробить не нужно
        $orbit = max(1, (int)($Position['planet'] ?? 1));
        $distance = $orbit * ORBIT_STEP; // виртуальное расстояние, используется в расчётах

        // --- Размер планеты ---
        $minSize = 3000; // минимальный размер планеты
        $maxSize = 40000; // максимальный размер землеподобной планеты

        // Защита на случай некорректных границ
        if ($minSize > $maxSize) {
            $minSize = max(5000, (int)floor($distance / 4));
            $maxSize = max($minSize, 40000);
        }

        $Planet['size'] = random_int($minSize, $maxSize);

        // Угол орбиты (для визуализации)
        $Planet['deg'] = random_int(0, 359);
        // Скорость вращения по орбите (чем ближе, тем быстрее)
        $Planet['speed'] = round(100000 / max(100, $Position['distance']), 2);

        // --- Температура: физически осмысленная зависимость от distance ---
        $tempKelvin = max(50, 3000 / sqrt(max(1, $distance)));
        $Planet['temp_min'] = round($tempKelvin - random_int(20, 80) - 273);
        $Planet['temp_max'] = round($tempKelvin + random_int(10, 50) - 273);

        // --- Атмосфера ---
        $Planet['atmosphere'] = (mt_rand(0, 100) < 35);
        if ($Planet['atmosphere']) {
            // Смягчение температуры
            $Planet['temp_min'] += 20;
            $Planet['temp_max'] -= 10;
        }

        // --- Тип планеты и изображение ---
        if ($HomeWorld) {
            // Для домашней планеты хотим строго 'water' или 'dirt' и безопасный диапазон температур/размеров
            $Planet['type'] = (mt_rand(0, 1) ? 'water' : 'dirt');
            $Planet['image'] = $Planet['type'] . '_' . mt_rand(1, 6);

            // Принудительное задание «комфортных» параметров для HomeWorld
            // Оставляем размер в разумном диапазоне (как раньше)
            $Planet['size'] = 15000  + mt_rand(-2000, 2000);
            $Planet['fields'] = 160 + mt_rand(-10, 10);
            $Planet['temp_min'] = -20;
            $Planet['temp_max'] = 40;
            $Planet['albedo'] = 0.45;
            $Planet['gravity'] = 1.0;
            $Planet['atmosphere'] = true;

            // rotation/deg уже заданы сверху
        } else {
            // Для обычных планет — выбираем тип по среднему температурному значению
            $avgTemp = ($Planet['temp_min'] + $Planet['temp_max']) / 2;
            if ($avgTemp >= 150) {
                $Planet['type'] = 'res_hot';
            } elseif ($avgTemp < -80) {
                $Planet['type'] = 'res_ice';
            } elseif ($avgTemp > -80 && $avgTemp < 150) {
                if (mt_rand(0, 100) < 15) {
                    $Planet['type'] = (mt_rand(0, 1) ? 'water' : 'dirt');
                } else {
                    $Planet['type'] = 'res';
                }
            } else {
                $Planet['type'] = 'res';
            }
            $Planet['image'] = $Planet['type'] . '_' . mt_rand(1, 6);
        }

        // --- Прочие параметры ---
        // Поля зависят от размера (как в старом коде)
        $Planet['fields'] = $Planet['fields'] ?? round(pow($Planet['size'], 1 / 2.15));
        $Planet['albedo'] = $Planet['albedo'] ?? round(mt_rand(20, 80) / 100, 2);
        $Planet['gravity'] = $Planet['gravity'] ?? round(($Planet['size'] / 150000) + mt_rand(-10, 10) / 100, 2);

        return $Planet;
    }
    
}
