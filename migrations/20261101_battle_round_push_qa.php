<?php
declare(strict_types=1);

// Подготовка только QA B044 при остановленном основном main; раунды считает он.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\FleetCommandType as C;
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};

$phase=$argv[1]??'';
if (!in_array($phase,['before','after','after2','after3'],true)) throw new LogicException('Нужна QA-фаза before/after/after2/after3.');
$socket=@stream_socket_client('tcp://127.0.0.1:9501',$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
Environment::init(__DIR__.'/../.env');$db=Database::getInstance();
foreach([15=>'qaown_260924',16=>'qafor_260924',18=>'qa_b018_home'] as $id=>$login) {
    if(($db->fetchOne('SELECT login FROM accounts WHERE id=?',[$id])['login']??'')!==$login) throw new LogicException('Другой QA-контекст.');
}
if($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")!==null
    ||$db->fetchOne("SELECT id FROM planet_battles WHERE phase<>'applied'")!==null
    ||$db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")!==null
    ||$db->fetchOne('SELECT id FROM fleets WHERE name LIKE ?',['QA B044 '.$phase.' %'])!==null) {
    throw new LogicException('QA-сцена уже есть или работают бои/checkpoint.');
}
R\Vars::init();
foreach([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,
    R\Galaxy::class,R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,
    R\Resources::class,R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,
    R\FleetCommands::class,R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,
    R\SpaceBattles::class,R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class] as $repo) $repo::init();
R\MiningCheckpoints::init();
$owners=[9=>new S\AccountData(15),10=>new S\AccountData(16),12=>new S\AccountData(18)];
$planet=R\Planets::findExistingById(36);
if((int)($planet['owner_id']??0)!==12||array_sum(array_diff_key(R\Ships::findById(36),['id'=>true]))!==0
    ||array_sum(array_diff_key(R\Defenses::findById(36),['id'=>true]))!==0) throw new LogicException('Изменена пустая QA-колония36.');
$target=Coordinate::object((int)$planet['galaxy'],(int)$planet['system'],(int)$planet['planet'],ObjectType::PLANET);
$find=static function(string $name):?array {
    foreach(R\Fleets::getAll() as $f)if($f['name']===$name)return $f;return null;
};
if($phase==='before'){
    $occupied=[];
    foreach(R\Fleets::getAll() as $f)foreach(['home_site_id','current_site_id'] as $key)if((int)$f[$key]>0)$occupied[(int)$f[$key]]=true;
    $sites=array_values(array_filter(R\OrbitalResourceSites::getAll(),static fn($s)=>(int)$s['galaxy']===1
        &&in_array((int)$s['system'],[7,10],true)&&(int)$s['site_type']===1
        &&!isset($occupied[(int)$s['id']])&&R\ResourceBases::findExistingById((int)$s['id'])===null));
    usort($sites,static fn($a,$b)=>$a['id']<=>$b['id']);$site=$sites[0]??null;
    if($site===null||$find('QA B044 space guard')!==null||$find('QA B044 planet guard')!==null)throw new LogicException('Нет свободного объекта или guards уже созданы.');
}else{
    $saved=$find('QA B044 space guard');$site=R\OrbitalResourceSites::findExistingById((int)($saved['home_site_id']??0));
    if($site===null||(int)(R\ResourceBases::findExistingById((int)$site['id'])['owner_id']??0)!==9)throw new LogicException('Потеряна QA-база before.');
}
$start=(float)sprintf('%.6F',microtime(true)+100);$planetStart=$start+35;
$at=S\FleetSurveyService::coordinate($site);
$physical=(new S\FleetFlightService())->resolvePhysicalPoint($at,$start);
$point=Coordinate::point($physical->galaxy,$physical->system,$physical->radius,$physical->angle);
if($phase==='before')R\ResourceBases::add(['id'=>(int)$site['id'],'owner_id'=>9,'created_at'=>$start,'updated_at'=>$start]);
$create=static function(string $name,int $user,int $home,int $status,int $mission,Coordinate $where,float $time,float $fuel=12000.0) {
    $id=R\Fleets::add(['owner_id'=>$user,'name'=>$name,'home_planet_id'=>$home,'fallback_planet_id'=>$home,
        'status'=>$status,'mission'=>$mission,'fuel_current'=>$fuel,'fuel_total'=>$fuel,'current_position_time'=>$time]);
    R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>$user,R\Vars::$resource[203].'_count'=>1]);
    $f=S\FleetsData::getInstance()->get($id);$f->setCurrentCoordinate($where,$time);$f->save();return $f;
};
if($phase==='before'){
    $guard=$create('QA B044 space guard',9,26,0,0,$point,$start);
    $guard->setHomeResourceSite((int)$site['id'],26);$guard->save();
    $planetGuard=$create('QA B044 planet guard',12,36,0,0,$target,$planetStart,0.0);
}else{
    $guard=S\FleetsData::getInstance()->get((int)$find('QA B044 space guard')['id']);
    $planetGuard=S\FleetsData::getInstance()->get((int)$find('QA B044 planet guard')['id']);
    foreach([$guard,$planetGuard] as $f)if($f===null||$f->getStatus()!==0||$f->getShips()[203]->getCount()!==1
        ||R\FleetCommands::getActiveCommands($f->getId())!==[])throw new LogicException('Изменён QA guard.');
    $guard->setBaseFuelState(null);$guard->setCurrentCoordinate($point,$start);$guard->save();
}
// Реальное планетарное admission ниже должно включить только нашего нового guard.
foreach(R\Fleets::getAll() as $row)if((int)$row['owner_id']===12&&(int)$row['status']===0
    &&(int)$row['current_coordinate_type']===1&&(int)$row['current_galaxy']===$target->getGalaxy()
    &&(int)$row['current_system']===$target->getSystem()&&(int)$row['current_orbit']===$target->getOrbit()
    &&(int)$row['id']!==$planetGuard->getId())throw new LogicException('У QA-цели появился другой базирующийся флот.');
$spaceAttacker=$create('QA B044 '.$phase.' space attacker',10,27,1,45,$point,$start);
$spaceAttacker->setStartCoordinate($point);$spaceAttacker->setTargetCoordinate($at);$spaceAttacker->save();
$action=R\FleetCommands::addCommand($spaceAttacker->getId(),C::ATTACK,['target'=>$at->toArray(),'resourceCapture'=>true,'speedFactor'=>10]);
R\FleetCommands::activateCommand((int)$action['id'],$start+10);
$return=static function($fleet,Coordinate $from,int $home):void {
    $p=R\Planets::findExistingById($home);$to=Coordinate::object((int)$p['galaxy'],(int)$p['system'],(int)$p['planet'],ObjectType::PLANET);
    foreach(S\FleetMissionBuilder::buildReturn($from,$to) as $step)R\FleetCommands::addCommand($fleet->getId(),$step['type'],$step['params']+['speedFactor'=>10,'returnLeg'=>true]);
};
$return($spaceAttacker,$at,27);
$a=S\FleetAttackService::fleetGroups($spaceAttacker,$owners[10]);$d=S\FleetAttackService::fleetGroups($guard,$owners[9]);
if(reset($a)['attack']>reset($d)['shield']||reset($d)['attack']>reset($a)['shield'])throw new LogicException('QA-группы не гарантируют draw.');
$key=R\SpaceBattles::nextId();
$uuid=S\FleetBattleService::beginSpace($key,$a,$d,$start,R\Vars::$rapidfire,[$spaceAttacker->getId(),$guard->getId()],
    ['spaceTarget'=>['name'=>'QA B044','coordinate'=>$point->toArray()],'attackerId'=>10,'defenderId'=>9,'fleetDebrisPercent'=>30,
        'resourceSite'=>['id'=>(int)$site['id'],'ownerId'=>9,'coordinate'=>$at->toArray()],
        'participants'=>[$spaceAttacker->getId()=>S\FleetAttackService::participant($spaceAttacker,'attacker',(int)$action['id']),
            $guard->getId()=>S\FleetAttackService::participant($guard,'defender')+['siteIdle'=>true,'siteSpeed'=>10.0]]]);
$params=S\FleetControlService::params($action);$params['battleWait']=['scope'=>'space','key'=>$key,'id'=>$uuid];$params['startedAt']=$start;
S\FleetControlService::patchCommand((int)$action['id'],['params'=>json_encode($params,JSON_THROW_ON_ERROR)]);
$planetAttacker=$create('QA B044 '.$phase.' planet attacker',9,26,1,1,$target,$planetStart);
$planetAttacker->setStartCoordinate($target);$planetAttacker->setTargetCoordinate($target);$planetAttacker->save();
$a=S\FleetAttackService::fleetGroups($planetAttacker,$owners[9]);$d=S\FleetAttackService::fleetGroups($planetGuard,$owners[12]);
if(reset($a)['attack']>reset($d)['shield']||reset($d)['attack']>reset($a)['shield'])throw new LogicException('QA-планетарные группы не гарантируют draw.');
R\FleetCommands::addCommand($planetAttacker->getId(),C::ATTACK,['target'=>$target->toArray(),'speedFactor'=>10]);
$return($planetAttacker,$target,26);
S\FleetCommandsService::activateNextCommand($planetAttacker,$owners[9]);
$battle=R\PlanetBattles::state(36);
if($battle===null||count($battle['mission']['participants'])!==2)throw new LogicException('Изменён состав планетарного боя.');
$all=R\FleetCommands::getAll();$fleetIds=array_values(array_unique([...array_column(R\Fleets::getAll(),'id'),...array_column($all,'fleet_id'),0,PHP_INT_MAX]));
foreach($fleetIds as $fleetId){
    $chain=array_values(array_filter($all,static fn($c)=>(int)$c['fleet_id']===(int)$fleetId));
    usort($chain,static fn($a,$b)=>$a['id']<=>$b['id']);
    $queue=array_values(array_filter($chain,static fn($c)=>!$c['executed']));
    $active=array_values(array_filter($queue,static fn($c)=>(float)$c['execute_time']>0));
    $pending=array_values(array_filter($queue,static fn($c)=>(float)$c['execute_time']===0.0));
    if(R\FleetCommands::getFleetCommandChain((int)$fleetId)!==$chain||R\FleetCommands::getFleetQueue((int)$fleetId)!==$queue
        ||R\FleetCommands::getActiveCommands((int)$fleetId)!==$active||R\FleetCommands::getNextPendingCommand((int)$fleetId)!==($pending[0]??null)) {
        throw new LogicException('Индекс команд не совпадает с полным проходом для Fleet#'.$fleetId);
    }
}
foreach([R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,R\SpaceBattles::class,R\PlanetBattles::class] as $repo){
    $repo::syncToDatabase();$pending=$repo::getPendingSync();if($pending['update']!==[]||$pending['delete']!==[])throw new RuntimeException('Не сохранена QA-сцена.');
}
echo json_encode(['phase'=>$phase,'site'=>(int)$site['id'],'commandIndexChecks'=>count($fleetIds)*4,'space'=>['key'=>$key,'uuid'=>$uuid,'startAt'=>$start,
    'guard'=>$guard->getId(),'attacker'=>$spaceAttacker->getId()],
    'planet'=>['key'=>36,'uuid'=>$battle['id'],'startAt'=>$planetStart,'guard'=>$planetGuard->getId(),'attacker'=>$planetAttacker->getId()]]),PHP_EOL;
