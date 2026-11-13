<?php

declare(strict_types=1);

require __DIR__ . '/FleetTransportTest.php';

use SPGame\Game\Enums\{FleetCommandType, FleetMissionType};
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{Builds, Config, Defenses, FleetCommands, Fleets, FleetsShips, Galaxy, GalaxyOrbits, Messages, PlanetResources, Planets, Resources, Ships, Vars};
use SPGame\Game\Services\{AccountData, FleetColonizationService, FleetCommandsService, FleetsData};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};
use Swoole\Table;

function colonyFixture(int $colonizers = 1, int $transports = 1, int $astro = 3): array
{
    // Старые fixtures используют один ID; destructor Fleet удаляет registry entry.
    // Завершаем orphan cycles до установки новых Tables и держим объекты этого теста.
    gc_collect_cycles();
    $f = installTransportFixture();
    $GLOBALS['colonyFixtureFleets'][] = $f['fleet'];
    class_exists(Resources::class); // PlanetResources объявлен в Resources.php.
    Vars::$resource += [124 => 'expedition_tech', 208 => 'colonizer'];
    Vars::$reslist['fleet'] = [202, 208];
    Vars::$attributes[208] = ['capacity' => 7500, 'speed' => 2500, 'consumption' => 1000,
        'consumption2' => 1000, 'tech' => 1, 'missions' => [FleetMissionType::COLONIZE->value]];
    appendTransportSchemaColumns(FleetsShips::class, [
        'colonizer_count' => ['swoole' => [Table::TYPE_INT], 'default' => 0],
        'colonizer_damage' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
        'colonizer_experience' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
        'colonizer_morale' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 1.0],
        'colonizer_status' => ['swoole' => [Table::TYPE_INT], 'default' => 0],
    ]);
    FleetsShips::update(FleetsShips::castRowToSchema(array_replace(FleetsShips::findById(1),
        ['small_cargo_count' => $transports, 'colonizer_count' => $colonizers]), true));
    FleetsData::getInstance()->remove(1, false);
    $f['fleet'] = FleetsData::getInstance()->get(1);
    $GLOBALS['colonyFixtureFleets'][] = $f['fleet'];
    (new ReflectionClass(AccountData::class))->getProperty('FleetsData')->setValue($f['account'], FleetsData::getInstance());
    $f['account']['Techs'] += ['expedition_tech' => $astro];
    $f['account']['Account'] += ['lang' => 'ru'];
    $f['coordinates'] = ['type' => 1, 'galaxy' => 1, 'system' => 10, 'orbit' => 9, 'objectType' => 1];
    $f['originalPlanet'] = $f['target'];
    $f['target'] = FleetTarget::fromCoordinates($f['coordinates']);
    $configRows = Config::getAll();
    installTransportRepository(Config::class, transportTable(array_values($configRows)), transportSyncTable(3),
        ['name' => uniqueTransportIndex(['FleetGameSpeed' => 1, 'FleetSpeedFactor' => 2, 'SpeedPlanets' => 3])]);
    foreach ([901 => 100, 902 => 50, 903 => 25] as $id => $count) Config::add(['name' => 'StartRes' . $id, 'value' => (string)$count]);
    $system = Galaxy::castRowToSchema(['id' => 1, 'galaxy' => 1, 'system' => 10, 'star_type' => 'G',
        'star_size' => 16, 'max_orbits' => 9, 'max_distance' => 2200, 'min_distance' => 500, 'rotation' => 1], true);
    installTransportRepository(Galaxy::class, transportTable([$system]), transportSyncTable(1),
        ['galaxy_system' => nonUniqueTransportIndex(['1_10' => [1]])]);
    $orbit = ['id' => 3, 'galaxy' => 1, 'system' => 10, 'orbit' => 9, 'distance' => 2200, 'type' => GalaxyOrbits::EMPTY];
    GalaxyOrbits::add($orbit);
    GalaxyOrbits::setLastId(3);
    $f['tables'] = [];
    foreach ([Builds::class, Ships::class, Defenses::class, PlanetResources::class] as $repo) {
        $f['tables'][$repo] = transportTable();
        installTransportRepository($repo, $f['tables'][$repo], transportSyncTable(), $repo === PlanetResources::class ? null : []);
    }
    $f['orbit'] = $orbit;
    return $f;
}

function colonyStart(array $f, array $cargo = []): float
{
    $travel = $f['fleet']->getTravelInfo($f['target'], $f['account'], 10, FleetMissionType::COLONIZE);
    transportAssert(is_array($travel), 'Preview свободной орбиты должен работать без Planet anchor.');
    $fuel = (float)ceil($travel['fuel']['max']);
    FleetCommandsService::startMission($f['fleet'], $f['account'], 15, $f['coordinates'], 10, $fuel, $cargo, 100);
    return $fuel;
}

function colonyPlanet(): ?array
{
    return Planets::findByIndex('galaxy_system_planet_type', [1, 10, 9, 1]);
}

function testColonySuccessCargoReturnAndMessages(): void
{
    $f = colonyFixture();
    $fuel = colonyStart($f, [901 => 300, 902 => 200, 903 => 100]);
    $chain = FleetCommands::getFleetCommandChain(1);
    transportAssert(array_column($chain, 'command_type') === [31, 10, 34, 10, 32, 52], 'Цепочка колонизации и безопасная выгрузка только дома при возврате.');
    $active = FleetCommands::getActiveCommand(1);
    $params = json_decode($active['params'], true);
    transportAssert($params['resolvedTarget']['type'] === 2 && $params['target']['objectType'] === 1,
        'Клиентская OBJECT цель и внутренняя физическая точка MOVE разделены.');
    $page = new FleetsPage(new \SPGame\Core\Message());
    $flights = array_values($page->buildFlyingFleetList($f['account']));
    transportAssert($flights[0]['mission_end_time'] > 100, 'Доступен прогноз завершения COLONIZE.');
    FleetCommandsService::completeCommand($active, $f['fleet'], $f['account']);
    $planet = colonyPlanet();
    transportAssert($planet !== null && $planet['owner_id'] === 100 && $planet['size'] > 0 && $planet['fields'] > 0, 'Планета создаётся существующим физическим генератором.');
    $resources = PlanetResources::findExistingById($planet['id']);
    transportAssert($planet['name_generated'] === 1
        && $planet['name'] === \SPGame\Game\Services\CelestialNameGenerator::planetName(1, 10, 9), 'Колония получает каноническое процедурное имя.');
    transportAssert($resources['metal'] === 400.0 && $resources['crystal'] === 250.0, 'Стартовые ресурсы и груз применены один раз.');
    transportAssert($f['fleet']->getShipCount(208) === 0 && $f['fleet']->getShipCount(202) === 1, 'Израсходован ровно один колонизатор.');
    transportAssert($f['fleet']->isReturn() && $f['fleet']->getUsedCapacity() === 0.0, 'Остаток кораблей возвращается без груза.');
    foreach ([Planets::class, Builds::class, Ships::class, Defenses::class, PlanetResources::class] as $repo) {
        transportAssert(in_array($planet['id'], $repo::getPendingSync()['update'], true), 'Все данные новой колонии dirty для RepositorySaver.');
    }
    transportAssert(Messages::findByIndex('to', [100])[0]['sample'] === 'FleetColonizationSuccess', 'Сообщение об основании колонии.');
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    transportAssert(FleetCommands::getFleetQueue(1) === [] && $f['fleet']->getMission() === 0, 'Возврат полностью завершён.');
    transportAssert($f['account']->resourceCount(10, 901) === 9700.0, 'Груз не вернулся после успешной колонизации.');
    transportAssert(count(Messages::findByIndex('to', [100])) === 2, 'Сообщения о колонии и возврате.');
}

function testColonySingleShipAndAllFuelDeposited(): void
{
    $f = colonyFixture(1, 0);
    colonyStart($f, [901 => 12]);
    $fuelOnArrival = $f['fleet']->getFuelCurrent();
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    $planet = colonyPlanet();
    transportAssert(Fleets::getById(1) === null && FleetsShips::findExistingById(1) === null && FleetCommands::getFleetQueue(1) === [], 'Одиночный колонизатор расходуется, пустой флот удаляется.');
    transportAssert(abs(PlanetResources::findExistingById($planet['id'])['deuterium'] - (25 + $fuelOnArrival)) < 0.000001, 'Всё оставшееся топливо доставлено на колонию, не потеряно.');
}

function testColonyOccupiedOnArrivalReturnsWithCargo(): void
{
    $f = colonyFixture();
    colonyStart($f, [901 => 321]);
    $occupied = $f['originalPlanet'];
    $occupied['id'] = 12; $occupied['planet'] = 9; $occupied['owner_id'] = 999;
    Planets::add($occupied);
    GalaxyOrbits::update(['id' => 3, 'type' => GalaxyOrbits::PLANET]);
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    transportAssert(colonyPlanet()['owner_id'] === 999 && $f['fleet']->getShipCount(208) === 1, 'Занятая орбита не захватывается, колонизатор не расходуется.');
    transportAssert($f['fleet']->getResources()[901]->getCount() === 321.0, 'Груз не выгружен чужому игроку.');
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    transportAssert($f['account']->resourceCount(10, 901) === 10000.0, 'Груз полностью вернулся домой.');
    transportAssert(Messages::findByIndex('to', [100])[0]['sample'] === 'FleetColonizationFailed', 'Понятный отчёт об отказе.');
}

function testColonyQuotaAndInvalidOrbitValidation(): void
{
    $f = colonyFixture(1, 1, 0);
    foreach ([0 => 1, 1 => 2, 2 => 2, 3 => 3, 4 => 3, 9 => 6] as $level => $limit) {
        $f['account']['Techs']['expedition_tech'] = $level;
        transportAssert(FleetColonizationService::planetLimit($f['account']) === $limit, 'Формула астрофизики.');
    }
    $f['account']['Techs']['expedition_tech'] = 1;
    transportAssert(!in_array(15, $f['fleet']->getAvailableMissions($f['target'], $f['account'])['missions'], true), 'Лимит исключает COLONIZE из доступных миссий.');
    $f['account']['Techs']['expedition_tech'] = 3;
    foreach ([GalaxyOrbits::ASTEROID_BELT, GalaxyOrbits::GAS_GIANT, GalaxyOrbits::SYSTEM_EDGE, GalaxyOrbits::EXPEDITION] as $type) {
        GalaxyOrbits::update(['id' => 3, 'type' => $type]);
        transportAssert(FleetColonizationService::availabilityReason($f['fleet'], $f['account'], Coordinate::fromArray($f['coordinates'])) !== null, 'Служебные и ресурсные орбиты недоступны для колонизации.');
    }
    GalaxyOrbits::update(['id' => 3, 'type' => GalaxyOrbits::EMPTY]);
    $f['fleet']->getShips()[208]->setCount(0);
    transportAssert(FleetColonizationService::availabilityReason($f['fleet'], $f['account'], Coordinate::fromArray($f['coordinates'])) !== null, 'Без колонизатора недоступно.');
    transportAssert(FleetCommands::getFleetQueue(1) === [] && $f['account']->resourceCount(10, 901) === 10000.0, 'Rejected prepare не меняет ресурсы/очередь.');
}

function testColonyQuotaChangedDuringFlight(): void
{
    $f = colonyFixture(); colonyStart($f);
    $f['account']['Techs']['expedition_tech'] = 1;
    FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    transportAssert(colonyPlanet() === null && $f['fleet']->isReturn() && $f['fleet']->getShipCount(208) === 1, 'Лимит повторно проверяется при прилёте.');
}

function testColonyRollbackAndExistingSchedulerRetry(): void
{
    foreach ([Planets::class, PlanetResources::class, FleetsShips::class, FleetCommands::class] as $failedRepo) {
        $f = colonyFixture(); colonyStart($f, [901 => 222]);
        $active = FleetCommands::getActiveCommand(1);
        $table = (new ReflectionClass($failedRepo))->getProperty('table')->getValue();
        $table->failedWrites = 1;
        // Для FleetCommands пропускаем marker завершения MOVE, ломаем запись COLONIZE.
        if ($failedRepo === FleetCommands::class) $table->failedWriteKey = (string)FleetCommands::getFleetCommandChain(1)[2]['id'];
        try {
            FleetCommandsService::completeCommand($active, $f['fleet'], $f['account']);
            throw new RuntimeException('Failure injection не сработала: ' . $failedRepo);
        } catch (RuntimeException $expected) {
            transportAssert(!str_starts_with($expected->getMessage(), 'Failure injection'), $expected->getMessage());
        }
        transportAssert(colonyPlanet() === null && Planets::count() === 2, 'Rollback не оставляет новую планету или ghost coordinate index.');
        transportAssert(GalaxyOrbits::findExistingById(3)['type'] === GalaxyOrbits::EMPTY, 'Орбита восстановлена.');
        transportAssert($f['fleet']->getShipCount(208) === 1 && $f['fleet']->getResources()[901]->getCount() === 222.0, 'Корабли и груз восстановлены.');
        transportAssert(FleetCommands::getCommand($active['id'])['executed'] === 0, 'Существующий due MOVE снова виден scheduler.');
        FleetCommandsService::completeCommand(FleetCommands::getCommand($active['id']), $f['fleet'], $f['account']);
        transportAssert(colonyPlanet() !== null && $f['fleet']->getShipCount(208) === 0, 'Повтор выполняет ровно одну колонизацию.');
    }
}

function testColonyBetweenSystemsAndRuntimeReconstruction(): void
{
    $f = colonyFixture(2, 1);
    Galaxy::add(Galaxy::castRowToSchema(['id' => 2, 'galaxy' => 1, 'system' => 11, 'star_type' => 'G',
        'star_size' => 16, 'max_orbits' => 9, 'max_distance' => 2200, 'min_distance' => 500, 'rotation' => 1], true));
    GalaxyOrbits::add(['id' => 4, 'galaxy' => 1, 'system' => 11, 'orbit' => 9,
        'distance' => 2200, 'type' => GalaxyOrbits::EMPTY]);
    GalaxyOrbits::setLastId(4);
    $f['coordinates']['system'] = 11;
    $f['target'] = FleetTarget::fromCoordinates($f['coordinates']);
    colonyStart($f, [901 => 120]);
    $chain = FleetCommands::getFleetCommandChain(1);
    transportAssert(array_column($chain, 'command_type') === [31, 10, 11, 10, 34, 10, 11, 10, 32, 52],
        'Межсистемная колонизация использует текущую цепочку выход/переход/вход.');
    for ($step = 0; $step < 3; $step++) {
        FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    }
    $planet = Planets::findByIndex('galaxy_system_planet_type', [1, 11, 9, 1]);
    transportAssert($planet !== null && $f['fleet']->getShipCount(208) === 1,
        'В другой системе создана точная цель, из двух колонизаторов израсходован один.');
    $f['fleet']->save();
    FleetsData::getInstance()->remove(1, false);
    $f['fleet'] = FleetsData::getInstance()->get(1);
    $GLOBALS['colonyFixtureFleets'][] = $f['fleet'];
    transportAssert($f['fleet']->getShipCount(208) === 1 && $f['fleet']->getCurrentCoordinate()->getSystem() === 11,
        'Состав и позиция восстанавливаются из сохранённых runtime rows.');
    for ($step = 0; $step < 3; $step++) {
        FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
    }
    transportAssert(FleetCommands::getFleetQueue(1) === [] && $f['fleet']->getCurrentCoordinate()->getSystem() === 10,
        'После реконструкции флот штатно возвращается в исходную систему.');
}

foreach (['testColonySuccessCargoReturnAndMessages', 'testColonySingleShipAndAllFuelDeposited',
    'testColonyOccupiedOnArrivalReturnsWithCargo', 'testColonyQuotaAndInvalidOrbitValidation',
    'testColonyQuotaChangedDuringFlight', 'testColonyRollbackAndExistingSchedulerRetry',
    'testColonyBetweenSystemsAndRuntimeReconstruction'] as $test) {
    $test(); echo "OK {$test}\n";
}
