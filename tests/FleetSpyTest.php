<?php

declare(strict_types=1);

namespace SPGame\Game\Services {
    // Детерминированные исходы только в этом отдельном тестовом PHP-процессе.
    function random_int(int $min, int $max): int
    {
        if (!empty($GLOBALS['spyRolls'])) {
            $value = array_shift($GLOBALS['spyRolls']);
            if ($value < $min || $value > $max) throw new \RuntimeException('Неверный тестовый бросок.');
            return $value;
        }
        return \random_int($min, $max);
    }
}

namespace {
    require __DIR__ . '/FleetTransportTest.php';

    use SPGame\Game\Enums\FleetMissionType;
    use SPGame\Game\Fleet\Fleet;
    use SPGame\Game\Repositories\{Accounts, Builds, Config, DebrisFields, Defenses, FleetCommands,
        Fleets, FleetsShips, Messages, Planets, Ships, Techs, Users, Vars};
    use SPGame\Game\Services\{AccountData, FleetCommandsService, FleetsData, FleetSpyService};
    use SPGame\Game\Space\{CoordinateType, ObjectType};
    use Swoole\Table;

    function spyFixture(int $probes = 1): array
    {
        $f = installTransportFixture();
        $f['fleet'] = null;
        Vars::$resource += [210 => 'spy_sonde', 106 => 'spy_tech', 113 => 'energy_tech',
            131 => 'metal_tech', 132 => 'crystal_tech', 133 => 'deuterium_tech', 1 => 'metal_mine', 401 => 'missile_launcher'];
        Vars::$reslist += ['bonus' => [], 'storage' => [], 'prod' => [],
            'build' => [1], 'tech' => [106], 'defense' => [401], 'missile' => []];
        Vars::$reslist['fleet'][] = 210;
        Vars::$attributes[210] = ['capacity' => 50000, 'speed' => 100000000, 'consumption' => 1,
            'consumption2' => 1, 'tech' => 1, 'missions' => [FleetMissionType::SPY->value]];
        Vars::$pricelist = [210 => [901 => 0, 902 => 1000], 202 => [901 => 2000, 902 => 2000]];
        appendTransportSchemaColumns(FleetsShips::class, [
            'spy_sonde_count' => ['swoole' => [Table::TYPE_INT], 'default' => 0],
            'spy_sonde_damage' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 0.0],
            'spy_sonde_morale' => ['swoole' => [Table::TYPE_FLOAT], 'default' => 1.0],
            'spy_sonde_status' => ['swoole' => [Table::TYPE_INT], 'default' => 0],
        ]);
        FleetsShips::update(['id' => 1, 'small_cargo_count' => 0, 'spy_sonde_count' => $probes,
            'spy_sonde_damage' => 0.0, 'spy_sonde_morale' => 1.0, 'spy_sonde_status' => 0]);
        installTransportRepository(FleetsShips::class, transportTable([FleetsShips::findById(1)]),
            transportSyncTable(1), ['fleet_id' => nonUniqueTransportIndex(['1' => [1]]),
                'owner_id' => nonUniqueTransportIndex(['100' => [1]])]);
        $target = Planets::findById(11);
        $target['owner_id'] = 200;
        Planets::update($target);
        $f['target'] = $target;
        $f['account']['User'] = ['id' => 100, 'account_id' => 1000, 'name' => 'Owner'];
        $f['account']['Techs'] = ['spy_tech' => 1, 'combustion_tech' => 0, 'impulse_motor_tech' => 0, 'hyperspace_motor_tech' => 0];
        installTransportRepository(Accounts::class, transportTable([
            Accounts::castRowToSchema(['id' => 1000, 'login' => 'owner'], true),
            Accounts::castRowToSchema(['id' => 2000, 'login' => 'target'], true),
        ]), transportSyncTable(2000), []);
        installTransportRepository(Users::class, transportTable([
            Users::castRowToSchema(['id' => 100, 'account_id' => 1000, 'name' => 'Owner', 'main_planet' => 10, 'current_planet' => 10], true),
            Users::castRowToSchema(['id' => 200, 'account_id' => 2000, 'name' => 'Target', 'main_planet' => 11, 'current_planet' => 11], true),
        ]), transportSyncTable(200), ['account' => uniqueTransportIndex(['1000' => 100, '2000' => 200])]);
        installTransportRepository(Techs::class, transportTable([['id' => 200, 'spy_tech' => 1,
            'energy_tech' => 0, 'metal_tech' => 0, 'crystal_tech' => 0, 'deuterium_tech' => 0]]), transportSyncTable(200), []);
        installTransportRepository(Builds::class, transportTable([['id' => 11, 'metal_mine' => 5]]), transportSyncTable(11), []);
        installTransportRepository(Ships::class, transportTable([['id' => 11, 'small_cargo' => 4, 'spy_sonde' => 2]]), transportSyncTable(11), []);
        installTransportRepository(Defenses::class, transportTable([['id' => 11, 'missile_launcher' => 3]]), transportSyncTable(11), []);
        // Resources.php объявляет обе таблицы ресурсов.
        class_exists(\SPGame\Game\Repositories\Resources::class);
        installTransportRepository(\SPGame\Game\Repositories\PlanetResources::class,
            transportTable([['id' => 11, 'metal' => 100.0, 'crystal' => 200.0, 'deuterium' => 300.0, 'energy' => 0.0]]), transportSyncTable(11), []);
        installTransportRepository(\SPGame\Game\Repositories\UserResources::class,
            transportTable([['id' => 200, 'credit' => 0, 'doubloon' => 0]]), transportSyncTable(200), []);
        installTransportRepository(DebrisFields::class, transportTable(), transportSyncTable(),
            ['galaxy_system_orbit' => new Table(), 'galaxy_system' => new Table()]);
        // FleetCommands и несколько старых repositories имеют общий indexTables.
        $fleetRow = Fleets::getById(1);
        installTransportRepository(Fleets::class, transportTable([$fleetRow]), transportSyncTable(1), [
            'owner_id' => nonUniqueTransportIndex(['100' => [1]]),
            'target_coordinate' => nonUniqueTransportIndex(['0_0_0_0_0' => [1]]),
            'target_fleet' => nonUniqueTransportIndex(['0_0' => [1]]),
        ]);
        $f['fleet'] = FleetsData::getInstance()->get(1);
        $f['coordinates'] = ['type' => CoordinateType::OBJECT->value, 'galaxy' => 1, 'system' => 10,
            'orbit' => 8, 'objectType' => ObjectType::PLANET->value];
        return $f;
    }

    function spyStart(array $f): void
    {
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates($f['coordinates']);
        $travel = $f['fleet']->getTravelInfo($target, $f['account'], 10.0, FleetMissionType::SPY);
        FleetCommandsService::startMission($f['fleet'], $f['account'], FleetMissionType::SPY->value,
            $f['coordinates'], 10, ceil($travel['fuel']['max']), [], 100.0);
    }

    function testSpyThresholdsAndStationaryShips(): void
    {
        $f = spyFixture();
        $target = new AccountData(2000);
        $report = FleetSpyService::inspect($f['fleet'], $f['account'], $target, static fn($min, $max) => $max);
        transportAssert(isset($report['sections']['ships'], $report['sections']['defense']), 'Один зонд при равных технологиях раскрывает корабли/оборону.');
        transportAssert(!isset($report['sections']['buildings'], $report['sections']['research']), 'Скрытые разделы нельзя передавать в JSON.');
        $f['account']['Techs']['spy_tech'] = 10;
        $row = Fleets::getById(1);
        $row['id'] = 2;
        $row['owner_id'] = 200;
        $row['current_orbit'] = 8;
        Fleets::add($row);
        $ships = FleetsShips::findById(1);
        $ships['id'] = $ships['fleet_id'] = 2;
        $ships['owner_id'] = 200;
        $ships['small_cargo_count'] = 10;
        FleetsShips::add($ships);
        $report = FleetSpyService::inspect($f['fleet'], $f['account'], $target, static fn($min, $max) => $max);
        transportAssert(isset($report['sections']['research']), 'Преимущество технологии раскрывает исследования.');
        transportAssert($report['sections']['ships'][202] === 14, 'Неподвижный Fleet должен учитываться вместе с кораблями планеты.');
        $target['Techs']['spy_tech'] = 20;
        $report = FleetSpyService::inspect($f['fleet'], $f['account'], $target, static fn($min, $max) => $max);
        transportAssert(array_keys($report['sections']) === ['resources'], 'При нехватке разведсил доступны только ресурсы.');
    }

    function testSpySurvivalReturnAndPrivacy(): void
    {
        $f = spyFixture();
        spyStart($f);
        $GLOBALS['spyRolls'] = [0, 100];
        FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
        transportAssert($f['fleet']->isReturn(), 'После разведки активируется возвратная MOVE.');
        transportAssert(Fleets::getById(1) !== null && DebrisFields::at(1, 10, 8) === null, 'Выживший флот не создаёт обломки.');
        $ownerMessages = Messages::findByIndex('to', [100]);
        $targetMessages = Messages::findByIndex('to', [200]);
        transportAssert(count($ownerMessages) === 1 && count($targetMessages) === 1, 'Оба игрока получают сообщения.');
        transportAssert(isset(json_decode($ownerMessages[0]['data'], true)['SpyReport']), 'Владельцу нужен отчёт.');
        transportAssert(!isset(json_decode($targetMessages[0]['data'], true)['SpyReport']), 'Цель не должна получать приватный отчёт.');
        FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
        transportAssert($f['fleet']->getStatus() === Fleet::STATUS_IDLE && $f['fleet']->getFuelCurrent() === 0.0,
            'Возврат завершает миссию и выгружает остаток топлива.');
        transportAssert(FleetCommands::getFleetQueue(1) === [], 'Завершённая цепочка очищается.');
        transportAssert(count(Messages::findByIndex('to', [100])) === 2, 'Владельцу приходит сообщение о возврате.');
    }

    function testSpyDestructionAndDebrisAccumulation(): void
    {
        $f = spyFixture(5);
        DebrisFields::addResources(1, 10, 8, 7, 9, 90);
        spyStart($f);
        $GLOBALS['spyRolls'] = [0, 0];
        FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
        $field = DebrisFields::at(1, 10, 8);
        transportAssert($field['metal'] === 7.0 && $field['crystal'] === 1509.0, 'Обломки пяти зондов должны прибавить 1500 кристалла, не заменить старый запас.');
        transportAssert(Fleets::getById(1) === null && FleetCommands::getFleetQueue(1) === [], 'Гибель удаляет флот и возвратные команды.');
        transportAssert(FleetsData::getInstance()->get(1) === null, 'Кэш не должен воскресить уничтоженный Fleet.');
        transportAssert(in_array($field['id'], DebrisFields::getPendingSync()['update'], true), 'Обломки должны попасть в RepositorySaver.');
        transportAssert(DebrisFields::findById(999) === null && DebrisFields::count() === 1, 'Чтение не создаёт пустых обломков.');
        $loss = json_decode(Messages::findByIndex('to', [100])[0]['data'], true);
        transportAssert($loss['SpyReport']['destroyed'] === true, 'Даже уничтоженный флот должен передать отчёт.');
    }

    function testSpyValidationAndUnavailableTarget(): void
    {
        $f = spyFixture();
        try {
            FleetCommandsService::prepareMissionRequest($f['fleet'], $f['account'], FleetMissionType::SPY->value,
                $f['coordinates'], 10, 1, [901 => 1]);
            throw new RuntimeException('Груз принят для SPY.');
        } catch (InvalidArgumentException $e) {}
        transportAssert(FleetCommands::getFleetQueue(1) === [], 'Отклонённая миссия не создаёт команды.');
        spyStart($f);
        Planets::update(['id' => 11, 'owner_id' => 100]);
        FleetCommandsService::completeCommand(FleetCommands::getActiveCommand(1), $f['fleet'], $f['account']);
        transportAssert($f['fleet']->isReturn(), 'Ставшая собственной цель приводит к безопасному возврату.');
        transportAssert(Messages::findByIndex('to', [100])[0]['sample'] === 'FleetSpyUnavailable', 'Отправителю сообщается о недоступной цели.');
        transportAssert((Messages::findByIndex('to', [200]) ?? []) === [], 'Бывший владелец не получает разведданные.');
    }

    function testSpyRejectsOwnTargetAndMissingProbes(): void
    {
        foreach ([false, true] as $noProbes) {
            $f = spyFixture($noProbes ? 0 : 1);
            if (!$noProbes) Planets::update(['id' => 11, 'owner_id' => 100]);
            $rejected = false;
            try {
                FleetCommandsService::prepareMissionRequest($f['fleet'], $f['account'], FleetMissionType::SPY->value,
                    $f['coordinates'], 10, 1, []);
            } catch (InvalidArgumentException $e) { $rejected = true; }
            transportAssert($rejected && FleetCommands::getFleetQueue(1) === [], 'Своя цель/отсутствие зондов должны отклоняться до мутации.');
            transportAssert($f['account']->resourceCount(10, 903) === 10000.0, 'Отклонение не должно списывать топливо.');
        }
    }

    function testSpyMoonAndDebrisTargets(): void
    {
        $f = spyFixture();
        $moon = $f['target'];
        $moon['id'] = 12;
        $moon['planet_type'] = ObjectType::MOON->value;
        Planets::add($moon);
        $coordinates = $f['coordinates'];
        $coordinates['objectType'] = ObjectType::MOON->value;
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates($coordinates);
        transportAssert(in_array(FleetMissionType::SPY->value, $f['fleet']->getAvailableMissions($target, $f['account'])['missions'], true),
            'Существующая чужая луна должна быть доступна для шпионажа.');
        Vars::$attributes[210]['missions'][] = FleetMissionType::RECYCLE->value;
        $coordinates['objectType'] = ObjectType::DEBRIS->value;
        $target = \SPGame\Game\Fleet\FleetTarget::fromCoordinates($coordinates);
        transportAssert($f['fleet']->getAvailableMissions($target, $f['account'])['missions'] === [], 'Пустое поле не даёт миссию сбора.');
        DebrisFields::addResources(1, 10, 8, 0, 300, 100);
        transportAssert(in_array(FleetMissionType::RECYCLE->value, $f['fleet']->getAvailableMissions($target, $f['account'])['missions'], true),
            'Обломки должны обнаруживаться через координаты, не как запись Planets.');
    }

    function testDebrisCostAndInvalidInput(): void
    {
        $f = spyFixture(2);
        $f['fleet']->setShipCount(202, 3);
        $cost = FleetSpyService::debrisFor($f['fleet']);
        transportAssert($cost === [901 => 1800.0, 902 => 2400.0], 'Обломки считаются по каждому реальному типу кораблей.');
        foreach ([-1.0, INF, NAN] as $amount) {
            $rejected = false;
            try { DebrisFields::addResources(1, 10, 8, $amount, 1, 100); }
            catch (InvalidArgumentException $e) { $rejected = true; }
            transportAssert($rejected && DebrisFields::count() === 0, 'Некорректные запасы не должны менять runtime.');
        }
        DebrisFields::addResources(1, 10, 8, 0, 0, 100);
        transportAssert(DebrisFields::count() === 0, 'Нулевое пополнение не создаёт пустого поля.');
    }

    foreach (['testSpyThresholdsAndStationaryShips', 'testSpySurvivalReturnAndPrivacy',
        'testSpyDestructionAndDebrisAccumulation', 'testSpyValidationAndUnavailableTarget',
        'testSpyRejectsOwnTargetAndMissingProbes', 'testSpyMoonAndDebrisTargets',
        'testDebrisCostAndInvalidInput'] as $test) {
        $test();
        echo "OK {$test}\n";
    }
}
