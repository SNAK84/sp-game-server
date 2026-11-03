<?php
declare(strict_types=1);

// Новые QA B034: реальное обнаружение/отправку/редактор проверяет основной main.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\FleetCommandType as C;
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};
if (($argv[1]??'')!=='--prepare') throw new LogicException('Нужен --prepare при остановленном main.');
$socket=@stream_socket_client('tcp://127.0.0.1:9501',$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
Environment::init(__DIR__.'/../.env');$db=Database::getInstance();
if (($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login']??'')!=='qaown_260924'
    || ($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')!=='qafor_260924'
    || $db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")!==null
    || $db->fetchOne("SELECT id FROM planet_battles WHERE phase<>'applied'")!==null
    || $db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")!==null
    || $db->fetchOne('SELECT id FROM fleets WHERE name LIKE ?',['QA B034 %'])!==null) {
    throw new LogicException('QA-контекст изменён или сцена занята.');
}
R\Vars::init();
foreach ([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,
    R\Galaxy::class,R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,
    R\Resources::class,R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,
    R\FleetCommands::class,R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,
    R\SpaceBattles::class,R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class,
    R\SensorContacts::class,R\PhalanxScans::class] as $repo) $repo::init();
R\MiningCheckpoints::init();
// Старые QA уже занимают11 активных слотов. Больше меняется только эта технология QA9.
$tech=R\Techs::findExistingById(9);$oldComputer=(int)$tech['computer_tech'];
R\Techs::update(['id'=>9,'computer_tech'=>max(20,$oldComputer)]);
$own=new S\AccountData(15);$foreign=new S\AccountData(16);$now=microtime(true);
$edge=S\GalaxyGenerator::getSpecialOrbit(1,6,R\GalaxyOrbits::SYSTEM_EDGE);
$target=Coordinate::point(1,6,(float)$edge['distance']*.6,135);
$sensor=Coordinate::point(1,6,(float)$edge['distance']*.4,0);
$home=R\Planets::findExistingById(26);
if ((int)($home['owner_id']??0)!==9) throw new LogicException('Изменена база QA9.');
$at=Coordinate::object((int)$home['galaxy'],(int)$home['system'],(int)$home['planet'],ObjectType::PLANET);
$ids=[];
foreach (['before'=>[9,26,214,1,0.0,$at], 'after'=>[9,26,214,1,0.0,$at],
    'sensor'=>[9,26,221,256,20000.0,$sensor], 'target'=>[10,27,203,1,5000.0,$target]] as $role=>[$user,$base,$type,$count,$fuel,$point]) {
    $holding=in_array($role,['sensor','target'],true);
    $id=R\Fleets::add(['owner_id'=>$user,'name'=>'QA B034 '.$role,'home_planet_id'=>$base,'fallback_planet_id'=>$base,
        'status'=>$holding?1:0,'mission'=>$holding?30:0,'fuel_current'=>$fuel,'fuel_total'=>$fuel,'current_position_time'=>$now]);
    R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>$user,R\Vars::$resource[$type].'_count'=>$count]);
    $f=S\FleetsData::getInstance()->get($id);$f->setCurrentCoordinate($point,$now);$f->save();
    if($holding) {
        $f->setStartCoordinate($point);$f->setTargetCoordinate($point);$f->save();
        R\FleetCommands::addCommand($id,C::OBSERVE,['target'=>$point->toArray()]);
        S\FleetCommandsService::activateNextCommand($f,$user===9?$own:$foreign);
        if($role==='sensor') S\FleetSensorService::setMode($f,$own,true,$now);
    }
    $ids[$role]=$id;
}
foreach([R\Techs::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class] as $repo) {
    $repo::syncToDatabase();$pending=$repo::getPendingSync();
    if($pending['update']!==[]||$pending['delete']!==[])throw new RuntimeException('Не сохранена QA-сцена.');
}
echo json_encode(['fleets'=>$ids,'computerTech'=>[$oldComputer,max(20,$oldComputer)],'target'=>$target->toArray()]),PHP_EOL;
