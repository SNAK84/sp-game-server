<?php

declare(strict_types=1);

// Только прибывшие QA-обследователи. Рабочую фазу и бой исполняет основной main.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};

Environment::init(__DIR__ . '/../.env'); $db = Database::getInstance();
$check = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$check(in_array($argv[1] ?? '', ['--prepare', '--fuel-pause'], true), 'Требуется --prepare или --fuel-pause.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите main.'); }
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924'
    && ($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login'] ?? '') === 'qafor_260924', 'Другие QA-аккаунты.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") === null, 'Pending mining journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'") === null, 'Незавершённый бой.');
R\Vars::init();
foreach ([R\Config::class, R\Accounts::class, R\Users::class, R\Techs::class, R\Friends::class,
    R\Galaxy::class, R\GalaxyOrbits::class, R\Planets::class, R\Builds::class, R\Ships::class, R\Defenses::class,
    R\Resources::class, R\OrbitalResourceSites::class, R\ResourceBases::class, R\Fleets::class, R\FleetsShips::class,
    R\FleetCommands::class, R\ResourceSurveys::class, R\SurveyResults::class, R\PlanetBattles::class,
    R\SpaceBattles::class, R\FleetAttackGroups::class, R\FleetExpeditions::class, R\EntitySettings::class, R\Queues::class] as $repository) $repository::init();
R\MiningCheckpoints::init();
$check((int)(R\ResourceBases::findExistingById(2)['owner_id'] ?? 0) === 9, 'Сектор2 уже не принадлежит QA9.');
$mode = $argv[1] === '--fuel-pause' ? 'fuel' : 'return';
$name = 'QA Survey auto ' . $mode;
foreach (R\Fleets::findByIndex('owner_id', [9]) ?? [] as $row) {
    $check($row['name'] !== $name, 'Этот QA-флот уже существует.');
    $active = R\FleetCommands::getActiveCommand((int)$row['id']);
    $check($active === null || (int)$active['command_type'] !== Command::RESEARCH->value
        || (int)(S\FleetControlService::params($active)['target']['siteId'] ?? 0) !== 2, 'Другое обследование ещё работает.');
}
$now = (float)sprintf('%.6F', microtime(true));
$at = S\FleetSurveyService::coordinate(R\OrbitalResourceSites::findExistingById(2));
$planet = R\Planets::findExistingById(26);
$check((int)($planet['owner_id'] ?? 0) === 9, 'Потеряна собственная база QA9.');
$home = Coordinate::object((int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet'], ObjectType::PLANET);
$fuel = $mode === 'fuel' ? 3.0 : 500.0;
$row = ['owner_id'=>9, 'name'=>$name, 'home_planet_id'=>26, 'fallback_planet_id'=>26,
    'status'=>1, 'mission'=>Mission::RESEARCH->value, 'current_position_time'=>$now,
    'fuel_current'=>$fuel, 'fuel_total'=>500.0];
foreach (['start','current','target'] as $prefix) foreach (['coordinate_type'=>1, 'galaxy'=>$at->getGalaxy(),
    'system'=>$at->getSystem(), 'orbit'=>$at->getOrbit(), 'object_type'=>$at->getObjectType()->value, 'site_id'=>2] as $key=>$value) $row[$prefix.'_'.$key] = $value;
$fleetId = R\Fleets::add($row);
R\FleetsShips::add(['id'=>$fleetId,'fleet_id'=>$fleetId,'owner_id'=>9,
    R\Vars::$resource[222].'_count'=>1, R\Vars::$resource[203].'_count'=>1]);
$fleet = S\FleetsData::getInstance()->get($fleetId); $fleet->setStartCoordinate($home); $fleet->save();
$command = R\FleetCommands::addCommand($fleetId, Command::RESEARCH, ['target'=>$at->toArray(),'speedFactor'=>10]);
R\FleetCommands::update(['id'=>$command['id'],'execute_time'=>$now+1]);
foreach (S\FleetMissionBuilder::buildReturn($at,$home) as $step) R\FleetCommands::addCommand($fleetId,$step['type'],
    $step['params']+['speedFactor'=>10,'returnLeg'=>true]);
foreach ([R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class] as $repository) {
    $repository::syncToDatabase(); $pending = $repository::getPendingSync();
    $check($pending['update'] === [] && $pending['delete'] === [], 'Не сохранён fixture:'.$repository);
}
echo json_encode(compact('fleetId','mode','fuel')+['commandId'=>$command['id'],'siteId'=>2,'preparedAt'=>$now],JSON_THROW_ON_ERROR),PHP_EOL;
