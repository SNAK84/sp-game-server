<?php

declare(strict_types=1);

// Переиспользуем изолированные runtime doubles, не подключаясь к игровой БД.
ob_start();
require __DIR__ . '/FleetTransportTest.php';
ob_end_clean();

use SPGame\Core\Message;
use SPGame\Game\PageBuilder;
use SPGame\Game\Pages\{GalaxyPage, OverviewPage};
use SPGame\Game\Repositories\{DebrisFields, Fleets, Galaxy, GalaxyOrbits, OrbitalResourceSites, Planets, ResourceSurveys, Vars};
use SPGame\Game\Services\{AccountData, CelestialNameGenerator as Names, FleetsData, GalaxyGenerator};

$fixture = installTransportFixture();
$account = $fixture['account'];
$account['Account']['lang'] = 'ru';
$account['User'] += ['name' => 'Tester', 'current_planet' => 10, 'main_planet' => 10];
$account['Builds'] = ['metal_mine' => 0, 'terraformer' => 0];
Vars::$resource += [1 => 'metal_mine', 33 => 'terraformer'];
Vars::$reslist['build'] = [1];
(new ReflectionProperty(AccountData::class, 'FleetsData'))->setValue($account, FleetsData::getInstance());
installTransportRepository(Fleets::class, transportTable(), transportSyncTable(), [
    'owner_id' => transportTable(), 'target_coordinate' => transportTable(), 'target_fleet' => transportTable(),
]);
installTransportRepository(DebrisFields::class, transportTable(), transportSyncTable(), [
    'galaxy_system_orbit' => transportTable(), 'galaxy_system' => transportTable(),
]);
installTransportRepository(ResourceSurveys::class, transportTable(), transportSyncTable(), [
    'owner_site' => transportTable(),
]);
installTransportRepository(OrbitalResourceSites::class, transportTable(), transportSyncTable(), [
    'galaxy_system' => transportTable(), 'galaxy_system_orbit' => transportTable(),
    'galaxy_system_orbit_sector' => transportTable(),
]);

$generated = array_replace($fixture['source'], ['name' => 'Velaria', 'name_generated' => 1]);
$manual = array_replace($fixture['target'], ['name' => 'My Altaris', 'name_generated' => 0]);
$account['Planets'] = [10 => $generated, 11 => $manual];
installTransportRepository(Planets::class, transportTable([$generated, $manual]), transportSyncTable(11), [
    'galaxy_system' => nonUniqueTransportIndex(['1_10' => [10, 11]]),
    'owner_id' => nonUniqueTransportIndex(['100' => [10, 11]]),
    'galaxy_system_planet' => nonUniqueTransportIndex(['1_10_7' => [10], '1_10_8' => [11]]),
    'galaxy_system_planet_type' => uniqueTransportIndex(['1_10_7_1' => 10, '1_10_8_1' => 11]),
]);

$system = Galaxy::castRowToSchema(['id' => 1, 'galaxy' => 1, 'system' => 10, 'name' => 'Altaris',
    'star_type' => 'G', 'star_size' => 16, 'min_distance' => 100, 'max_distance' => 5000,
    'max_orbits' => 8, 'rotation' => 1], true);
installTransportRepository(Galaxy::class, transportTable([$system]), transportSyncTable(1), [
    'galaxy_system' => nonUniqueTransportIndex(['1_10' => [1]]),
]);

// Порядок загрузки намеренно отличается от последовательности физических орбит.
$orbits = [];
foreach ([6 => GalaxyOrbits::ASTEROID_BELT, 3 => GalaxyOrbits::GAS_GIANT,
    2 => GalaxyOrbits::ASTEROID_BELT, 7 => GalaxyOrbits::PLANET, 8 => GalaxyOrbits::PLANET] as $number => $type) {
    $orbits[] = ['id' => count($orbits) + 1, 'galaxy' => 1, 'system' => 10,
        'orbit' => $number, 'distance' => $number * 500, 'type' => $type];
}
installTransportRepository(GalaxyOrbits::class, transportTable($orbits), transportSyncTable(count($orbits)), [
    'galaxy_system' => nonUniqueTransportIndex(['1_10' => array_column($orbits, 'id')]),
    'galaxy_system_orbit' => uniqueTransportIndex(array_combine(
        array_map(static fn(array $row): string => '1_10_' . $row['orbit'], $orbits), array_column($orbits, 'id')
    )),
]);
GalaxyGenerator::ensureSpecialOrbits($system);
GalaxyGenerator::ensureResourceSitesForSystem($system, 100);

$before = [Galaxy::getAll(), GalaxyOrbits::getAll(), Planets::getAll(), OrbitalResourceSites::getAll()];
$builder = (new ReflectionClass(PageBuilder::class))->newInstanceWithoutConstructor();
$overview = new OverviewPage(new Message());
$galaxy = new GalaxyPage(new Message());
foreach (['ru', 'en'] as $language) {
    $account['Account']['lang'] = $language;
    $snapshot = $galaxy->render($account);
    $expected = Names::localize('Altaris', $language);
    transportAssert($snapshot['System']['name'] === $expected && $snapshot['SystemInfo']['star']['name'] === $expected,
        'Система и единственная звезда используют одно локализованное имя.');
    $byOrbit = array_column($snapshot['Orbits'], null, 'orbit');
    transportAssert($byOrbit[3]['name'] === Names::gasGiantName('Altaris', 3, $language),
        'Газовый гигант использует фактический номер орбиты III.');
    transportAssert($byOrbit[2]['name'] === Names::asteroidBeltName('Altaris', 1, $language)
        && $byOrbit[6]['name'] === Names::asteroidBeltName('Altaris', 2, $language),
        'Пояса нумеруются по физическим орбитам независимо от порядка загрузки.');
    foreach ($snapshot['ResourceSites'] as $site) {
        transportAssert($site['name'] === $byOrbit[$site['orbit']]['name'], 'Секторы используют имя своего пояса.');
    }
    $expectedPlanet = Names::localize('Velaria', $language);
    transportAssert(array_column($snapshot['Planets'], 'name', 'id') === [10 => $expectedPlanet, 11 => 'My Altaris'],
        'Карта локализует только сгенерированные имена.');
    transportAssert($overview->render($account)['PlanetName'] === $expectedPlanet, 'Обзор локализует имя.');
    $list = $builder->GetPlanetList($account);
    transportAssert(array_column($list['Planets'], 'name', 'id') === [10 => $expectedPlanet, 11 => 'My Altaris'],
        'Общий список планет использует язык аккаунта и сохраняет ручное имя.');
    transportAssert($before === [Galaxy::getAll(), GalaxyOrbits::getAll(), Planets::getAll(), OrbitalResourceSites::getAll()]
        && $account['Planet']['name'] === 'Velaria', 'Чтение страницы не меняет канонические имена и физику runtime.');
}
echo "OK celestial names: RU/EN galaxy, overview, planet list, stable belts, real gas orbit, manual names and read-only runtime\n";
