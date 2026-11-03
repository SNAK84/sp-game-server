<?php

declare(strict_types=1);

if (!class_exists(\Swoole\Table::class)) {
    eval('namespace Swoole; class Table { public const TYPE_INT = 1; public const TYPE_FLOAT = 2; public const TYPE_STRING = 3; }');
}

require_once __DIR__ . '/../vendor/autoload.php';

use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\OrbitalResourceSites;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Services\GalaxyGenerator;

function galaxyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$generateOrbits = new ReflectionMethod(GalaxyGenerator::class, 'generateOrbits');
$generateOrbits->setAccessible(true);

$systemsChecked = 0;
$planetsChecked = 0;

foreach (Galaxy::$starTypes as $starType => $profile) {
    $midSize = (int)round(($profile['size'][0] + $profile['size'][1]) / 2);
    $physics = GalaxyGenerator::getStarPhysics($starType, $midSize);
    galaxyAssert($physics['mass'] > 0, "{$starType}: масса звезды должна быть положительной");
    galaxyAssert($physics['game_luminosity'] > 0, "{$starType}: светимость должна быть положительной");

    $innerMotion = GalaxyGenerator::getOrbitMotion($starType, $midSize, 1000);
    $outerMotion = GalaxyGenerator::getOrbitMotion($starType, $midSize, 4000);
    galaxyAssert($innerMotion['speed'] > $outerMotion['speed'], "{$starType}: внешняя орбита движется быстрее внутренней");
    galaxyAssert($innerMotion['period_hours'] < $outerMotion['period_hours'], "{$starType}: период внешней орбиты короче внутренней");

    for ($run = 0; $run < 250; $run++) {
        [$minDistance, $maxDistance] = GalaxyGenerator::getSystemDistanceRange($physics);
        $orbits = $generateOrbits->invoke(null, $minDistance, $maxDistance, $profile['max_orbits']);

        galaxyAssert(count($orbits) === $profile['max_orbits'], "{$starType}: создано неверное количество орбит");
        galaxyAssert($orbits[0]['distance'] === $minDistance, "{$starType}: потеряна внутренняя граница");
        galaxyAssert($orbits[array_key_last($orbits)]['distance'] === $maxDistance, "{$starType}: потеряна внешняя граница");

        $direction = random_int(0, 1);
        $previousDistance = 0;
        $previousAverageTemperature = null;
        $firstSpeed = null;
        $lastSpeed = null;

        foreach ($orbits as $index => $orbit) {
            galaxyAssert($orbit['orbit'] === $index + 1, "{$starType}: нарушена нумерация орбит");
            galaxyAssert($orbit['distance'] > $previousDistance, "{$starType}: орбиты не возрастают");
            $previousDistance = $orbit['distance'];

            $planet = GalaxyGenerator::generatePlanet(
                $starType,
                $orbit['distance'],
                false,
                $midSize,
                $direction
            );
            $averageTemperature = ($planet['temp_min'] + $planet['temp_max']) / 2;

            galaxyAssert($planet['fields'] >= 60 && $planet['fields'] <= 350, "{$starType}: поля вне игрового диапазона");
            galaxyAssert($planet['temp_min'] <= $planet['temp_max'], "{$starType}: перепутаны температуры");
            galaxyAssert($planet['speed'] > 0, "{$starType}: скорость орбиты должна быть положительной");
            galaxyAssert($planet['rotation'] === $direction, "{$starType}: планета не наследует направление системы");

            if ($previousAverageTemperature !== null) {
                // Случайная атмосфера может немного сблизить соседние орбиты,
                // но не должна инвертировать климат больше чем на 80 градусов.
                galaxyAssert(
                    $averageTemperature <= $previousAverageTemperature + 80,
                    "{$starType}: климат внешней орбиты стал существенно горячее внутренней"
                );
            }
            $previousAverageTemperature = $averageTemperature;
            $firstSpeed ??= $planet['speed'];
            $lastSpeed = $planet['speed'];
            $planetsChecked++;
        }

        galaxyAssert($firstSpeed > $lastSpeed, "{$starType}: внешняя планета движется быстрее внутренней");
        $systemsChecked++;
    }
}

$homeWorld = GalaxyGenerator::generatePlanet('G', 1500, true, 16, 1);
galaxyAssert($homeWorld['fields'] >= 150 && $homeWorld['fields'] <= 170, 'Домашние поля вышли из стартового диапазона');
galaxyAssert($homeWorld['temp_min'] === -20 && $homeWorld['temp_max'] === 40, 'Изменена стартовая температура');
galaxyAssert(in_array($homeWorld['type'], ['dirt', 'water'], true), 'Домашняя планета получила неверный тип');

$resourceSystem = [
    'galaxy' => 3,
    'system' => 27,
    'star_type' => 'G',
    'star_size' => 16,
    'max_distance' => 7000,
    'rotation' => 0,
];
$resourceTime = 1770000000.125;

$gasRows = GalaxyGenerator::buildResourceSiteRows($resourceSystem, [
    'orbit' => 6,
    'distance' => 5600,
    'type' => GalaxyOrbits::GAS_GIANT,
], $resourceTime);
galaxyAssert(count($gasRows) === 1, 'Газовый гигант должен создавать один постоянный объект');
$gas = $gasRows[0];
galaxyAssert($gas['site_type'] === OrbitalResourceSites::GAS_GIANT, 'Неверный тип газового гиганта');
galaxyAssert($gas['sector'] === 0, 'Газовый гигант должен использовать sector=0');
galaxyAssert($gas['metal_rate'] === 0.0 && $gas['crystal_rate'] === 0.0, 'Газовый гигант не должен производить металл или кристалл');
galaxyAssert($gas['deuterium_rate'] > 0.0, 'Газовый гигант должен производить дейтерий');
galaxyAssert($gas['deuterium_stock'] > 0.0 && $gas['deuterium_stock'] <= $gas['deuterium_capacity'], 'Начальный запас дейтерия вне ёмкости');
galaxyAssert($gas['anchor_angle'] >= 0.0 && $gas['anchor_angle'] < 360.0, 'Угол газового гиганта не нормализован');
galaxyAssert($gas['position_time'] === $resourceTime, 'Газовый гигант потерял position_time');
galaxyAssert($gas['rotation'] === 0, 'Газовый гигант не наследует направление системы');
galaxyAssert($gas['visual_variant'] >= 1 && $gas['visual_variant'] <= 6, 'Вариант газового гиганта вне диапазона');
galaxyAssert($gas['ring_variant'] >= 1 && $gas['ring_variant'] <= 3, 'Вариант колец вне диапазона');
galaxyAssert(
    $gasRows === GalaxyGenerator::buildResourceSiteRows($resourceSystem, [
        'orbit' => 6,
        'distance' => 5600,
        'type' => GalaxyOrbits::GAS_GIANT,
    ], $resourceTime),
    'Повторная генерация газового гиганта должна быть детерминированной'
);

$beltRows = GalaxyGenerator::buildResourceSiteRows($resourceSystem, [
    'orbit' => 4,
    'distance' => 3300,
    'type' => GalaxyOrbits::ASTEROID_BELT,
], $resourceTime);
galaxyAssert(count($beltRows) >= 3 && count($beltRows) <= 7, 'Количество секторов пояса вне диапазона');
galaxyAssert(
    array_column($beltRows, 'sector') === range(1, count($beltRows)),
    'Сектора пояса должны иметь последовательные стабильные номера'
);

$beltAngles = [];
foreach ($beltRows as $sector) {
    galaxyAssert($sector['site_type'] === OrbitalResourceSites::ASTEROID_SECTOR, 'Неверный тип сектора пояса');
    galaxyAssert($sector['metal_rate'] > 0.0 && $sector['crystal_rate'] > 0.0, 'Сектор пояса должен производить металл и кристалл');
    galaxyAssert($sector['deuterium_rate'] === 0.0, 'Сектор пояса не должен производить дейтерий');
    galaxyAssert($sector['metal_stock'] > 0.0 && $sector['metal_stock'] <= $sector['metal_capacity'], 'Запас металла вне ёмкости');
    galaxyAssert($sector['crystal_stock'] > 0.0 && $sector['crystal_stock'] <= $sector['crystal_capacity'], 'Запас кристалла вне ёмкости');
    galaxyAssert($sector['anchor_angle'] >= 0.0 && $sector['anchor_angle'] < 360.0, 'Угол сектора не нормализован');
    galaxyAssert($sector['arc_width'] > 0.0 && $sector['arc_width'] < 360.0 / count($beltRows), 'Ширина сектора пересекает соседний сектор');
    galaxyAssert($sector['speed'] === $beltRows[0]['speed'], 'Сектора одного пояса должны иметь общую скорость');
    galaxyAssert($sector['rotation'] === $beltRows[0]['rotation'], 'Сектора одного пояса должны иметь общее направление');
    galaxyAssert($sector['position_time'] === $resourceTime, 'Сектор пояса потерял position_time');
    $beltAngles[] = round($sector['anchor_angle'], 6);
}
galaxyAssert(count(array_unique($beltAngles)) === count($beltRows), 'Сектора пояса получили одинаковые углы');
galaxyAssert(
    $beltRows === GalaxyGenerator::buildResourceSiteRows($resourceSystem, [
        'orbit' => 4,
        'distance' => 3300,
        'type' => GalaxyOrbits::ASTEROID_BELT,
    ], $resourceTime),
    'Повторная генерация секторов пояса должна быть детерминированной'
);

galaxyAssert(
    GalaxyGenerator::buildResourceSiteRows($resourceSystem, [
        'orbit' => 1,
        'distance' => 700,
        'type' => GalaxyOrbits::EMPTY,
    ], $resourceTime) === [],
    'Пустая орбита не должна создавать ресурсные объекты'
);

$schemaProperty = new ReflectionProperty(Planets::class, 'tableSchema');
$schemaProperty->setAccessible(true);
$planetSchema = $schemaProperty->getValue();
galaxyAssert(
    $planetSchema['columns']['speed']['swoole'][0] === \Swoole\Table::TYPE_FLOAT,
    'Planets.speed должен храниться как FLOAT в runtime'
);
galaxyAssert(
    str_starts_with(strtoupper($planetSchema['columns']['speed']['sql']), 'DOUBLE'),
    'Planets.speed должен храниться как DOUBLE в MySQL'
);

$resourceSchemaProperty = new ReflectionProperty(OrbitalResourceSites::class, 'tableSchema');
$resourceSchemaProperty->setAccessible(true);
$resourceSchema = $resourceSchemaProperty->getValue();
galaxyAssert(
    $resourceSchema['columns']['anchor_angle']['swoole'][0] === \Swoole\Table::TYPE_FLOAT,
    'OrbitalResourceSites.anchor_angle должен храниться как FLOAT в runtime'
);
galaxyAssert(
    $resourceSchema['columns']['resource_update_time']['swoole'][0] === \Swoole\Table::TYPE_FLOAT,
    'OrbitalResourceSites.resource_update_time должен храниться как FLOAT в runtime'
);

echo "GalaxyGeneratorTest passed: systems={$systemsChecked}, planets={$planetsChecked}\n";
