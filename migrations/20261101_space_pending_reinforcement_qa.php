<?php
declare(strict_types=1);

// Прибывшая QA-сцена в основном dev. Раунд и admission исполняет обычный main.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\FleetCommandType as C;
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};
Environment::init(__DIR__ . '/../.env');$db=Database::getInstance();
$scene=$argv[1]??'';
if (!in_array($scene,['before','before2','after','cleanup-before','recover-before2'],true)) throw new LogicException('Нужна QA-сцена при остановленном main.');
$socket=@stream_socket_client('tcp://127.0.0.1:9501',$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
if (($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login']??'')!=='qaown_260924'
    || ($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')!=='qafor_260924'
    || $db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")!==null
    || $db->fetchOne("SELECT id FROM planet_battles WHERE phase<>'applied'")!==null
    || $db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")!==null
    || (!in_array($scene,['cleanup-before','recover-before2'],true) && $db->fetchOne('SELECT id FROM fleets WHERE name LIKE ?',['QA B031 '.$scene.' %'])!==null)) {
    throw new LogicException('Другой QA-контекст, сцена уже есть или работают бои/checkpoint.');
}
R\Vars::init();
foreach ([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,
    R\Galaxy::class,R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,
    R\Resources::class,R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,
    R\FleetCommands::class,R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,
    R\SpaceBattles::class,R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class] as $repo) $repo::init();
R\MiningCheckpoints::init();
if (in_array($scene,['cleanup-before','recover-before2'],true)) {
    // Только созданные этим Stage QA: дефект подготовки / воспроизведённая B031.
    $recover=$scene==='recover-before2';$ids=$recover?[11402=>6584,11403=>6589]:[10378=>5559,10379=>5563];
    foreach ($ids as $id=>$action) {
        $f=R\Fleets::findExistingById($id);$c=R\FleetCommands::getCommand($action);
        $role=$id===array_key_first($ids)?'lateAttack':'lateDefense';
        if (($f['name']??'')!=='QA B031 '.($recover?'before2':'before').' '.$role
            || (int)($c['fleet_id']??0)!==$id || (int)$c['command_type']!==C::ATTACK->value
            || !empty($c['executed']) || isset(S\FleetControlService::params($c)['battleWait'])
            || ($recover && (S\FleetControlService::params($c)['pause']??null)!=='battle')) {
            throw new LogicException('Изменился контекст первого QA-прогона.');
        }
        $p=S\FleetControlService::params($c);unset($p['pause']);
        S\FleetControlService::patchCommand($action,['execute_time'=>microtime(true),'executed'=>1,'params'=>json_encode($p,JSON_THROW_ON_ERROR)]);
        $next=R\FleetCommands::getNextPendingCommand($id);
        $p=S\FleetControlService::params($next);$p['pause']='manual';
        S\FleetControlService::patchCommand((int)$next['id'],['execute_time'=>0.0,'params'=>json_encode($p,JSON_THROW_ON_ERROR)]);
    }
    R\FleetCommands::syncToDatabase();
    echo json_encode(['qaManualPauseAfterRecovery'=>array_keys($ids)]),PHP_EOL;
    exit;
}
$start=(float)sprintf('%.6F',microtime(true)+90);$late=$start+5;
$point=Coordinate::point(1,10,5000,0);
$owners=[9=>new S\AccountData(15),10=>new S\AccountData(16)];$fleets=[];$actions=[];
foreach (['winner'=>[10,207,100,50000.0,0.0], 'victim'=>[9,210,1,0.0,0.0],
    'lateAttack'=>[10,203,1,1000.0,321.0], 'lateDefense'=>[9,203,1,1000.0,654.0]] as $role=>[$owner,$type,$count,$fuel,$xp]) {
    $home=$owner===9?26:27;$at=$start;
    $id=R\Fleets::add(['owner_id'=>$owner,'name'=>'QA B031 '.$scene.' '.$role,'home_planet_id'=>$home,
        'fallback_planet_id'=>$home,'status'=>1,'mission'=>$role==='victim'?30:1,'fuel_current'=>$fuel,'fuel_total'=>$fuel,
        'experience'=>$count>0?$xp/$count:0.0,'current_position_time'=>$at]);
    R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>$owner,
        R\Vars::$resource[$type].'_count'=>$count,R\Vars::$resource[$type].'_experience'=>$xp]);
    $f=S\FleetsData::getInstance()->get($id);$f->setStartCoordinate($point);$f->setCurrentCoordinate($point,$at);$f->save();$fleets[$role]=$f;
}
$key=R\SpaceBattles::nextId();
foreach ($fleets as $role=>$fleet) {
    $id=$fleet->getId();$home=R\Planets::findExistingById($fleet->getHomePlanetId());
    $home=Coordinate::object((int)$home['galaxy'],(int)$home['system'],(int)$home['planet'],ObjectType::PLANET);
    if($role==='victim') {
        $c=R\FleetCommands::addCommand($id,C::OBSERVE,['target'=>$point->toArray(),'pause'=>'battle']);
        $fleet->setTargetCoordinate($point);
    } else {
        $target=$fleets[$role==='lateDefense'?'winner':'victim']->getId();
        $fleet->setTargetCoordinate(Coordinate::fleet($target,$point),$start);
        if(str_starts_with($role,'late')) {
            // Обычный MOVE активирует ATTACK при прибытии; сам ATTACK не due-таймер.
            $from=Coordinate::point(1,10,4999,0);
            $duration=(new S\FleetFlightService())->calculateCommandDuration(C::MOVE_IN_SYSTEM,$from,$point,$start,
                $fleet->getTotalSpeed($owners[$fleet->getOwnerId()]),10);
            $fleet->setStartCoordinate($from);$fleet->setCurrentCoordinate($from,$late-$duration);
            R\FleetCommands::addCommand($id,C::MOVE_IN_SYSTEM,['target'=>$point->toArray(),'speedFactor'=>10]);
        }
        $c=R\FleetCommands::addCommand($id,C::ATTACK,['target'=>Coordinate::fleet($target,$point)->toArray(),'speedFactor'=>10]);
        if($role==='winner') R\FleetCommands::activateCommand((int)$c['id'],$start+10);
        $actions[$role]=(int)$c['id'];
        foreach(S\FleetMissionBuilder::buildReturn($point,$home) as $step) {
            R\FleetCommands::addCommand($id,$step['type'],$step['params']+['speedFactor'=>10,'returnLeg'=>true]);
        }
    }
    if(str_starts_with($role,'late')) {$fleet->getResources()[901]->setCount(123);$fleet->getResources()[902]->setCount(45);$fleet->getResources()[903]->setCount(67);}
    $fleet->save();
    if(str_starts_with($role,'late')) S\FleetCommandsService::activateNextCommand($fleet,$owners[$fleet->getOwnerId()]);
}
$a=$fleets['winner'];$d=$fleets['victim'];
$uuid=S\FleetBattleService::beginSpace($key,S\FleetAttackService::fleetGroups($a,$owners[10]),S\FleetAttackService::fleetGroups($d,$owners[9]),
    $start,R\Vars::$rapidfire,[$a->getId(),$d->getId()],['spaceTarget'=>['name'=>'QA B031 '.$scene,'coordinate'=>$point->toArray()],
        'attackerId'=>10,'defenderId'=>9,'fleetDebrisPercent'=>30,'participants'=>[
            $a->getId()=>S\FleetAttackService::participant($a,'attacker',$actions['winner']),
            $d->getId()=>S\FleetAttackService::participant($d,'defender')]]);
$c=R\FleetCommands::getCommand($actions['winner']);$p=S\FleetControlService::params($c);
$p['battleWait']=['scope'=>'space','key'=>$key,'id'=>$uuid];$p['startedAt']=$start;
S\FleetControlService::patchCommand((int)$c['id'],['params'=>json_encode($p,JSON_THROW_ON_ERROR)]);
foreach([R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,R\SpaceBattles::class] as $repo){
    $repo::syncToDatabase();$pending=$repo::getPendingSync();
    if($pending['update']!==[]||$pending['delete']!==[])throw new RuntimeException('Не сохранена QA-сцена.');
}
echo json_encode(['scene'=>$scene,'key'=>$key,'uuid'=>$uuid,'startAt'=>$start,'lateAt'=>$late,
    'fleets'=>array_map(static fn($f):int=>$f->getId(),$fleets),'actions'=>$actions]),PHP_EOL;
