<?php

declare(strict_types=1);

// Только подготовка QA9/10 offline. Полёт и бой выполняет основной dev-сервер.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};

Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$check = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$mode = $argv[1] ?? '';
$check(in_array($mode,['--prepare','--repair-capture','--prepare-belt','--prepare-belt-retry'],true), 'Требуется явный режим подготовки QA.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите main.'); }
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924'
    && ($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login'] ?? '') === 'qafor_260924', 'Другие QA-аккаунты.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") === null, 'Pending journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'") === null, 'Активный бой.');
R\Vars::init();
foreach ([R\Config::class, R\Accounts::class, R\Users::class, R\Techs::class, R\Friends::class,
    R\Galaxy::class, R\GalaxyOrbits::class, R\Planets::class, R\Builds::class, R\Ships::class, R\Defenses::class,
    R\Resources::class, R\OrbitalResourceSites::class, R\ResourceBases::class, R\Fleets::class, R\FleetsShips::class,
    R\FleetCommands::class, R\ResourceSurveys::class, R\SurveyResults::class, R\PlanetBattles::class,
    R\SpaceBattles::class, R\FleetAttackGroups::class, R\FleetExpeditions::class, R\EntitySettings::class, R\Queues::class] as $repository) $repository::init();
R\MiningCheckpoints::init();
if (in_array($mode,['--prepare-belt','--prepare-belt-retry'],true)) {
    $retry = $mode === '--prepare-belt-retry';
    $check(($retry ? R\ResourceBases::findExistingById(5) === null : (int)(R\ResourceBases::findExistingById(5)['owner_id'] ?? 0) === 10)
        && R\ResourceBases::findExistingById(1) === null, 'Первый захват не сохранён/сектор занят.');
    foreach (R\Fleets::findByIndex('owner_id',[9]) ?? [] as $row) $check($row['name'] !== 'QA Capture belt guard', 'Fixture уже создан.');
    $now = (float)sprintf('%.6F',microtime(true));
    $tech = R\Techs::findExistingById(10); $before = $tech[R\Vars::$resource[136]];
    R\Techs::update(['id'=>10,R\Vars::$resource[136]=>max(4,(int)$before)]);
    $site = R\OrbitalResourceSites::findExistingById(1); $at = S\FleetSurveyService::coordinate($site);
    R\ResourceBases::restoreDeletedRow(['id'=>1,'owner_id'=>9,'created_at'=>$now,'updated_at'=>$now]);
    $row = ['owner_id'=>9,'name'=>'QA Capture belt guard','home_planet_id'=>0,'home_site_id'=>1,
        'fallback_planet_id'=>26,'status'=>0,'mission'=>0,'current_position_time'=>$now,'fuel_current'=>90,'fuel_total'=>90];
    foreach (['start','current','target'] as $prefix) foreach (['coordinate_type'=>1,'galaxy'=>$at->getGalaxy(),
        'system'=>$at->getSystem(),'orbit'=>$at->getOrbit(),'object_type'=>$at->getObjectType()->value,'site_id'=>1] as $field=>$value) $row[$prefix.'_'.$field]=$value;
    $id = R\Fleets::add($row); R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>9,R\Vars::$resource[204].'_count'=>2]);
    $fleet = S\FleetsData::getInstance()->get($id);
    S\ResourceBaseService::beginStanding($fleet,new S\AccountData(15),$now,10,$at); $fleet->save();
    foreach ([R\Techs::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class] as $repository) {
        $repository::syncToDatabase(); $pending=$repository::getPendingSync();
        $check($pending['update']===[] && $pending['delete']===[],'Не сохранён fixture.');
    }
    $attacker = null;
    if ($retry) {
        $source = S\FleetSurveyService::coordinate(R\OrbitalResourceSites::findExistingById(5));
        R\ResourceBases::restoreDeletedRow(['id'=>5,'owner_id'=>10,'created_at'=>$now,'updated_at'=>$now]);
        $row = ['owner_id'=>10,'name'=>'QA Capture belt','home_planet_id'=>0,'home_site_id'=>5,
            'fallback_planet_id'=>27,'status'=>0,'mission'=>0,'current_position_time'=>$now,'fuel_current'=>1000,'fuel_total'=>1000];
        foreach (['start','current','target'] as $prefix) foreach (['coordinate_type'=>1,'galaxy'=>$source->getGalaxy(),
            'system'=>$source->getSystem(),'orbit'=>$source->getOrbit(),'object_type'=>$source->getObjectType()->value,'site_id'=>5] as $field=>$value) $row[$prefix.'_'.$field]=$value;
        $attacker = R\Fleets::add($row); R\FleetsShips::add(['id'=>$attacker,'fleet_id'=>$attacker,'owner_id'=>10,
            R\Vars::$resource[206].'_count'=>10,R\Vars::$resource[203].'_count'=>2]);
        $fleet = S\FleetsData::getInstance()->get($attacker);
        S\ResourceBaseService::beginStanding($fleet,new S\AccountData(16),$now,10,$source); $fleet->save();
        foreach ([R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class] as $repository) $repository::syncToDatabase();
    }
    echo json_encode(['guard'=>$id,'attacker'=>$attacker,'site'=>1,'miningBefore'=>$before,'miningAfter'=>max(4,(int)$before)],JSON_THROW_ON_ERROR),PHP_EOL;
    exit;
}
if ($mode === '--repair-capture') {
    $fleet = R\Fleets::findExistingById(80);
    $battle = R\SpaceBattles::state(2);
    $check($fleet !== null && $fleet['name'] === 'QA Capture win' && (int)$fleet['owner_id'] === 10
        && (int)$fleet['home_site_id'] === 5 && (int)$fleet['current_site_id'] === 5 && (int)$fleet['status'] === 0
        && R\FleetCommands::getFleetQueue(80) === [] && ($battle['phase'] ?? null) === 'applied'
        && ($battle['resolution']['outcome'] ?? null) === 'attacker'
        && isset($battle['mission']['participants'][80]) && R\ResourceBases::findExistingById(5) === null,
        'Нет подтверждённого QA-захвата с потерянной записью контроля.');
    $row = ['id'=>5,'owner_id'=>10,'created_at'=>(float)$fleet['current_position_time'],'updated_at'=>(float)$fleet['current_position_time']];
    R\ResourceBases::restoreDeletedRow($row); R\ResourceBases::syncToDatabase();
    $check((int)($db->fetchOne('SELECT owner_id FROM resource_bases WHERE id=5')['owner_id'] ?? 0) === 10, 'Не сохранён контроль QA.');
    echo json_encode(['recoveredControl'=>$row,'economicReplay'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
    exit;
}
$check(R\ResourceBases::findExistingById(5) === null, 'Тестовая цель уже занята.');
foreach (R\Fleets::getAll() as $row) $check(!str_starts_with($row['name'], 'QA Capture '), 'Fixture уже существует.');
$before = [];
foreach ([9 => [108=>10, 134=>10, 136=>3], 10 => [108=>10, 134=>10, 136=>3, 115=>100]] as $user=>$levels) {
    $tech = R\Techs::findExistingById($user); $patch = ['id'=>$user];
    foreach ($levels as $id=>$level) {
        $name = R\Vars::$resource[$id]; $before[$user][$name] = $tech[$name];
        $patch[$name] = max((int)$tech[$name], $level);
    }
    R\Techs::update($patch);
}
$now = (float)sprintf('%.6F', microtime(true));
$site = R\OrbitalResourceSites::findExistingById(5);
$at = S\FleetSurveyService::coordinate($site);
$known = R\ResourceSurveys::findByIndex('owner_site', [9,5]);
$reveal = S\FleetSurveyService::reveal($site, $known, S\FleetSurveyService::stock($site,$now), $now,100,16,bin2hex(random_bytes(16)));
if ($known) R\ResourceSurveys::update(['id'=>$known['id']]+$reveal);
else R\ResourceSurveys::add(['owner_id'=>9,'site_id'=>5]+$reveal);
R\ResourceBases::add(['id'=>5,'owner_id'=>9,'created_at'=>$now,'updated_at'=>$now]);
$create = static function (string $name, int $owner, int $planet, Coordinate $position, array $ships, bool $based=false) use ($now): int {
    $row = ['owner_id'=>$owner,'name'=>'QA Capture '.$name,'home_planet_id'=>$based ? 0 : $planet,
        'home_site_id'=>$based ? 5 : 0,'fallback_planet_id'=>$planet,'status'=>0,'mission'=>0,'current_position_time'=>$now,
        'fuel_current'=>$based ? 500 : 0,'fuel_total'=>$based ? 500 : 0];
    foreach (['start','current','target'] as $prefix) foreach ([
        'coordinate_type'=>$position->getType()->value,'galaxy'=>$position->getGalaxy(),'system'=>$position->getSystem(),
        'orbit'=>$position->getOrbit() ?? 0,'object_type'=>$position->getObjectType()?->value ?? 0,
        'site_id'=>$position->getSiteId() ?? 0,'distance'=>$position->getDistance() ?? 0,'angle'=>$position->getAngle() ?? 0] as $field=>$value) $row[$prefix.'_'.$field]=$value;
    $id = R\Fleets::add($row); $groups = ['id'=>$id,'fleet_id'=>$id,'owner_id'=>$owner];
    foreach ($ships as $type=>$count) $groups[R\Vars::$resource[$type].'_count']=$count;
    R\FleetsShips::add($groups); return $id;
};
$guard = $create('guard',9,26,$at,[204=>2],true);
R\Fleets::update(['id'=>$guard,'fuel_current'=>90,'fuel_total'=>90]);
$miner = $create('miner',9,26,$at,[224=>1],true);
$owner = new S\AccountData(15);
foreach ([$guard,$miner] as $id) {
    $fleet = S\FleetsData::getInstance()->get($id);
    S\ResourceBaseService::beginStanding($fleet,$owner,$now,10,$at); $fleet->save();
}
$away = $create('absent',9,26,Coordinate::point(1,10,1000,90),[202=>1],true);
R\Fleets::update(['id'=>$away,'status'=>1,'mission'=>Mission::OBSERVE->value]);
R\FleetCommands::addCommand($away,Command::OBSERVE,['target'=>Coordinate::point(1,10,1000,90)->toArray(),'pause'=>'manual']);
$p = R\Planets::findExistingById(27);
$home = Coordinate::object((int)$p['galaxy'],(int)$p['system'],(int)$p['planet'],ObjectType::PLANET);
$draw = $create('draw',10,27,$home,[203=>1]);
$win = $create('win',10,27,$home,[206=>10,203=>2]);
foreach ([R\Techs::class,R\ResourceSurveys::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class] as $repository) {
    $repository::syncToDatabase(); $sync = $repository::getPendingSync();
    $check($sync['update'] === [] && $sync['delete'] === [], 'Не сохранён fixture: '.$repository);
}
echo json_encode(compact('guard','miner','away','draw','win','before')+['site'=>5,'preparedAt'=>$now],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),PHP_EOL;
