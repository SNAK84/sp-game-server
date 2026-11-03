<?php

declare(strict_types=1);

// Прибывшие QA-флоты. Обследование запускает обычный due-handler основного main.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\{Coordinate, ObjectType};

Environment::init(__DIR__ . '/../.env'); $db=Database::getInstance();
$check=static function(bool $ok,string $why):void {if(!$ok)throw new LogicException($why);};
$check(($argv[1]??'')==='--prepare','Требуется --prepare.');
$socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login']??'')==='qaown_260924'
    &&($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')==='qafor_260924','Другие QA-аккаунты.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")===null,'Pending journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")===null,'Активный бой.');
R\Vars::init();
foreach([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,
    R\Galaxy::class,R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,
    R\Resources::class,R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,
    R\FleetCommands::class,R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,
    R\SpaceBattles::class,R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class] as $repository)$repository::init();
R\MiningCheckpoints::init();
$check(R\ResourceBases::findExistingById(2)===null,'Сектор2 занят.');
foreach(R\Fleets::findByIndex('owner_id',[9])??[] as $row)$check(!str_starts_with($row['name'],'QA Survey battle'),'Fixture уже существует.');
$check(R\ResourceSurveys::findByIndex('owner_site',[9,2])===null,'В секторе уже есть личное знание QA9.');
$attacker=R\Fleets::findExistingById(79);
$check($attacker!==null && $attacker['name']==='QA Capture draw' && (int)$attacker['owner_id']===10
    && (int)$attacker['status']===0 && R\FleetCommands::getFleetQueue(79)===[],'QA79 ещё не вернулся.');
$tech=R\Techs::findExistingById(9); $patch=['id'=>9]; $before=[];
foreach([115=>100,117=>100] as $type=>$level){$name=R\Vars::$resource[$type];$before[$name]=$tech[$name];$patch[$name]=max($level,(int)$tech[$name]);}
R\Techs::update($patch);
$now=(float)sprintf('%.6F',microtime(true)); $site=R\OrbitalResourceSites::findExistingById(2);
$at=S\FleetSurveyService::coordinate($site); $planet=R\Planets::findExistingById(26);
$home=Coordinate::object((int)$planet['galaxy'],(int)$planet['system'],(int)$planet['planet'],ObjectType::PLANET);
R\ResourceBases::restoreDeletedRow(['id'=>2,'owner_id'=>9,'created_at'=>$now,'updated_at'=>$now]);
$create=static function(string $name,array $ships,bool $based,float $fuel)use($now,$at):int{
    $row=['owner_id'=>9,'name'=>'QA Survey battle '.$name,'home_planet_id'=>$based?0:26,'home_site_id'=>$based?2:0,
        'fallback_planet_id'=>26,'status'=>$based?0:1,'mission'=>$based?0:Mission::RESEARCH->value,
        'current_position_time'=>$now,'fuel_current'=>$fuel,'fuel_total'=>$fuel];
    foreach(['start','current','target']as $prefix)foreach(['coordinate_type'=>1,'galaxy'=>$at->getGalaxy(),
        'system'=>$at->getSystem(),'orbit'=>$at->getOrbit(),'object_type'=>$at->getObjectType()->value,'site_id'=>2]as $key=>$value)$row[$prefix.'_'.$key]=$value;
    $id=R\Fleets::add($row);$groups=['id'=>$id,'fleet_id'=>$id,'owner_id'=>9];
    foreach($ships as $type=>$count)$groups[R\Vars::$resource[$type].'_count']=$count;
    R\FleetsShips::add($groups);return $id;
};
$guard=$create('guard',[204=>2],true,90);$survey=$create('research',[222=>1,203=>1],false,500);
$surveyFleet=S\FleetsData::getInstance()->get($survey);$surveyFleet->setStartCoordinate($home);$surveyFleet->save();
$owner=new S\AccountData(15);$fleet=S\FleetsData::getInstance()->get($guard);
S\ResourceBaseService::beginStanding($fleet,$owner,$now,10,$at);$fleet->save();
$command=R\FleetCommands::addCommand($survey,Command::RESEARCH,['target'=>$at->toArray(),'speedFactor'=>10]);
R\FleetCommands::update(['id'=>$command['id'],'execute_time'=>$now+1]);
foreach(S\FleetMissionBuilder::buildReturn($at,$home)as $step)R\FleetCommands::addCommand($survey,$step['type'],$step['params']+['speedFactor'=>10,'returnLeg'=>true]);
foreach([R\Techs::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class]as $repository){
    $repository::syncToDatabase();$pending=$repository::getPendingSync();
    $check($pending['update']===[] && $pending['delete']===[],'Не сохранён fixture:'.$repository);
}
echo json_encode(compact('guard','survey','command','before')+['site'=>2,'preparedAt'=>$now,
    'estimate'=>S\FleetSurveyService::estimate(S\FleetsData::getInstance()->get($survey),$owner,$at)],JSON_THROW_ON_ERROR),PHP_EOL;
