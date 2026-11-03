<?php
declare(strict_types=1);

// Подготовка сборного боя в основном dev. Никакого второго игрового сервера.
require __DIR__.'/../vendor/autoload.php';
use SPGame\Core\{Database,Environment};
use SPGame\Game\Enums\{FleetCommandType as C,FleetMissionType as M};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services as S;
use SPGame\Game\Space\Coordinate;
Environment::init(__DIR__.'/../.env');$db=Database::getInstance();
$check=static function(bool $ok,string $why):void{if(!$ok)throw new LogicException($why);};
$mode=$argv[1]??'';$check(in_array($mode,['--prepare','--attack','--verify'],true),'Нужен режим.');
// SELECT-only при работающем main. Не создаёт локальных копий Swoole-репозиториев.
if($mode==='--verify'){
 $report=$db->fetchOne("SELECT id,payload,ready FROM battle_reports WHERE payload LIKE '%QA Combined enemy%' ORDER BY created_at DESC LIMIT 1");
 $check($report!==null&&(int)$report['ready']===1,'Доклад ещё не сохранён.');$battle=json_decode($report['payload'],true,512,JSON_THROW_ON_ERROR);
 $check($battle['outcome']==='draw'&&count($battle['rounds'])===10,'Неожиданный результат боя.');
 $rows=$db->fetchAll("SELECT * FROM fleets WHERE name IN ('QA Relocate host','QA Combined miner A','QA Combined miner B','QA Combined friend','QA Combined away','QA Combined enemy')");
 $byName=[];foreach($rows as$r)$byName[$r['name']]=$r;
 $check(count($byName)===6,'Не все тестовые флоты сохранились.');
 $expected=[];foreach($byName as$name=>$r)if($name!=='QA Combined away')$expected[]=(int)$r['id'];sort($expected);
 $actual=array_map(static fn($p)=>(int)$p['fleetId'],array_values($battle['participants']));sort($actual);
 $check($actual===$expected,'В бою лишние/потерянные участники.');
 foreach($battle['groups']as$g)$check($g['before']===$g['survived']&&$g['lost']===0,'Потери в контрольном бое.');
 $head=$db->fetchOne('SELECT * FROM mining_checkpoints WHERE site_id=3');$meta=json_decode($head['metadata'],true,512,JSON_THROW_ON_ERROR);
 $check($head['phase']==='applied'&&(float)$head['checkpoint_time']===(float)$battle['startedAt'],'Другой/неприменённый journal.');
 $summary=['battle'=>$report['id'],'revision'=>(int)$head['revision'],'participants'=>$actual];
 foreach(['QA Combined miner A'=>100.0,'QA Combined miner B'=>200.0]as$name=>$xp){
  $f=$byName[$name];$id=(int)$f['id'];$commands=$db->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id='.$id.' AND executed=0 ORDER BY id');
  $check(count($commands)===2&&(int)$commands[0]['command_type']===C::OBSERVE->value&&(float)$commands[0]['execute_time']===0.0,'Нет послебоевой паузы добытчика.');
  $p=json_decode($commands[0]['params'],true,512,JSON_THROW_ON_ERROR);$run=$p['mining'];
  $check($run['phase']==='finished'&&$run['reason']==='battle'&&$p['siteGuard']===3
   &&$p['miningClosed']['source']===$meta['closedFrom']&&(float)$run['endedAt']===(float)$head['checkpoint_time'],'Добытчики закрыты разными batch.');
  $total=array_sum($run['extracted']);$calculated=$run['production']*($run['endedAt']-$run['startedAt'])/3600;
  $check(abs($total-$calculated)<.000001,'Повторное/неполное начисление добычи.');
  foreach([901=>'metal',902=>'crystal']as$res=>$field)$check(abs((float)$f['resource_'.$field]-$run['extracted'][$res])<.000001,'Груз не равен закрытой добыче.');
  $ship=$db->fetchOne('SELECT asteroid_miner_count,asteroid_miner_experience FROM fleets_ships WHERE id='.$id);
  $check((int)$ship['asteroid_miner_count']===2&&(float)$ship['asteroid_miner_experience']===$xp,'Изменён состав/опыт добытчика.');
  $messages=$db->fetchAll("SELECT data FROM messages WHERE sample='FleetMiningResult' AND data LIKE '%".$name."%'");
  $check(count($messages)===1,'Повторное/отсутствующее сообщение добычи.');$message=json_decode($messages[0]['data'],true,512,JSON_THROW_ON_ERROR);
  $elapsed=(float)$f['base_fuel_time']-$battle['finishedAt'];$frozen=(float)$message['ResourcesFuelOnboard'][903];
  $check($elapsed>=0&&abs((float)$f['fuel_current']-($frozen-$elapsed*(float)$f['base_fuel_rate']))<.000001,'Бой/стоянка повторно списали топливо.');
  $check((int)$f['home_site_id']===3&&(int)$f['current_site_id']===3&&(int)$f['current_coordinate_type']===1,'Потеряна ресурсная позиция.');
  $summary['miners'][$id]=['metal'=>(float)$f['resource_metal'],'crystal'=>(float)$f['resource_crystal'],'xp'=>$xp,'fuel'=>(float)$f['fuel_current']];
 }
 $friend=$byName['QA Combined friend'];$guard=$db->fetchOne('SELECT * FROM fleet_commands WHERE fleet_id='.(int)$friend['id'].' AND executed=0');
 $p=json_decode($guard['params'],true,512,JSON_THROW_ON_ERROR);
 $check((int)$guard['command_type']===C::DEFEND->value&&!isset($p['pause'])&&(float)$guard['execute_time']===(float)$p['hold']['until']
  &&abs($p['hold']['until']-$p['hold']['startedAt']-3600)<.000001,'Не восстановлена исходная DEFEND.');
 $away=$byName['QA Combined away'];$check((int)$away['home_site_id']===3&&(int)$away['current_coordinate_type']===2,'Свободная точка стала защитником базы.');
 $check((int)$db->fetchOne('SELECT owner_id FROM resource_bases WHERE id=3')['owner_id']===10,'Потерян контроль объекта.');
 echo json_encode($summary,JSON_THROW_ON_ERROR),PHP_EOL;exit;
}
$socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
$check(($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')==='qafor_260924','Другой QA.');
$check($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'")===null,'Pending journal.');
$check($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'")===null,'Не завершён бой.');
R\Vars::init();
foreach([R\Config::class,R\Accounts::class,R\Users::class,R\Techs::class,R\Friends::class,R\Galaxy::class,
 R\GalaxyOrbits::class,R\Planets::class,R\Builds::class,R\Ships::class,R\Defenses::class,R\Resources::class,
 R\OrbitalResourceSites::class,R\ResourceBases::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,
 R\ResourceSurveys::class,R\SurveyResults::class,R\PlanetBattles::class,R\SpaceBattles::class,
 R\FleetAttackGroups::class,R\FleetExpeditions::class,R\EntitySettings::class,R\Queues::class]as$r)$r::init();
R\MiningCheckpoints::init();
$check((int)(R\ResourceBases::findExistingById(3)['owner_id']??0)===10,'Сектор3 уже не QA10.');
$check(R\Friends::areFriends(9,10),'QA не друзья.');
$at=S\FleetSurveyService::coordinate(R\OrbitalResourceSites::findExistingById(3));
$named=static function(string $name):?array{foreach(R\Fleets::getAll()??[]as$r)if($r['name']===$name)return$r;return null;};
$host=$named('QA Relocate host');$check($host!==null&&(int)$host['home_site_id']===3&&(int)$host['status']===0,'Нет базирующейся охраны.');
$now=(float)sprintf('%.6F',microtime(true));$result=['mode'=>$mode,'now'=>$now];
// Создаём только новые явно названные QA-флоты, не переписываем старые составы.
$create=static function(string $name,int $owner,int $home,int $site,Coordinate $position,int $type,int $count,float $fuel,float $xp=0)use($now):int{
 $row=['name'=>$name,'owner_id'=>$owner,'home_planet_id'=>$site?0:$home,'home_site_id'=>$site,
  'fallback_planet_id'=>$home,'current_position_time'=>$now,'fuel_current'=>$fuel,'fuel_total'=>$fuel,
  'experience'=>$count>0?$xp/$count:0.0];
 foreach(['coordinate_type'=>$position->getType()->value,'galaxy'=>$position->getGalaxy(),'system'=>$position->getSystem(),
  'orbit'=>$position->getOrbit()??0,'object_type'=>$position->getObjectType()?->value??0,'site_id'=>$position->getSiteId()??0,
  'distance'=>$position->getDistance()??0,'angle'=>$position->getAngle()??0]as$k=>$v)$row['current_'.$k]=$v;
 $id=R\Fleets::add($row);R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>$owner,
  R\Vars::$resource[$type].'_count'=>$count,R\Vars::$resource[$type].'_experience'=>$xp]);return$id;
};
if($mode==='--prepare'){
 foreach(['QA Combined miner A','QA Combined miner B','QA Combined friend','QA Combined away']as$name)$check($named($name)===null,'Уже подготовлено.');
 $site=R\OrbitalResourceSites::findExistingById(3);$known=R\ResourceSurveys::findByIndex('owner_site',[10,3]);
 $reveal=S\FleetSurveyService::reveal($site,$known,S\FleetSurveyService::stock($site,$now),$now,100,16,bin2hex(random_bytes(16)));
 if($known)R\ResourceSurveys::update(['id'=>$known['id']]+$reveal);else R\ResourceSurveys::add(['owner_id'=>10,'site_id'=>3]+$reveal);
 foreach([['QA Combined miner A',100],['QA Combined miner B',200]]as[$name,$xp]){
  $id=$create($name,10,27,3,$at,223,2,5000,$xp);$f=S\FleetsData::getInstance()->get($id);
  S\ResourceBaseService::beginStanding($f,new S\AccountData(16),$now,10,$at);$f->save();$result['fleets'][$name]=$id;
 }
 $id=$create('QA Combined friend',9,26,0,$at,203,1,1000);$arrival=$now+30;
 R\Fleets::update(['id'=>$id,'status'=>1,'mission'=>M::DEFEND->value,'current_position_time'=>$arrival]);
 $c=R\FleetCommands::addCommand($id,C::DEFEND,['target'=>$at->toArray(),'targetOwnerId'=>10,'duration'=>3600,'speedFactor'=>10]);
 R\FleetCommands::update(['id'=>$c['id'],'execute_time'=>$arrival]);$result['friend']=$id;
 $point=Coordinate::point(1,10,1000,90);$id=$create('QA Combined away',10,27,3,$point,202,1,1000);
 R\Fleets::update(['id'=>$id,'status'=>1,'mission'=>M::OBSERVE->value]);
 $c=R\FleetCommands::addCommand($id,C::OBSERVE,['target'=>$point->toArray()]);
 S\FleetControlService::beginHold($c,S\FleetsData::getInstance()->get($id),new S\AccountData(16),$now);$result['away']=$id;
}else{
 $expected=[(int)$host['id']];
 foreach(['QA Combined miner A','QA Combined miner B']as$name){
  $r=$named($name);$check($r!==null,'Нет добытчика.');$f=S\FleetsData::getInstance()->get((int)$r['id']);
  $check(S\FleetMiningRuntimeService::canInterruptForBattle($f),'Добытчик ещё не работает.');
  $run=S\FleetMiningRuntimeService::state($f);$check($run['endsAt']>$now+180,'Добыча близка к окончанию.');
  $expected[]=$f->getId();$result['before'][$f->getId()]=['state'=>$run,'fuel'=>$f->getFuelCurrent(),
   'metal'=>$r['resource_metal'],'crystal'=>$r['resource_crystal']];
 }
 $friend=$named('QA Combined friend');$c=R\FleetCommands::getActiveCommand((int)$friend['id']);
 $check(S\FleetResourceDefendService::isCommand($c)&&isset(S\FleetControlService::params($c)['hold']),'Нет работающей DEFEND.');
 $expected[]=(int)$friend['id'];sort($expected);
 $context=S\ResourceBaseService::objectBattleContext($at,$now);$actual=array_keys($context['fleets']);sort($actual);
 $check($actual===$expected,'Общий состав не совпал.');S\ResourceBaseService::assertBattleSupported($context);
 $check($named('QA Combined enemy')===null,'Враг уже создан.');
 $home=R\Planets::findExistingById(30);$check((int)$home['owner_id']===5,'Другая база врага.');
 $arrival=$now+90;$id=$create('QA Combined enemy',5,30,0,$at,203,4,1000);
 R\Fleets::update(['id'=>$id,'status'=>1,'mission'=>M::CAPTURE->value,'current_position_time'=>$arrival]);
 $c=R\FleetCommands::addCommand($id,C::ATTACK,['target'=>$at->toArray(),'resourceCapture'=>true,'speedFactor'=>10]);
 R\FleetCommands::update(['id'=>$c['id'],'execute_time'=>$arrival]);
 R\FleetCommands::addCommand($id,C::CAPTURE,['target'=>$at->toArray(),'combatCapture'=>true,'speedFactor'=>10]);
 R\FleetCommands::addCommand($id,C::STAY,['at'=>$at->toArray(),'speedFactor'=>10]);
 $result+=['enemy'=>$id,'arrival'=>$arrival,'defenders'=>$expected];
}
foreach([R\ResourceSurveys::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class]as$r){
 $r::syncToDatabase();$p=$r::getPendingSync();$check($p['update']===[]&&$p['delete']===[],'Не сохранено:'.$r);
}
echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;
