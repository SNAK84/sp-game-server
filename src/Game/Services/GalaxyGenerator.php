<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\OrbitalResourceSites;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Config;

use SPGame\Core\Logger;

class GalaxyGenerator
{
    /** 1500 игровых единиц соответствуют одной условной астрономической единице. */
    public const DISTANCE_PER_AU = 1500.0;
    /** Сжатие реального диапазона светимостей O–M для игрового масштаба. */
    private const LUMINOSITY_COMPRESSION = 0.25;
    /** Период земной орбиты при SpeedPlanets=24. */
    private const BASE_ORBIT_PERIOD_HOURS = 24.0;
    /** Один внешний шаг равен трети обитаемой дистанции звезды. */
    private const SPECIAL_ORBIT_STEP_FACTOR = 1.0 / 3.0;
    /** Служебные дистанции округляются до игровых 50 единиц. */
    private const SPECIAL_ORBIT_STEP_QUANTUM = 50;
    private const SPECIAL_ORBIT_MIN_STEP = 100;
    private const GAS_DEUTERIUM_RATE_PER_HOUR = 900.0;
    private const BELT_TOTAL_RATE_PER_HOUR = 1200.0;
    private const RESOURCE_CAPACITY_HOURS_MIN = 168;
    private const RESOURCE_CAPACITY_HOURS_MAX = 336;

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
        $star = Galaxy::$starTypes[$type];

        $starSize = random_int($star['size'][0], $star['size'][1]);
        $starPhysics = self::getStarPhysics($type, $starSize);
        [$minDistance, $maxDistance] = self::getSystemDistanceRange($starPhysics);
        $maxOrbits = $star['max_orbits'];
        $rotation = random_int(0, 1);

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
            'rotation' => $rotation,
        ];

        $Gsystem = Galaxy::castRowToSchema($Gsystem, true);
        Galaxy::add($Gsystem);

        $snowLine = $starPhysics['habitable_center'] * 2.7;
        $asteroidOrbit = self::selectAsteroidOrbit($orbits, $snowLine);
        $CountGasGiant = 0;
        foreach ($orbits as $orbit) {
            $record = ['galaxy' => $galaxy, 'system' => $system, 'orbit' => $orbit['orbit'], 'distance' => $orbit['distance']];

            if ($asteroidOrbit !== null && $orbit['orbit'] === $asteroidOrbit) {
                $record['type'] = GalaxyOrbits::ASTEROID_BELT;
            } elseif ($orbit['distance'] >= $snowLine && mt_rand(0, 100) < 40 && $CountGasGiant < 2) {
                $record['type'] = GalaxyOrbits::GAS_GIANT;
                $CountGasGiant++;
            } else {
                $record['type'] = GalaxyOrbits::EMPTY;
            }

            GalaxyOrbits::add($record);
        }

        self::ensureSpecialOrbits($Gsystem);
        self::ensureResourceSitesForSystem($Gsystem);

        $logger->info("Система G{$galaxy}:S{$system} создана (звезда {$type}, орбит " . count($orbits) . ")");

        return $Gsystem;
    }

    /**
     * Добавить обязательные неподвижные орбиты границы системы и экспедиции.
     * max_orbits остаётся числом обычных планетных орбит.
     */
    public static function ensureSpecialOrbits(array $systemData): void
    {
        $galaxy = (int)($systemData['galaxy'] ?? 0);
        $system = (int)($systemData['system'] ?? 0);
        $maxOrbits = (int)($systemData['max_orbits'] ?? 0);
        $maxDistance = (int)($systemData['max_distance'] ?? 0);

        if ($galaxy <= 0 || $system <= 0 || $maxOrbits <= 0 || $maxDistance <= 0) {
            throw new \LogicException('Невозможно создать служебные орбиты: данные системы неполные.');
        }

        $specialOrbitStep = self::getSpecialOrbitStep($systemData);
        $definitions = [
            GalaxyOrbits::SYSTEM_EDGE => [
                'orbit' => $maxOrbits + 1,
                'distance' => $maxDistance + $specialOrbitStep,
            ],
            GalaxyOrbits::EXPEDITION => [
                'orbit' => $maxOrbits + 2,
                'distance' => $maxDistance + $specialOrbitStep * 2,
            ],
        ];

        foreach ($definitions as $type => $definition) {
            $existing = GalaxyOrbits::findByIndex(
                'galaxy_system_orbit',
                [$galaxy, $system, $definition['orbit']]
            );

            if ($existing) {
                $row = $existing;
                if ((int)$row['type'] !== $type) {
                    Logger::getInstance()->error('Номер служебной орбиты уже занят', [
                        'galaxy' => $galaxy,
                        'system' => $system,
                        'orbit' => $definition['orbit'],
                        'expected_type' => $type,
                        'actual_type' => (int)$row['type'],
                    ]);
                    continue;
                }

                if ((int)$row['distance'] !== $definition['distance']) {
                    $row['distance'] = $definition['distance'];
                    GalaxyOrbits::update($row);
                }
                continue;
            }

            GalaxyOrbits::add([
                'galaxy' => $galaxy,
                'system' => $system,
                'orbit' => $definition['orbit'],
                'distance' => $definition['distance'],
                'type' => $type,
            ]);
        }
    }

    /**
     * Масштаб внешней зоны системы зависит от физики конкретной звезды.
     * Для Солнцеподобной G-звезды шаг остаётся около прежних 500 единиц.
     */
    private static function getSpecialOrbitStep(array $systemData): int
    {
        $starType = (string)($systemData['star_type'] ?? 'G');
        $starSize = isset($systemData['star_size'])
            ? (int)$systemData['star_size']
            : null;
        $starPhysics = self::getStarPhysics($starType, $starSize);
        $rawStep = $starPhysics['habitable_center'] * self::SPECIAL_ORBIT_STEP_FACTOR;
        $roundedStep = (int)round($rawStep / self::SPECIAL_ORBIT_STEP_QUANTUM)
            * self::SPECIAL_ORBIT_STEP_QUANTUM;

        return max(self::SPECIAL_ORBIT_MIN_STEP, $roundedStep);
    }

    /** Дозаполнить служебные орбиты всех уже созданных систем при старте. */
    public static function ensureAllSpecialOrbits(): void
    {
        foreach (Galaxy::getAll() ?? [] as $systemData) {
            self::ensureSpecialOrbits($systemData);
        }
    }

    /**
     * Создать недостающие постоянные ресурсные объекты одной системы.
     * Повторный вызов безопасен: identity задаётся через (galaxy, system,
     * orbit, sector), а параметры генерируются детерминированно.
     */
    public static function ensureResourceSitesForSystem(array $systemData, ?float $atTime = null): int
    {
        $galaxy = (int)($systemData['galaxy'] ?? 0);
        $system = (int)($systemData['system'] ?? 0);
        if ($galaxy <= 0 || $system <= 0) {
            throw new \LogicException('Невозможно создать ресурсные объекты: данные системы неполные.');
        }

        $atTime ??= microtime(true);
        $orbits = GalaxyOrbits::findByIndex('galaxy_system', [$galaxy, $system]) ?? [];
        $added = 0;

        foreach ($orbits as $orbit) {
            $orbitType = (int)($orbit['type'] ?? GalaxyOrbits::EMPTY);
            if (!in_array($orbitType, [GalaxyOrbits::GAS_GIANT, GalaxyOrbits::ASTEROID_BELT], true)) {
                continue;
            }

            // При восстановлении частично заполненного пояса новые сектора
            // должны сохранить общую временную точку орбитального движения.
            $existingOrbitSites = OrbitalResourceSites::findByIndex(
                'galaxy_system_orbit',
                [$galaxy, $system, (int)$orbit['orbit']]
            ) ?? [];
            $positionTime = $existingOrbitSites === []
                ? $atTime
                : (float)$existingOrbitSites[0]['position_time'];

            foreach (self::buildResourceSiteRows($systemData, $orbit, $positionTime) as $site) {
                $existing = OrbitalResourceSites::findByIndex(
                    'galaxy_system_orbit_sector',
                    [$galaxy, $system, (int)$site['orbit'], (int)$site['sector']]
                );
                if ($existing !== null) {
                    if ((int)$existing['site_type'] !== (int)$site['site_type']) {
                        Logger::getInstance()->warning('Тип существующего орбитального ресурса не совпадает с типом орбиты', [
                            'galaxy' => $galaxy,
                            'system' => $system,
                            'orbit' => (int)$site['orbit'],
                            'sector' => (int)$site['sector'],
                            'expected_type' => (int)$site['site_type'],
                            'actual_type' => (int)$existing['site_type'],
                        ]);
                    }
                    continue;
                }

                if (OrbitalResourceSites::add($site) !== null) {
                    $added++;
                }
            }
        }

        return $added;
    }

    /**
     * Построить устойчивые строки одного газового гиганта или секторов пояса.
     * Метод не изменяет runtime state и поэтому пригоден для проверки генерации.
     *
     * @return array<int,array<string,int|float>>
     */
    public static function buildResourceSiteRows(array $systemData, array $orbit, float $atTime): array
    {
        $galaxy = (int)($systemData['galaxy'] ?? 0);
        $system = (int)($systemData['system'] ?? 0);
        $orbitNumber = (int)($orbit['orbit'] ?? 0);
        $distance = (int)($orbit['distance'] ?? 0);
        $orbitType = (int)($orbit['type'] ?? GalaxyOrbits::EMPTY);

        if ($galaxy <= 0 || $system <= 0 || $orbitNumber <= 0 || $distance <= 0 || $atTime <= 0) {
            throw new \InvalidArgumentException('Некорректные данные для генерации орбитального ресурса.');
        }
        if (!in_array($orbitType, [GalaxyOrbits::GAS_GIANT, GalaxyOrbits::ASTEROID_BELT], true)) {
            return [];
        }

        $starType = (string)($systemData['star_type'] ?? 'G');
        $starSize = (int)($systemData['star_size'] ?? 16);
        $rotation = (int)($systemData['rotation'] ?? 1);
        $maxDistance = max($distance, (int)($systemData['max_distance'] ?? $distance));
        $distanceRatio = max(0.0, min(1.0, $distance / $maxDistance));
        $motion = self::getOrbitMotion($starType, $starSize, $distance);
        $seed = implode(':', [$galaxy, $system, $orbitNumber, $orbitType]);

        $base = [
            'galaxy' => $galaxy,
            'system' => $system,
            'orbit' => $orbitNumber,
            'speed' => $motion['speed'],
            'rotation' => $rotation,
            'position_time' => $atTime,
            'resource_update_time' => $atTime,
            'controller_fleet_id' => 0,
        ];

        if ($orbitType === GalaxyOrbits::GAS_GIANT) {
            $quality = self::deterministicFloat($seed, 'quality', 0.75, 1.35);
            $capacityHours = self::deterministicInt(
                $seed,
                'capacity_hours',
                self::RESOURCE_CAPACITY_HOURS_MIN,
                self::RESOURCE_CAPACITY_HOURS_MAX
            );
            $fillRatio = self::deterministicFloat($seed, 'initial_fill', 0.25, 0.85);
            $coldFactor = 0.85 + 0.30 * $distanceRatio;
            $deuteriumRate = round(self::GAS_DEUTERIUM_RATE_PER_HOUR * $quality * $coldFactor, 6);
            $deuteriumCapacity = round($deuteriumRate * $capacityHours, 6);

            return [[...$base, ...[
                'site_type' => OrbitalResourceSites::GAS_GIANT,
                'sector' => 0,
                'anchor_angle' => self::normalizeAngle(
                    self::deterministicFloat($seed, 'anchor_angle', 0.0, 360.0)
                ),
                'arc_width' => 0.0,
                'size' => self::deterministicInt($seed, 'size', 45000, 110000),
                'visual_variant' => self::deterministicInt($seed, 'visual_variant', 1, 6),
                'ring_variant' => self::deterministicInt($seed, 'ring_variant', 1, 3),
                'quality' => round($quality, 6),
                'survey_difficulty' => self::deterministicInt($seed, 'survey_difficulty', 45, 95),
                'capacity_hours' => $capacityHours,
                'metal_rate' => 0.0,
                'crystal_rate' => 0.0,
                'deuterium_rate' => $deuteriumRate,
                'metal_capacity' => 0.0,
                'crystal_capacity' => 0.0,
                'deuterium_capacity' => $deuteriumCapacity,
                'metal_stock' => 0.0,
                'crystal_stock' => 0.0,
                'deuterium_stock' => round($deuteriumCapacity * $fillRatio, 6),
            ]]];
        }

        $sectorCount = max(3, min(7,
            3
            + (int)round($distanceRatio * 2.0)
            + self::deterministicInt($seed, 'sector_count_adjustment', -1, 1)
        ));
        $spacing = 360.0 / $sectorCount;
        $baseAngle = self::deterministicFloat($seed, 'base_angle', 0.0, 360.0);
        $rows = [];

        for ($sector = 1; $sector <= $sectorCount; $sector++) {
            $sectorSeed = $seed . ':sector:' . $sector;
            $angleJitter = self::deterministicFloat($sectorSeed, 'angle_jitter', -0.12, 0.12) * $spacing;
            $quality = self::deterministicFloat($sectorSeed, 'quality', 0.65, 1.35);
            $capacityHours = self::deterministicInt(
                $sectorSeed,
                'capacity_hours',
                self::RESOURCE_CAPACITY_HOURS_MIN,
                self::RESOURCE_CAPACITY_HOURS_MAX
            );
            $fillRatio = self::deterministicFloat($sectorSeed, 'initial_fill', 0.20, 0.85);
            $metalShare = self::deterministicFloat($sectorSeed, 'metal_share', 0.58, 0.78);
            $totalRate = self::BELT_TOTAL_RATE_PER_HOUR * $quality * (0.90 + 0.20 * $distanceRatio);
            $metalRate = round($totalRate * $metalShare, 6);
            $crystalRate = round($totalRate - $metalRate, 6);
            $metalCapacity = round($metalRate * $capacityHours, 6);
            $crystalCapacity = round($crystalRate * $capacityHours, 6);

            $rows[] = [...$base, ...[
                'site_type' => OrbitalResourceSites::ASTEROID_SECTOR,
                'sector' => $sector,
                'anchor_angle' => self::normalizeAngle($baseAngle + ($sector - 1) * $spacing + $angleJitter),
                'arc_width' => round($spacing * self::deterministicFloat($sectorSeed, 'arc_width', 0.34, 0.55), 6),
                'size' => 0,
                'visual_variant' => 0,
                'ring_variant' => 0,
                'quality' => round($quality, 6),
                'survey_difficulty' => self::deterministicInt($sectorSeed, 'survey_difficulty', 35, 90),
                'capacity_hours' => $capacityHours,
                'metal_rate' => $metalRate,
                'crystal_rate' => $crystalRate,
                'deuterium_rate' => 0.0,
                'metal_capacity' => $metalCapacity,
                'crystal_capacity' => $crystalCapacity,
                'deuterium_capacity' => 0.0,
                'metal_stock' => round($metalCapacity * $fillRatio, 6),
                'crystal_stock' => round($crystalCapacity * $fillRatio, 6),
                'deuterium_stock' => 0.0,
            ]];
        }

        return $rows;
    }

    /**
     * Привести существующие планеты к orbital source of truth, не меняя их
     * текущую видимую позицию и не сдвигая общий resource update_time.
     */
    public static function reconcilePlanetOrbits(?float $atTime = null): int
    {
        $atTime ??= microtime(true);
        $speedMultiplier = (float)(Config::getValue('SpeedPlanets') ?? 24.0) / 24.0;
        $updated = 0;

        foreach (Planets::findAll() as $planet) {
            $systemRows = Galaxy::findByIndex('galaxy_system', [
                (int)$planet['galaxy'],
                (int)$planet['system'],
            ]);
            $systemData = $systemRows[0] ?? null;
            $orbit = GalaxyOrbits::findByIndex('galaxy_system_orbit', [
                (int)$planet['galaxy'],
                (int)$planet['system'],
                (int)$planet['planet'],
            ]);
            if (!$systemData || !$orbit) {
                Logger::getInstance()->warning('Planet orbit reconciliation skipped', [
                    'planet_id' => (int)($planet['id'] ?? 0),
                    'galaxy' => (int)($planet['galaxy'] ?? 0),
                    'system' => (int)($planet['system'] ?? 0),
                    'orbit' => (int)($planet['planet'] ?? 0),
                ]);
                continue;
            }

            $newMotion = self::getOrbitMotion(
                (string)$systemData['star_type'],
                (int)$systemData['star_size'],
                (int)$orbit['distance']
            );
            $newDirection = (int)($systemData['rotation'] ?? 1);
            $elapsedHours = max(0.0, $atTime - (float)$planet['update_time']) / 3600.0;
            $oldDirection = (int)$planet['rotation'] === 0 ? -1.0 : 1.0;
            $newDirectionSign = $newDirection === 0 ? -1.0 : 1.0;
            $currentAngle = self::normalizeAngle(
                (float)$planet['deg']
                + $oldDirection * $elapsedHours * (float)$planet['speed'] * $speedMultiplier
            );
            $newAnchorAngle = self::normalizeAngle(
                $currentAngle
                - $newDirectionSign * $elapsedHours * $newMotion['speed'] * $speedMultiplier
            );

            $needsUpdate = (int)$planet['distance'] !== (int)$orbit['distance']
                || (int)$planet['rotation'] !== $newDirection
                || abs((float)$planet['speed'] - $newMotion['speed']) > 0.000001;
            if (!$needsUpdate) {
                continue;
            }

            $planet['distance'] = (int)$orbit['distance'];
            $planet['rotation'] = $newDirection;
            $planet['speed'] = $newMotion['speed'];
            $planet['deg'] = $newAnchorAngle;
            Planets::update($planet);
            $updated++;
        }

        if ($updated > 0) {
            Logger::getInstance()->info('Planet orbit reconciliation completed', ['updated' => $updated]);
        }

        return $updated;
    }

    public static function getSpecialOrbit(int $galaxy, int $system, int $type): array
    {
        if (!in_array($type, [GalaxyOrbits::SYSTEM_EDGE, GalaxyOrbits::EXPEDITION], true)) {
            throw new \InvalidArgumentException('Запрошен неизвестный тип служебной орбиты.');
        }

        $systemData = Galaxy::getSystem($galaxy, $system);
        if (!$systemData) {
            throw new \LogicException('Звёздная система не найдена.');
        }

        $orbitNumber = (int)$systemData['max_orbits']
            + ($type === GalaxyOrbits::SYSTEM_EDGE ? 1 : 2);
        $rows = GalaxyOrbits::findByIndex(
            'galaxy_system_orbit',
            [$galaxy, $system, $orbitNumber]
        );

        if (!$rows || (int)$rows['type'] !== $type) {
            throw new \LogicException('Служебная орбита системы не найдена.');
        }

        return $rows;
    }

    /**
     * Физические параметры конкретной звезды. star_size используется как
     * положение внутри диапазона спектрального класса, поэтому расчёт можно
     * полностью восстановить после перезапуска без дополнительных колонок.
     *
     * @return array{temperature:float,mass:float,radius:float,real_luminosity:float,game_luminosity:float,habitable_center:float}
     */
    public static function getStarPhysics(string $starType, ?int $starSize = null): array
    {
        $profile = Galaxy::$starTypes[$starType] ?? Galaxy::$starTypes['G'];
        $minSize = (int)$profile['size'][0];
        $maxSize = (int)$profile['size'][1];
        $starSize ??= (int)round(($minSize + $maxSize) / 2);
        $starSize = max($minSize, min($maxSize, $starSize));
        $position = $maxSize > $minSize
            ? ($starSize - $minSize) / ($maxSize - $minSize)
            : 0.5;

        $temperature = self::interpolate($profile['temperature'], $position);
        $mass = self::interpolate($profile['mass'], $position);
        $radius = self::interpolate($profile['radius'], $position);
        $realLuminosity = pow($radius, 2) * pow($temperature / 5778.0, 4);
        $gameLuminosity = pow(max(0.000001, $realLuminosity), self::LUMINOSITY_COMPRESSION);

        return [
            'temperature' => $temperature,
            'mass' => $mass,
            'radius' => $radius,
            'real_luminosity' => $realLuminosity,
            'game_luminosity' => $gameLuminosity,
            'habitable_center' => self::DISTANCE_PER_AU * sqrt($gameLuminosity),
        ];
    }

    /** @return array{speed:float,period_hours:float} */
    public static function getOrbitMotion(string $starType, ?int $starSize, int $distance): array
    {
        $starPhysics = self::getStarPhysics($starType, $starSize);
        $distanceAu = max(0.05, $distance / self::DISTANCE_PER_AU);
        $periodHours = self::BASE_ORBIT_PERIOD_HOURS
            * sqrt(pow($distanceAu, 3) / max(0.08, $starPhysics['mass']));
        $periodHours = max(0.25, $periodHours);

        return [
            'speed' => round(360.0 / $periodHours, 6),
            'period_hours' => round($periodHours, 6),
        ];
    }

    private static function normalizeAngle(float $angle): float
    {
        $angle = fmod($angle, 360.0);
        return $angle < 0.0 ? $angle + 360.0 : $angle;
    }

    private static function deterministicFloat(
        string $seed,
        string $field,
        float $min,
        float $max
    ): float {
        if ($max < $min) {
            throw new \InvalidArgumentException('Максимум детерминированного диапазона меньше минимума.');
        }

        $value = hexdec(substr(hash('sha256', $seed . ':' . $field), 0, 8));
        $fraction = $value / 4294967295;

        return $min + ($max - $min) * $fraction;
    }

    private static function deterministicInt(
        string $seed,
        string $field,
        int $min,
        int $max
    ): int {
        if ($max < $min) {
            throw new \InvalidArgumentException('Максимум детерминированного диапазона меньше минимума.');
        }

        $range = $max - $min + 1;
        $value = $min + (int)floor(self::deterministicFloat($seed, $field, 0.0, 1.0) * $range);

        return min($max, $value);
    }

    /** @return array{0:int,1:int} */
    public static function getSystemDistanceRange(array $starPhysics): array
    {
        $habitableCenter = max(300.0, (float)$starPhysics['habitable_center']);
        $minDistance = max(200, (int)round($habitableCenter * self::randomFactor(0.32, 0.42)));
        $maxDistance = (int)round($habitableCenter * self::randomFactor(4.2, 4.8));

        return [$minDistance, max($minDistance + 1000, $maxDistance)];
    }

    private static function interpolate(array $range, float $position): float
    {
        return (float)$range[0] + ((float)$range[1] - (float)$range[0]) * $position;
    }

    private static function randomFactor(float $min, float $max): float
    {
        return $min + (($max - $min) * random_int(0, 1_000_000) / 1_000_000);
    }

    /** Выбрать некрайнюю орбиту пояса, ближайшую к снеговой линии. */
    private static function selectAsteroidOrbit(array $orbits, float $snowLine): ?int
    {
        if (count($orbits) < 5 || random_int(0, 100) >= 50) {
            return null;
        }

        $candidates = array_slice($orbits, 1, -1);
        usort(
            $candidates,
            static fn(array $a, array $b): int => abs($a['distance'] - $snowLine) <=> abs($b['distance'] - $snowLine)
        );

        return isset($candidates[0]) ? (int)$candidates[0]['orbit'] : null;
    }

    /**
     * Геометрическое распределение орбит с небольшим случайным разбросом.
     * В отличие от rejection sampling всегда создаёт ровно $count орбит.
     */
    private static function generateOrbits(int $min, int $max, int $count): array
    {
        if ($count <= 0 || $min <= 0 || $max <= $min) {
            throw new \InvalidArgumentException('Некорректные параметры генерации орбит.');
        }
        if ($count === 1) {
            return [['orbit' => 1, 'distance' => $min]];
        }
        if (($max - $min) < ($count - 1)) {
            throw new \InvalidArgumentException('Диапазон слишком мал для уникальных орбит.');
        }

        $segments = $count - 1;
        $weights = [];
        for ($i = 0; $i < $segments; $i++) {
            $weights[] = random_int(850, 1150);
        }
        $weightTotal = array_sum($weights);
        $logMin = log((float)$min);
        $logSpan = log((float)$max) - $logMin;
        $weightPassed = 0;
        $previous = $min;
        $result = [['orbit' => 1, 'distance' => $min]];

        for ($i = 1; $i < $count - 1; $i++) {
            $weightPassed += $weights[$i - 1];
            $distance = (int)round(exp($logMin + $logSpan * ($weightPassed / $weightTotal)));
            $remaining = $count - 1 - $i;
            $distance = max($previous + 1, min($max - $remaining, $distance));
            $result[] = ['orbit' => $i + 1, 'distance' => $distance];
            $previous = $distance;
        }

        $result[] = ['orbit' => $count, 'distance' => $max];
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
            // Служебные орбиты границы/экспедиции не участвуют в
            // заселённости планетных орбит.
            $totalOrbits = $maxOrbits;

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

    public static function findFreePlanetPosition(int $startGalaxy, int $startSystem, bool $randomOrbit = true): ?array
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

    public static function generatePlanet(
        string $starType,
        int $distance,
        bool $homeWorld = false,
        ?int $starSize = null,
        ?int $orbitDirection = null
    ): array {
        $starPhysics = self::getStarPhysics($starType, $starSize);
        $distanceAu = max(0.05, $distance / self::DISTANCE_PER_AU);

        // Температура равновесия зависит от светимости и обратного квадратного
        // корня расстояния. Атмосфера и альбедо добавляют небольшой игровой
        // разброс, не меняя физический порядок орбит.
        $equilibriumKelvin = 278.0
            * pow($starPhysics['game_luminosity'], 0.25)
            / sqrt($distanceAu);
        $equilibriumCelsius = $equilibriumKelvin - 273.15;

        $provisionalType = match (true) {
            $equilibriumCelsius > 130 => 'res_hot',
            $equilibriumCelsius < -90 => 'res_ice',
            $equilibriumCelsius >= -25 && $equilibriumCelsius <= 50 => 'temperate',
            default => 'res',
        };
        $albedoFactor = match ($provisionalType) {
            'res_hot' => self::randomFactor(0.93, 0.99),
            'res_ice' => self::randomFactor(0.86, 0.94),
            'temperate' => self::randomFactor(0.97, 1.0),
            default => self::randomFactor(0.94, 1.0),
        };
        $greenhouse = match ($provisionalType) {
            'res_hot' => self::randomFactor(20.0, 80.0),
            'res_ice' => self::randomFactor(-5.0, 10.0),
            'temperate' => self::randomFactor(8.0, 30.0),
            default => self::randomFactor(0.0, 25.0),
        };
        $meanTemperature = ($equilibriumKelvin * $albedoFactor) - 273.15 + $greenhouse;

        $type = match (true) {
            $meanTemperature > 150 => 'res_hot',
            $meanTemperature < -80 => 'res_ice',
            $meanTemperature >= -20 && $meanTemperature <= 60 => random_int(0, 3) === 0 ? 'water' : 'dirt',
            default => 'res',
        };
        [$minSpread, $maxSpread] = match ($type) {
            'water' => [8, 25],
            'dirt' => [12, 35],
            'res_hot' => [20, 55],
            'res_ice' => [10, 35],
            default => [15, 45],
        };
        $tempMin = (int)round(max(-220.0, min(450.0, $meanTemperature - random_int($minSpread, $maxSpread))));
        $tempMax = (int)round(max(-220.0, min(450.0, $meanTemperature + random_int($minSpread, $maxSpread))));

        if ($homeWorld) {
            $type = random_int(0, 1) === 1 ? 'dirt' : 'water';
            $size = 15000 + random_int(-2000, 2000);
            $fields = 160 + random_int(-10, 10);
            $tempMin = -20;
            $tempMax = 40;
        } else {
            [$minSize, $maxSize] = match ($type) {
                'res_hot' => [4500, 14000],
                'res_ice' => [7000, 26000],
                'water' => [10000, 22000],
                'dirt' => [8000, 21000],
                default => [6000, 24000],
            };
            $size = self::triangularRandom($minSize, $maxSize);
            $fields = self::calculateFields($size, $type, $tempMin, $tempMax);
        }

        $avgTemp = ($tempMin + $tempMax) / 2.0;
        $deg = random_int(0, 359);
        $orbitMotion = self::getOrbitMotion($starType, $starSize, $distance);
        $periodHours = $orbitMotion['period_hours'];
        $speed = $orbitMotion['speed'];
        $rotation = $orbitDirection ?? random_int(0, 1);

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
            'orbit_period' => round($periodHours, 6),
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
        // Игровое сжатие площади: диаметр важен, но не даёт квадратичного
        // превосходства большим планетам. 15 000 км соответствуют 160 полям.
        $s = max(1000.0, min($size, 100000.0));
        $base = 160.0 * pow($s / 15000.0, 0.75);

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
                $typeFactor = 0.78;
                break;
            case 'res_ice':
                $typeFactor = 0.82;
                break;
            case 'gas_giant':
                $typeFactor = 1.80;
                break;
            default:
                $typeFactor = 1.00;
        }

        // --- Температурный множитель ---
        $avgTemp = ($tempMin + $tempMax) / 2.0;

        // Температура влияет мягко: основной предел задают размер и тип.
        $tempFactor = 1.0 - (abs($avgTemp - 20.0) / 1200.0);
        $tempFactor = max(0.85, min(1.0, $tempFactor));

        // --- Мягкое сглаживание для гигантов ---
        $fields = $base * $typeFactor * $tempFactor;

        return max(60, min(350, (int)round($fields)));
    }

    private static function triangularRandom(int $min, int $max): int
    {
        return (int)round((random_int($min, $max) + random_int($min, $max)) / 2);
    }

    public static function CreatePlanet(int $userId, string $NamePlanet, bool $HomeWorld = false, ?array $position = null): array
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
        $planetData = self::generatePlanet(
            $starType,
            (int)$position['distance'],
            $HomeWorld,
            (int)($systemData['star_size'] ?? 16),
            (int)($systemData['rotation'] ?? 1)
        );

        // --- 3. Добавляем общие поля ---
        $planetData['galaxy'] = (int)$position['galaxy'];
        $planetData['system'] = (int)$position['system'];
        $planetData['planet'] = (int)$position['orbit'];
        $planetData['distance'] = (int)$position['distance'];
        $planetData['owner_id'] = $userId;
        $planetData['name'] = $NamePlanet;
        $planetData['create_time'] = time();
        $planetData['update_time'] = microtime(true);
        $planetData['rotation'] = rand(0, 1);

        // --- 4. Приведение к схеме и сохранение ---
        $planetData = Planets::castRowToSchema($planetData, true);
        Planets::add($planetData);

        // Помечаем орбиту как занятую планетой
        $orbitData = GalaxyOrbits::findByIndex('galaxy_system_orbit', [
            $planetData['galaxy'],
            $planetData['system'],
            $planetData['planet'],
        ]);

        if ($orbitData !== null) {
            $orbitData['type'] = GalaxyOrbits::PLANET;
            GalaxyOrbits::update($orbitData);
        } else {
            $logger->warning("Не найдена орбита для созданной планеты", [
                'planet_id' => $planetData['id'] ?? 0,
                'galaxy' => $planetData['galaxy'],
                'system' => $planetData['system'],
                'orbit' => $planetData['planet'],
            ]);
        }

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
            $distance = $pos['distance'] ?? (int)self::DISTANCE_PER_AU;

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
                    $distance = $pos['distance'] ?? (int)self::DISTANCE_PER_AU;
                }
            } else {
                $distance = $orbitData['distance'] ?? (int)self::DISTANCE_PER_AU;
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

        $systemData = Galaxy::getSystem((int)$planet['galaxy'], (int)$planet['system']);
        $maxOrbits = (int)($systemData['max_orbits'] ?? 0);
        $ordinaryOrbits = array_values(array_filter(
            $orbits,
            static fn(array $row): bool => (int)($row['orbit'] ?? 0) <= $maxOrbits
        ));
        $totalOrbits = count($ordinaryOrbits);

        // Приводим номер орбиты в допустимый диапазон 1..$totalOrbits
        $requestedOrbit = max(0, (int)($planet['planet'] ?? 0));
        if ($requestedOrbit < 1 || $requestedOrbit > $totalOrbits) {
            $requestedOrbit = 0;
        }

        // Если орбита найдена по номеру и соответствует записи — оставляем
        $foundIndex = null;
        if ($requestedOrbit > 0) {
            // ищем орбиту с полем orbit == $requestedOrbit
            foreach ($ordinaryOrbits as $idx => $o) {
                if ((int)$o['orbit'] === $requestedOrbit) {
                    $foundIndex = $idx;
                    break;
                }
            }
        }

        // --- Если орбита не найдена или занята — выбираем случайную пустую ---
        if ($foundIndex === null) {
            $freeOrbits = [];
            foreach ($ordinaryOrbits as $idx => $o) {
                if (empty($o['planet']) && ($o['type'] ?? 0) == 0) {
                    $freeOrbits[] = $idx;
                }
            }

            if (count($freeOrbits) > 0) {
                $foundIndex = $freeOrbits[array_rand($freeOrbits)];
            } else {
                // если нет пустых — берём случайную из всех орбит
                if ($ordinaryOrbits === []) {
                    throw new \RuntimeException('normalizeCoordinates: в системе нет обычных орбит');
                }
                $foundIndex = array_rand($ordinaryOrbits);
            }
        }

        $orbitData = $ordinaryOrbits[$foundIndex];
        $planet['planet'] = (int)$orbitData['orbit'];
        $planet['distance'] = (int)$orbitData['distance'];

        $logger->info("normalizeCoordinates: planet placed", [
            'galaxy' => $planet['galaxy'],
            'system' => $planet['system'],
            'orbit'  => $planet['planet'],
            'distance' => $planet['distance'],
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
        $newData = self::generatePlanet(
            $starType,
            (int)$distance,
            false,
            (int)($systemData['star_size'] ?? 16),
            (int)($systemData['rotation'] ?? 1)
        );

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
        $planet['distance'] = (int)$distance;

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
