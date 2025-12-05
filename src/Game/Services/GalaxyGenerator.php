<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Config;

use SPGame\Core\Logger;

class GalaxyGenerator
{
    const ORBIT_STEP = 700;

    private static array $starTypes = [
        'O' => ['color' => 'lightblue',   'size' => [25, 30], 'range' => [800, 20000], 'max_orbits' => 15, 'temperature' => 30000],
        'B' => ['color' => 'deepskyblue', 'size' => [22, 26], 'range' => [700, 16000], 'max_orbits' => 13, 'temperature' => 20000],
        'A' => ['color' => 'white',       'size' => [18, 23], 'range' => [600, 12000],  'max_orbits' => 11, 'temperature' => 10000],
        'F' => ['color' => 'beige',       'size' => [16, 20], 'range' => [600, 9000],  'max_orbits' => 9,  'temperature' => 7500],
        'G' => ['color' => 'yellow',      'size' => [14, 18], 'range' => [600, 7000],  'max_orbits' => 7,  'temperature' => 6000],
        'K' => ['color' => 'orange',      'size' => [12, 16], 'range' => [600, 6000],  'max_orbits' => 6,  'temperature' => 5000],
        'M' => ['color' => 'red',         'size' => [10, 14], 'range' => [500, 4000],  'max_orbits' => 5,  'temperature' => 3500],
    ];

    /**
     * Генерация всей системы: звезда + орбиты + гиганты/пояса
     */
    public static function generateSystem(int $galaxy, int $system): array
    {
        $logger = Logger::getInstance();

        $weights = ['O' => 1, 'B' => 2, 'A' => 3, 'F' => 5, 'G' => 8, 'K' => 10, 'M' => 12];
        $pool = [];
        foreach ($weights as $type => $w) {
            $pool = array_merge($pool, array_fill(0, $w, $type));
        }
        $type = $pool[array_rand($pool)];
        $star = self::$starTypes[$type];

        $starSize = random_int($star['size'][0], $star['size'][1]);
        $minDistance = random_int($star['range'][0], $star['range'][0] + 400);
        $maxDistance = random_int($star['range'][1] - 800, $star['range'][1]);
        $maxOrbits = $star['max_orbits'];

        $orbits = self::generateOrbits($minDistance, $maxDistance, $maxOrbits);

        $Gsystem = [
            'galaxy' => $galaxy,
            'system' => $system,
            'star_type' => $type,
            'star_color' => $star['color'],
            'star_size' => $starSize,
            'min_distance' => $minDistance,
            'max_distance' => $maxDistance,
            'max_orbits' => $maxOrbits,
        ];

        $Gsystem = Galaxy::castRowToSchema($Gsystem, true);
        Galaxy::add($Gsystem);

        $CountAsteroidBelt = 0;
        $CountGasGiant = 0;
        foreach ($orbits as $orbit) {
            $record = ['galaxy' => $galaxy, 'system' => $system, 'orbit' => $orbit['orbit'], 'distance' => $orbit['distance']];

            if (mt_rand(0, 100) < 50 && $orbit['orbit'] > 2 && $orbit['orbit'] < $maxOrbits - 2 && $CountAsteroidBelt < 1) {
                $record['type'] = GalaxyOrbits::ASTEROID_BELT;
                $CountAsteroidBelt++;
            } elseif ($orbit['distance'] >= ($maxDistance * 0.6) && mt_rand(0, 100) < 40 && $CountGasGiant < 2) {
                $record['type'] = GalaxyOrbits::GAS_GIANT;
                $CountGasGiant++;
            } else {
                $record['type'] = GalaxyOrbits::EMPTY;
            }

            GalaxyOrbits::add($record);
        }

        $logger->info("Система G{$galaxy}:S{$system} создана (звезда {$type}, орбит " . count($orbits) . ")");

        return $Gsystem;
    }

    private static function generateOrbits(int $min, int $max, int $count): array
    {
        $minGap = self::ORBIT_STEP;
        $used = [$min, $max];
        $attempts = 0;
        while (count($used) < $count && $attempts < 500) {
            $attempts++;
            $dist = random_int($min, $max);
            $ok = true;
            foreach ($used as $u) {
                if (abs($u - $dist) < $minGap) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) $used[] = $dist;
        }
        sort($used);
        $result = [];
        foreach ($used as $i => $distance) {
            $result[] = ['orbit' => $i + 1, 'distance' => $distance];
        }
        return $result;
    }

    public static function findHomeworldPosition(): ?array
    {
        $maxGalaxy = (int)Config::getValue('MaxGalaxy');
        $maxSystem = (int)Config::getValue('MaxSystem');
        $startGalaxy = (int)Config::getValue('LastGalaxyPos');
        $startSystem = (int)Config::getValue('LastSystemPos');
        $galaxy = $startGalaxy;
        $system = $startSystem;
        $fullCycle = false;

        while (true) {
            $systemData = Galaxy::getSystem($galaxy, $system);
            if (!$systemData) {
                $system++;
                continue;
            }

            $starType = $systemData['star_type'] ?? 'G';
            $maxOrbits = (int)($systemData['max_orbits'] ?? 7);

            // --- получаем орбиты и занятость ---
            $orbits = GalaxyOrbits::findByIndex('galaxy_system', [$galaxy, $system]);
            if (!$orbits) {
                $system++;
                continue;
            }

            $planets = Planets::findByIndex('galaxy_system', [$galaxy, $system]);
            $usedCount = $planets ? count($planets) : 0;
            $totalOrbits = count($orbits);

            // --- правило: если занято более 20%, пропускаем систему ---
            if ($usedCount / max(1, $totalOrbits) > 0.2) {
                $system++;
                continue;
            }

            // --- вычисляем "жилая зона" по орбитам ---
            $minOrbit = (int)round($maxOrbits * 0.3);
            $maxOrbit = (int)round($maxOrbits * 0.6);
            if ($minOrbit < 1) $minOrbit = 1;

            // --- собираем свободные подходящие орбиты ---
            $usedOrbits = $planets ? array_column($planets, 'planet') : [];
            $freeOrbits = [];
            foreach ($orbits as $o) {
                if (($o['type'] ?? 0) != 0) continue; // пропускаем астероидные и газовые
                if (in_array($o['orbit'], $usedOrbits, true)) continue;

                if ($o['orbit'] >= $minOrbit && $o['orbit'] <= $maxOrbit) {
                    $freeOrbits[] = $o;
                }
            }

            if (!empty($freeOrbits)) {
                // --- выбираем случайную свободную орбиту из "жизненной зоны" ---
                $chosen = $freeOrbits[array_rand($freeOrbits)];

                return [
                    'galaxy'   => $galaxy,
                    'system'   => $system,
                    'orbit'    => $chosen['orbit'],
                    'distance' => $chosen['distance'],
                ];
            }

            $system++;
            if ($system > $maxSystem) {
                $system = 1;
                $galaxy++;
                if ($galaxy > $maxGalaxy) $galaxy = 1;
            }

            if ($galaxy === $startGalaxy && $system === $startSystem) {
                if ($fullCycle) return null;
                $fullCycle = true;
            }
        }
    }

    public static function findFreePlanetPosition($startGalaxy, $startSystem, bool $randomOrbit = true): ?array
    {
        $maxGalaxy = (int)Config::getValue('MaxGalaxy');
        $maxSystem = (int)Config::getValue('MaxSystem');

        $galaxy = $startGalaxy;
        $system = $startSystem;
        $fullCycle = false;

        while (true) {
            // Проверяем систему
            $systemData = Galaxy::getSystem($galaxy, $system);
            if (!$systemData) {
                // Если системы нет — создаём
                self::generateSystem($galaxy, $system);
                $systemData = Galaxy::getSystem($galaxy, $system);
            }

            // Получаем орбиты и занятые планеты
            $orbits = GalaxyOrbits::findByIndex('galaxy_system', [$galaxy, $system]);
            if (!$orbits) {
                $system++;
                continue;
            }

            $planets = Planets::findByIndex('galaxy_system', [$galaxy, $system]);
            $usedOrbits = $planets ? array_column($planets, 'planet') : [];

            // Собираем все свободные орбиты (без планеты, не занятые)
            $freeOrbits = [];
            foreach ($orbits as $o) {
                // Пропускаем орбиты с типом != 0 (астероидные пояса, газовые гиганты и т.п.)
                if (($o['type'] ?? 0) != 0) {
                    continue;
                }

                if (empty($o['planet']) && !in_array($o['orbit'], $usedOrbits, true)) {
                    $freeOrbits[] = $o;
                }
            }

            // Если найдены свободные орбиты
            if (count($freeOrbits) > 0) {
                $chosenOrbit = $randomOrbit
                    ? $freeOrbits[array_rand($freeOrbits)] // случайная свободная
                    : $freeOrbits[0];                     // первая свободная

                return [
                    'galaxy'   => $galaxy,
                    'system'   => $system,
                    'orbit'    => $chosenOrbit['orbit'],
                    'distance' => $chosenOrbit['distance'],
                ];
            }

            // переход к следующей системе
            $system++;
            if ($system > $maxSystem) {
                $system = 1;
                $galaxy++;
                if ($galaxy > $maxGalaxy) $galaxy = 1;
            }

            // Проверяем, не прошли ли полный цикл
            if ($galaxy === $startGalaxy && $system === $startSystem) {
                if ($fullCycle) return null;
                $fullCycle = true;
            }
        }
    }

    public static function generatePlanet(string $starType, int $distance, bool $homeWorld = false): array
    {
        // Звёздные параметры
        $star = self::$starTypes[$starType] ?? self::$starTypes['G'];
        $starTemp = $star['temperature'];
        $starMin = $star['range'][0];
        $starMax = $star['range'][1];

        // --- нормализация расстояния ---
        $orbitNorm = 0.0;
        if ($starMax > $starMin) {
            $orbitNorm = ($distance - $starMin) / max(1, ($starMax - $starMin));
            $orbitNorm = max(0.0, min(1.0, $orbitNorm));
        }

        // --- базовая температура от звезды ---
        $luminosityFactor = pow($starTemp / 5778, 4); // относительная светимость к Солнцу
        // базовая температура около 20°C на 1500 ед. у G-звезды
        $tempBaseC = 20 * pow($luminosityFactor, 0.25) * pow(1500 / max(300, $distance), 0.5);

        // --- балансная поправка по орбитальной позиции (игровая нелинейность) ---
        // даёт естественный градиент: ближние орбиты горячие, дальние холодные
        $heatCurve = 160 - 320 * pow($orbitNorm, 1.0);
        // orbitNorm=0 → +160°C (вблизи звезды), orbitNorm=1 → ≈ -160°C (дальняя зона)

        // --- комбинируем физику и игровую кривую ---
        $tempC = ($tempBaseC * 1.9) + ($heatCurve * 1.1);

        // --- защита от крайних значений ---
        if ($tempC > 400) $tempC = 400;
        if ($tempC < -200) $tempC = -200;

        // --- добавим небольшой случайный разброс ---
        $tempMin = round($tempC - random_int(0, 40));
        $tempMax = round($tempC + random_int(0, 40));
        $avgTemp = round(($tempMin + $tempMax) / 2, 1);




        if ($homeWorld) {
            $type = (mt_rand(0, 1) ? 'dirt' : 'water');
            $size = 15000 + mt_rand(-2000, 2000);
            $fields = 160 + mt_rand(-10, 10);
            $tempMin = -20;
            $tempMax = 40;
        } else {
            // --- тип планеты по температуре и зоне ---
            // --- Определяем "зону обитаемости" в зависимости от типа звезды ---
            $starFactor = $starTemp / 5778; // Сравнение с Солнцем
            $habitableStart = 1000 * pow($starFactor, 1.7);   // ближняя граница
            $habitableEnd   = 2500 * pow($starFactor, 1.7);   // дальняя граница

            // --- Классификация по температуре ---
            if ($avgTemp > 150) {
                $type = 'res_hot'; // адские жаркие
            } elseif ($avgTemp < -80) {
                $type = 'res_ice'; // ледяные
            } elseif ($avgTemp >= -20 && $avgTemp <= 60) {
                // зона "комфортных температур" — высокий шанс на обитаемость
                $type = mt_rand(0, 3) === 0 ? 'water' : 'dirt'; // 25% шанс океанического мира
                //} elseif ($distance >= $habitableStart && $distance <= $habitableEnd) {
                // запасной вариант, если температура не идеально подходит, но в зоне обитаемости
                //    $type = mt_rand(0, 1) ? 'dirt' : 'water';
            } else {
                $type = 'res'; // нейтральный скалистый
            }


            $orbitNorm = 0.0;
            if ($starMax > $starMin) {
                $orbitNorm = ($distance - $starMin) / max(1, ($starMax - $starMin));
                $orbitNorm = max(0.0, min(1.0, $orbitNorm));
            }

            // нелинейное распределение размеров: ближе — чаще мелкие/каменные, дальше — крупнее
            if ($orbitNorm < 0.25) {
                $size = random_int(3000, 15000);
            } elseif ($orbitNorm < 0.6) {
                $size = random_int(8000, 30000);
            } else {
                $size = random_int(15000, 40000);
            }

            // --- поля ---
            $fields = self::calculateFields($size, $type, $tempMin, $tempMax);
        }

        $deg = rand(0, 359);
        // Скорость — обратна sqrt(distance) (игровая приближенность)
        $speed = 300 + round(100000 / max(100, $distance), 4);
        $rotation = rand(0, 1);

        // --- Гравитация ---
        // Простая модель: g ~ sqrt(size / EarthSize)
        $earthSize = 12742.0; // диаметр Земли, км
        $gravity = pow(max(1.0, $size) / $earthSize, 0.5);
        // Ограничение реалистичное для игры
        $gravity = max(0.1, min($gravity, 3.0));
        // Округлим
        $gravity = round($gravity, 2);

        // --- Атмосфера (уровень 0..5) ---
        // Базовый фактор от температуры
        if ($avgTemp < -80) {
            $atm = mt_rand(0, 1);
        } elseif ($avgTemp < 50) {
            $atm = mt_rand(2, 3);
        } elseif ($avgTemp < 150) {
            $atm = mt_rand(3, 4);
        } else {
            $atm = mt_rand(4, 5);
        }

        // Малая гравитация ослабляет удержание атмосферы
        if ($gravity < 0.6) {
            $atm = max(0, (int)floor($atm * 0.6));
        }

        // Тип планеты влияет на атмосферу
        switch ($type) {
            case 'res_hot':
                $atm += 1; // горячие чаще плотные
                break;
            case 'res_ice':
                $atm -= 1; // холодные обычно тоньше
                break;
            case 'water':
                $atm += 1; // водные — имеют влагу/плотность
                break;
            case 'dirt':
            case 'res':
            default:
                // нейтральное влияние
                break;
        }
        $atm = max(0, min(5, (int)$atm));

        // --- Обитаемость (игровая метрика 0.0..1.0) ---
        $habitability = 0.0;
        // базовые условия: вода/плодородие, подходящая температура, нормальная атмосфера, разумная гравитация
        if (in_array($type, ['dirt', 'water'], true)) {
            // идеальная зона примерно -20 .. +60 (игровая)
            $tempScore = 1.0 - (abs($avgTemp - 20.0) / 100.0);
            $tempScore = max(0.0, min(1.0, $tempScore));
            $atmScore = ($atm >= 2) ? 1.0 : ($atm == 1 ? 0.5 : 0.0);
            $gravScore = ($gravity >= 0.5 && $gravity <= 2.0) ? 1.0 : ($gravity < 0.5 ? 0.5 : 0.8);
            $habitability = ($tempScore * 0.5) + ($atmScore * 0.3) + ($gravScore * 0.2);
        }
        $habitability = max(0.0, min(1.0, round($habitability, 2)));

        // --- Доп. проверка: если тип res_hot/res_ice/res, уменьшить шанс "высокой" атмосферы в крайних условиях ---
        if (in_array($type, ['res_hot', 'res_ice', 'res'], true) && $atm > 4) {
            $atm = 4;
        }

        //$resourceCoeffs = self::calculateResourceModifiers($type, $avgTemp);

        return [
            'type' => $type,
            'image' => $type . '_' . mt_rand(1, 6),
            'size' => $size,
            'fields' => $fields,
            'temp_min' => $tempMin,
            'temp_max' => $tempMax,
            'deg'        => (int)$deg,
            'speed'      => $speed,
            'rotation'   => (int)$rotation,
            'gravity'    => (float)$gravity,
            'atmosphere' => (int)$atm,
            'habitability' => (float)$habitability,
        ];
    }

    /**
     * Рассчитывает количество полей планеты на основе размера, типа и температуры.
     *
     * @param float $size      Размер планеты (радиус или относительный размер)
     * @param string $type     Тип планеты ('dirt', 'water', 'res', 'res_hot', 'res_ice', 'gas_giant')
     * @param float $tempMin   Минимальная температура поверхности (°C)
     * @param float $tempMax   Максимальная температура поверхности (°C)
     * @return int             Примерное количество полей
     */
    public static function calculateFields(float $size, string $type, float $tempMin, float $tempMax): int
    {
        // --- Базовая формула, подобранная под HomeWorld 13–17k ≈ 160 полей ---
        $A = -22.388296444439163;
        $k = 0.10906463719810218;
        $p = 0.7721811772435125;

        // Безопасный диапазон размера
        $s = max(100, min($size, 200000));

        // Базовое значение от размера
        $base = $A + $k * pow($s, $p);

        // --- Тип планеты ---
        switch ($type) {
            case 'dirt':
            case 'water':
                $typeFactor = 1.00;
                break;
            case 'res':
                $typeFactor = 0.90;
                break;
            case 'res_hot':
            case 'res_ice':
                $typeFactor = 0.75;
                break;
            case 'gas_giant':
                $typeFactor = 1.80;
                break;
            default:
                $typeFactor = 1.00;
        }

        // --- Температурный множитель ---
        $avgTemp = ($tempMin + $tempMax) / 2.0;

        // Комфортная зона около 20°C, чем дальше — тем меньше эффективность
        $tempFactor = 1.0 - (abs($avgTemp - 20.0) / 600.0);
        if ($tempFactor < 0.7) $tempFactor = 0.7;
        if ($tempFactor > 1.1) $tempFactor = 1.1;

        // --- Мягкое сглаживание для гигантов ---
        $fields = $base * $typeFactor * $tempFactor;

        // Мягкая нелинейная коррекция — уменьшает слишком большие числа, не обрезая
        if ($fields > 400) {
            $fields = 400 + pow($fields - 400, 0.85);
        }

        return (int)round($fields);
    }

    public static function CreatePlanet(int $userId, string $NamePlanet, bool $HomeWorld = false): array
    {
        $logger = Logger::getInstance();

        // --- 1. Ищем или создаём систему и свободную орбиту ---
        if ($HomeWorld) {
            $position = self::findHomeworldPosition();
        }

        if (!$position) {
            throw new \RuntimeException("Не удалось найти свободное место для новой планеты");
        }

        $systemData = Galaxy::getSystem($position['galaxy'], $position['system']);
        $starType = $systemData['star_type'] ?? 'G';

        // --- 2. Генерируем физику планеты ---
        $planetData = self::generatePlanet($starType, $position['distance'], $HomeWorld);

        // --- 3. Добавляем общие поля ---
        $planetData['owner_id'] = $userId;
        $planetData['name'] = $NamePlanet;
        $planetData['create_time'] = time();
        $planetData['update_time'] = microtime(true);
        $planetData['rotation'] = rand(0, 1);

        // --- 4. Приведение к схеме и сохранение ---
        $planetData = Planets::castRowToSchema($planetData, true);
        Planets::add($planetData);

        $logger->info("Создана планета {$planetData['name']} (ID={$planetData['id']}) для пользователя #{$userId}");

        return $planetData;
    }


    /**
     * Нормализует координаты планеты: galaxy/system/planet.
     * Если указанной орбиты не существует — подбирает ближайшую пустую или ближайшую вообще.
     */
    public static function normalizeCoordinates(array &$planet): void
    {
        $logger = Logger::getInstance();

        $maxGalaxy = (int)Config::getValue('MaxGalaxy');
        $maxSystem = (int)Config::getValue('MaxSystem');

        // --- Проверка корректности координат ---
        $galaxy = (int)($planet['galaxy'] ?? 0);
        $system = (int)($planet['system'] ?? 0);

        $galaxy = (int)($planet['galaxy'] ?? 0);
        $system = (int)($planet['system'] ?? 0);
        $orbit  = (int)($planet['planet'] ?? 0);

        // --- Сохраняем начальные координаты с нормализацией диапазона ---
        $StartGalaxy = ($galaxy >= 1 && $galaxy <= $maxGalaxy) ? $galaxy : 1;
        $StartSystem = ($system >= 1 && $system <= $maxSystem) ? $system : 1;

        // Проверяем корректность координат
        if (
            $galaxy <= 0 || $system <= 0 || $orbit <= 0 ||
            $galaxy > $maxGalaxy || $system > $maxSystem
        ) {


            $logger->info("normalizeCoordinates: finding free planet position...", [$planet]);
            $pos = self::findFreePlanetPosition($StartGalaxy, $StartSystem);
            if (!$pos) {
                throw new \RuntimeException("normalizeCoordinates: не удалось найти свободную орбиту");
            }

            $planet['galaxy'] = $pos['galaxy'];
            $planet['system'] = $pos['system'];
            $planet['planet'] = $pos['orbit'];
            $distance = $pos['distance'] ?? self::ORBIT_STEP;

            $logger->info("normalizeCoordinates: assigned new position", $pos);
        } else {
            // получаем орбиту для текущей системы
            $orbits = GalaxyOrbits::findByIndex('galaxy_system', [$galaxy, $system]);
            $orbitData = null;
            foreach ($orbits as $o) {
                if ((int)$o['orbit'] === $orbit && $o['type'] === 0) {
                    $orbitData = $o;
                    break;
                }
            }

            // если орбита не найдена — ищем новую
            if (!$orbitData) {
                $logger->info("normalizeCoordinates: orbit not found, searching free orbit...");
                $pos = self::findFreePlanetPosition($StartGalaxy, $StartSystem);
                if ($pos) {
                    $planet['galaxy'] = $pos['galaxy'];
                    $planet['system'] = $pos['system'];
                    $planet['planet'] = $pos['orbit'];
                    $distance = $pos['distance'] ?? self::ORBIT_STEP;
                }
            } else {
                $distance = $orbitData['distance'] ?? self::ORBIT_STEP;
            }
        }



        // Получаем данные системы и список орбит
        $orbits = GalaxyOrbits::findByIndex('galaxy_system', [$planet['galaxy'], $planet['system']]);

        if (!$orbits || count($orbits) === 0) {
            // нет орбит — попробуем найти ближайшую существующую систему (можно логировать)
            Logger::getInstance()->warning("normalizeCoordinates: system has no orbits", [$planet]);
            // на всякий случай обнулим планетную позицию
            $planet['planet'] = 0;
            return;
        }

        $totalOrbits = count($orbits);

        // Приводим номер орбиты в допустимый диапазон 1..$totalOrbits
        $requestedOrbit = max(0, (int)($planet['planet'] ?? 0));
        if ($requestedOrbit < 1 || $requestedOrbit > $totalOrbits) {
            $requestedOrbit = 0;
        }

        // Если орбита найдена по номеру и соответствует записи — оставляем
        $foundIndex = null;
        if ($requestedOrbit > 0) {
            // ищем орбиту с полем orbit == $requestedOrbit
            foreach ($orbits as $idx => $o) {
                if ((int)$o['orbit'] === $requestedOrbit) {
                    $foundIndex = $idx;
                    break;
                }
            }
        }

        // --- Если орбита не найдена или занята — выбираем случайную пустую ---
        if ($foundIndex === null) {
            $freeOrbits = [];
            foreach ($orbits as $idx => $o) {
                if (empty($o['planet']) && ($o['type'] ?? 0) == 0) {
                    $freeOrbits[] = $idx;
                }
            }

            if (count($freeOrbits) > 0) {
                $foundIndex = $freeOrbits[array_rand($freeOrbits)];
            } else {
                // если нет пустых — берём случайную из всех орбит
                $foundIndex = array_rand($orbits);
            }
        }

        $orbitData = $orbits[$foundIndex];
        $planet['planet'] = (int)$orbitData['orbit'];

        $logger->info("normalizeCoordinates: planet placed", [
            'galaxy' => $planet['galaxy'],
            'system' => $planet['system'],
            'orbit'  => $planet['planet'],
            'distance' => $distance,
        ]);
    }

    /**
     * Регенерация планеты по её id.
     * @param int $planetId
     * @param bool $keepOwner сохранять owner_id и имя (если true)
     * @return array обновлённые данные планеты
     * @throws \RuntimeException
     */
    public static function regeneratePlanet(array $planet, bool $keepOwner = true): array
    {
        $logger = Logger::getInstance();

        // Сохраним часть данных, если нужно
        $owner = $planet['owner_id'] ?? null;
        $name = $planet['name'] ?? null;

        // Нормализуем координаты (найдёт корректную орбиту и установит speed/deg/rotation)
        self::normalizeCoordinates($planet);

        // Получим систему и звёздный тип
        $systemData = Galaxy::getSystem($planet['galaxy'], $planet['system']);
        $starType = $systemData['star_type'] ?? 'G';

        // Найдём орбиту в GalaxyOrbits, чтобы получить distance
        $orbits = GalaxyOrbits::findByIndex('galaxy_system', [$planet['galaxy'], $planet['system']]);
        $distance = null;
        foreach ($orbits as $o) {
            if ((int)$o['orbit'] === (int)$planet['planet']) {
                $distance = $o['distance'];
                break;
            }
        }
        if ($distance === null) {
            // если нет, берем среднюю дистанцию
            $distance = (int)round(array_sum(array_column($orbits, 'distance')) / max(1, count($orbits)));
        }

        // Генерируем физику планеты заново
        $newData = self::generatePlanet($starType, (int)$distance, false);

        // Обновляем поля планеты (не трогаем id)
        $planet['type'] = $newData['type'];
        $planet['image'] = $newData['image'];
        $planet['size'] = $newData['size'];
        $planet['fields'] = $newData['fields'];
        $planet['temp_min'] = $newData['temp_min'];
        $planet['temp_max'] = $newData['temp_max'];
        $planet['deg'] = $newData['deg'];
        $planet['speed'] = $newData['speed'];
        $planet['rotation'] = $newData['rotation'];

        // Время обновления
        //$planet['update_time'] = microtime(true);

        // Если требуется — поддерживаем owner и имя
        if ($keepOwner) {
            if ($owner !== null) $planet['owner_id'] = $owner;
            if ($name !== null) $planet['name'] = $name;
        }

        // Приведение к схеме и запись
        $planet = Planets::castRowToSchema($planet, true);
        Planets::update($planet);

        $logger->info("Regenerated planet #{$planet['id']}", [$planet, 'newData' => $newData]);

        return $planet;
    }


    private static function calculateResourceModifiers(string $type, float $avgTemp): array
    {
        $base = ['metal' => 1.0, 'crystal' => 1.0, 'deuterium' => 1.0, 'energy' => 1.0];
        switch ($type) {
            case 'dirt':
                return $base;
            case 'water':
                $base['metal'] *= 0.9;
                $base['crystal'] *= 1.0;
                $base['deuterium'] *= 1.2;
                break;
            case 'res_hot':
                $base['metal'] *= 1.2;
                $base['crystal'] *= 0.9;
                $base['deuterium'] *= 0.7;
                $base['energy'] *= 1.3;
                break;
            case 'res_ice':
                $base['metal'] *= 0.8;
                $base['crystal'] *= 1.2;
                $base['deuterium'] *= 1.4;
                $base['energy'] *= 0.7;
                break;
            case 'res':
                $base['metal'] *= 1.1;
                $base['crystal'] *= 1.0;
                break;
        }
        if ($avgTemp > 100) {
            $base['energy'] *= 1.2;
        } elseif ($avgTemp < -50) {
            $base['deuterium'] *= 1.1;
        }
        return $base;
    }
}
