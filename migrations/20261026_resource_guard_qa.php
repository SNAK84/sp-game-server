<?php

declare(strict_types=1);

// Подготовка checkpoint только QA9/10 при остановленном main. Не игровой сервер.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};

Environment::init(__DIR__ . '/../.env'); $db = Database::getInstance();
$check = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите main.'); }
$mode = $argv[1] ?? '';
$check(in_array($mode, ['--prepare', '--battle', '--cargo'], true), 'Требуется --prepare/--battle/--cargo.');
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924'
    && ($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login'] ?? '') === 'qafor_260924', 'Другие QA-аккаунты.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") === null, 'Pending journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'") === null, 'Активный бой.');
R\Vars::init();
$repositories = [R\Config::class, R\Accounts::class, R\Users::class, R\Techs::class, R\Friends::class,
    R\Galaxy::class, R\GalaxyOrbits::class, R\Planets::class, R\Builds::class, R\Ships::class, R\Defenses::class,
    R\Resources::class, R\OrbitalResourceSites::class, R\ResourceBases::class, R\Fleets::class, R\FleetsShips::class,
    R\FleetCommands::class, R\ResourceSurveys::class, R\SurveyResults::class, R\PlanetBattles::class,
    R\SpaceBattles::class, R\FleetAttackGroups::class, R\FleetExpeditions::class, R\EntitySettings::class, R\Queues::class];
foreach ($repositories as $repository) $repository::init();
R\MiningCheckpoints::init();
$owner = new S\AccountData(15); $enemy = new S\AccountData(16);
$site = R\OrbitalResourceSites::findExistingById(19); $at = S\FleetSurveyService::coordinate($site);
$now = (float)sprintf('%.6F', microtime(true));
$home = static function (int $id): Coordinate {
    $p = R\Planets::findExistingById($id);
    return Coordinate::object((int)$p['galaxy'], (int)$p['system'], (int)$p['planet'], ObjectType::PLANET);
};
$find = static function (string $name, int $owner): ?array {
    foreach (R\Fleets::findByIndex('owner_id', [$owner]) ?? [] as $row) if ($row['name'] === $name) return $row;
    return null;
};
if ($mode === '--cargo') {
    $guard = $find('QA Paid guard', 9);
    $check($guard !== null && (int)$guard['home_site_id'] === 19 && (int)$guard['status'] === 0
        && R\FleetCommands::getFleetQueue((int)$guard['id']) === []
        && in_array((float)$guard['resource_metal'], [0.0, 1.0], true), 'Охрана изменилась.');
    R\Fleets::update(['id' => $guard['id'], 'resource_metal' => 1.0,
        'fuel_current' => min(4998.0, (float)$guard['fuel_current'])]);
    $result = ['guard' => $guard['id'], 'issuedMetal' => 1 - (float)$guard['resource_metal'], 'fuelBefore' => $guard['fuel_current']];
} elseif ($mode === '--prepare') {
    $check(R\ResourceBases::findExistingById(19) === null && $find('QA Paid miner', 9) === null, 'Fixture уже создан/объект занят.');
    foreach ([61, 62] as $id) {
        $f = S\FleetsData::getInstance()->get($id);
        $check($f !== null && $f->getOwnerId() === 9 && $f->getCurrentCoordinate()->toArray() === $at->toArray()
            && S\FleetControlService::state($f)['reason'] === 'battle', 'Старый QA-добытчик изменился.');
    }
    R\ResourceBases::add(['id' => 19, 'owner_id' => 9, 'created_at' => $now, 'updated_at' => $now]);
    $create = static function (string $name, int $ownerId, int $planet, Coordinate $position, array $ships,
        bool $based = false) use ($now): int {
        $row = ['owner_id' => $ownerId, 'name' => $name, 'home_planet_id' => $based ? 0 : $planet,
            'home_site_id' => $based ? 19 : 0, 'fallback_planet_id' => $planet,
            'status' => 1, 'mission' => Mission::MINING->value, 'current_position_time' => $now,
            'fuel_current' => 5000, 'fuel_total' => 5000];
        foreach (['start', 'current', 'target'] as $prefix) foreach ([
            'coordinate_type' => $position->getType()->value, 'galaxy' => $position->getGalaxy(), 'system' => $position->getSystem(),
            'orbit' => $position->getOrbit() ?? 0, 'object_type' => $position->getObjectType()?->value ?? 0,
            'site_id' => $position->getSiteId() ?? 0, 'distance' => $position->getDistance() ?? 0,
            'angle' => $position->getAngle() ?? 0] as $field => $value) $row[$prefix . '_' . $field] = $value;
        $id = R\Fleets::add($row); $groups = ['id' => $id, 'fleet_id' => $id, 'owner_id' => $ownerId];
        foreach ($ships as $type => $count) $groups[R\Vars::$resource[$type] . '_count'] = $count;
        R\FleetsShips::add($groups); return $id;
    };
    $miner = $create('QA Paid miner', 9, 26, $at, [223 => 2], true);
    $c = R\FleetCommands::addCommand($miner, Command::MINING, ['target' => $at->toArray(), 'speedFactor' => 10,
        'miningRequest' => ['mode' => 'mix', 'duration' => 3600, 'finish' => 'stay', 'speedFactor' => 10]]);
    R\FleetCommands::update(['id' => $c['id'], 'execute_time' => $now + 15]);
    R\Fleets::update(['id' => $miner, 'current_position_time' => $now + 15]);
    R\FleetCommands::addCommand($miner, Command::STAY, ['at' => $at->toArray(), 'returnLeg' => true, 'homeSiteId' => 19]);
    $guard = $create('QA Paid guard', 9, 26, $at, [202 => 1], true);
    R\Fleets::update(['id' => $guard, 'status' => 0, 'mission' => 0]);
    $physical = (new S\FleetFlightService())->resolvePhysicalPoint($at, $now);
    $point = Coordinate::point($physical->galaxy, $physical->system, $physical->radius, $physical->angle);
    $attacker = $create('QA Paid attacker', 10, 27, $point, [204 => 10, 202 => 2]);
    R\Fleets::update(['id' => $attacker, 'mission' => Mission::ATTACK->value]);
    // Пока объектная атака закрыта, fixture ждёт в POINT. Не обход видимости в игровом API.
    R\FleetCommands::addCommand($attacker, Command::OBSERVE, ['target' => $point->toArray(), 'pause' => 'manual']);
    $result = compact('miner', 'guard', 'attacker');
} else {
    $miner = $find('QA Paid miner', 9); $guard = $find('QA Paid guard', 9); $attacker = $find('QA Paid attacker', 10);
    $check($miner !== null && $guard !== null && $attacker !== null, 'Нет fixture.');
    $target = S\FleetsData::getInstance()->get((int)$miner['id']);
    $working = S\FleetMiningRuntimeService::canInterruptForBattle($target);
    $pending = R\FleetCommands::getNextPendingCommand($target->getId());
    $closed = $pending === null ? [] : S\FleetControlService::params($pending);
    $check($working || ($closed['miningClosed']['reason'] ?? null) === 'battle', 'MINING ещё не начался/завершился.');
    $context = S\ResourceBaseService::battleContext($target, $now); S\ResourceBaseService::assertBattleSupported($context);
    $ids = array_keys($context['fleets']); sort($ids);
    $expected = [61, 62, (int)$miner['id'], (int)$guard['id']]; sort($expected);
    $check($ids === $expected, 'Другой состав защиты: ' . json_encode($ids));
    if ($working) S\FleetMiningRuntimeService::interruptManyForBattle([$target], $now);
    $physical = (new S\FleetFlightService())->resolvePhysicalPoint($at, $now);
    $point = Coordinate::point($physical->galaxy, $physical->system, $physical->radius, $physical->angle);
    $d = $participants = []; $before = [];
    $freeze = new ReflectionMethod(S\FleetSpaceBattleService::class, 'freeze');
    foreach ($context['fleets'] as $id => $fleet) {
        S\ResourceBaseService::settleStanding($fleet, $now);
        // Небольшой избыток ускоряет проверку резерва, но не меняет сам расход/баланс.
        S\ResourceBaseService::beginStanding($fleet, $owner, $now, 10, $at);
        $reserve = $fleet->getBaseFuelState()['reserve'];
        if ($id !== (int)$guard['id']) $fleet->setFuelCurrent($reserve + ($id === 61 ? .25 : 5.0));
        $idle = $fleet->getStatus() === 0;
        $freeze->invoke(null, $fleet, $point, $now, $idle);
        $d += S\FleetAttackService::fleetGroups($fleet, $owner);
        $participants[$id] = S\FleetAttackService::participant($fleet, 'defender') + ['siteIdle' => $idle, 'siteSpeed' => 10.0];
        $before[$id] = ['fuel' => $fleet->getFuelCurrent(), 'cargo' => $participants[$id]['cargo']];
    }
    $a = S\FleetsData::getInstance()->get((int)$attacker['id']);
    R\FleetCommands::clearFleetQueue($a->getId());
    $a->setCurrentCoordinate($point, $now); $a->save();
    $action = S\FleetControlService::add($a->getId(), Command::ATTACK, ['target' => $at->toArray(), 'speedFactor' => 10]);
    foreach (S\FleetMissionBuilder::buildReturn($point, $home(27)) as $step) S\FleetControlService::add($a->getId(), $step['type'],
        $step['params'] + ['returnLeg' => true, 'speedFactor' => 10]);
    $participants[$a->getId()] = S\FleetAttackService::participant($a, 'attacker', (int)$action['id']);
    $key = R\SpaceBattles::nextId();
    $battleId = S\FleetBattleService::beginSpace($key, S\FleetAttackService::fleetGroups($a, $enemy), $d, $now,
        R\Vars::$rapidfire, array_keys($participants), ['spaceTarget' => ['name' => S\FleetSurveyService::label($site), 'coordinate' => $point->toArray()],
            'attackerId' => 10, 'defenderId' => 9, 'participants' => $participants, 'fleetDebrisPercent' => 30,
            'resourceSite' => ['id' => 19, 'ownerId' => 9, 'coordinate' => $at->toArray()]]);
    S\FleetControlService::patchCommand((int)$action['id'], ['execute_time' => $now + 10,
        'params' => json_encode(['target' => $at->toArray(), 'speedFactor' => 10, 'startedAt' => $now,
            'battleWait' => ['scope' => 'space', 'key' => $key, 'id' => $battleId]], JSON_THROW_ON_ERROR)]);
    $result = ['battleKey' => $key, 'battleId' => $battleId, 'defenders' => $ids, 'before' => $before, 'at' => $now];
}
foreach ([R\Fleets::class, R\FleetsShips::class, R\FleetCommands::class, R\ResourceBases::class, R\SpaceBattles::class] as $repository) {
    $repository::syncToDatabase(); $sync = $repository::getPendingSync();
    $check($sync['update'] === [] && $sync['delete'] === [], 'Не сохранён fixture: ' . $repository);
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), PHP_EOL;
