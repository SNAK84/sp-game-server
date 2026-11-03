<?php
declare(strict_types=1);

// Только новые QA-флоты и свободная база4. Раунды/результат считает основной main.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\FleetCommandType as C;
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};

if (($argv[1]??'')!=='--prepare') throw new LogicException('Нужен --prepare при остановленном main.');
$socket=@stream_socket_client('tcp://127.0.0.1:9501',$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
Environment::init(__DIR__ . '/../.env');$db=Database::getInstance();
if (($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login']??'')!=='qaown_260924'
    || ($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')!=='qafor_260924'
    || $db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")!==null
    || $db->fetchOne("SELECT id FROM planet_battles WHERE phase<>'applied'")!==null
    || $db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")!==null
    || $db->fetchOne('SELECT id FROM resource_bases WHERE id=4')!==null
    || $db->fetchOne('SELECT id FROM fleets WHERE home_site_id=4 OR current_site_id=4 OR name LIKE ?',['QA B033 %'])!==null) {
    throw new LogicException('QA-контекст изменён или сцена занята.');
}
R\Vars::init();
foreach ([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,
    R\Galaxy::class,R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,
    R\Resources::class,R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,
    R\FleetCommands::class,R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,
    R\SpaceBattles::class,R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class] as $repo) $repo::init();
R\MiningCheckpoints::init();
$site=R\OrbitalResourceSites::findExistingById(4);
if ((int)($site['galaxy']??0)!==1 || (int)$site['system']!==10 || (int)$site['site_type']!==1) {
    throw new LogicException('Изменилась свободная QA-база.');
}
$at=S\FleetSurveyService::coordinate($site);$start=(float)sprintf('%.6F',microtime(true)+240);
$physical=(new S\FleetFlightService())->resolvePhysicalPoint($at,$start);
$point=Coordinate::point($physical->galaxy,$physical->system,$physical->radius,$physical->angle);
$owner=new S\AccountData(15);$enemy=new S\AccountData(16);$actors=[];
R\ResourceBases::add(['id'=>4,'owner_id'=>9,'created_at'=>$start,'updated_at'=>$start]);
foreach (['guard'=>[9,26,0],'attacker'=>[10,27,1]] as $role=>[$user,$home,$status]) {
    $id=R\Fleets::add(['owner_id'=>$user,'name'=>'QA B033 '.$role,'home_planet_id'=>$role==='guard'?0:$home,
        'home_site_id'=>$role==='guard'?4:0,'fallback_planet_id'=>$home,'status'=>$status,'mission'=>$role==='guard'?0:45,
        'fuel_current'=>12000.0,'fuel_total'=>12000.0,'current_position_time'=>$start]);
    R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>$user,R\Vars::$resource[203].'_count'=>1]);
    $f=S\FleetsData::getInstance()->get($id);$f->setCurrentCoordinate($point,$start);
    if($role==='attacker') {$f->setStartCoordinate($point);$f->setTargetCoordinate($at);}
    $f->save();$actors[$role]=$f;
}
$attacker=$actors['attacker'];$guard=$actors['guard'];
$c=R\FleetCommands::addCommand($attacker->getId(),C::ATTACK,['target'=>$at->toArray(),'resourceCapture'=>true,'speedFactor'=>10]);
R\FleetCommands::activateCommand((int)$c['id'],$start+10);
$home=R\Planets::findExistingById(27);
$home=Coordinate::object((int)$home['galaxy'],(int)$home['system'],(int)$home['planet'],ObjectType::PLANET);
foreach(S\FleetMissionBuilder::buildReturn($at,$home) as $step) R\FleetCommands::addCommand($attacker->getId(),$step['type'],
    $step['params']+['speedFactor'=>10,'returnLeg'=>true]);
$key=R\SpaceBattles::nextId();
$uuid=S\FleetBattleService::beginSpace($key,S\FleetAttackService::fleetGroups($attacker,$enemy),S\FleetAttackService::fleetGroups($guard,$owner),
    $start,R\Vars::$rapidfire,[$attacker->getId(),$guard->getId()],['spaceTarget'=>['name'=>'QA B033','coordinate'=>$point->toArray()],
        'attackerId'=>10,'defenderId'=>9,'fleetDebrisPercent'=>30,'resourceSite'=>['id'=>4,'ownerId'=>9,'coordinate'=>$at->toArray()],
        'participants'=>[$attacker->getId()=>S\FleetAttackService::participant($attacker,'attacker',(int)$c['id']),
            $guard->getId()=>S\FleetAttackService::participant($guard,'defender')+['siteIdle'=>true,'siteSpeed'=>10.0]]]);
$p=S\FleetControlService::params($c);$p['battleWait']=['scope'=>'space','key'=>$key,'id'=>$uuid];$p['startedAt']=$start;
S\FleetControlService::patchCommand((int)$c['id'],['params'=>json_encode($p,JSON_THROW_ON_ERROR)]);
foreach([R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,R\SpaceBattles::class] as $repo) {
    $repo::syncToDatabase();$pending=$repo::getPendingSync();
    if($pending['update']!==[]||$pending['delete']!==[])throw new RuntimeException('Не сохранена QA-сцена.');
}
echo json_encode(['siteId'=>4,'key'=>$key,'uuid'=>$uuid,'startAt'=>$start,'guard'=>$guard->getId(),'attacker'=>$attacker->getId()]),PHP_EOL;
