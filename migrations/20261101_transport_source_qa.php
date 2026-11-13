<?php
declare(strict_types=1);

// B016: только новый post-loss QA-checkpoint основного dev, без второго мира.
require __DIR__.'/../vendor/autoload.php';
use SPGame\Core\{Database,Environment};
use SPGame\Game\Services\FleetMissionBuilder as Builder;
use SPGame\Game\Space\{Coordinate,ObjectType};
Environment::init(__DIR__.'/../.env');$db=Database::getInstance();
$mode=$argv[1]??'';
$guard=static function(bool$ok):void{if(!$ok)throw new LogicException('Другой QA-контекст B016.');};
$guard(in_array($mode,['--inspect','--before','--after','--move-only'],true));
$guard(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login']??'')==='qaown_260924');
$guard(($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login']??'')==='qafor_260924');
$home=$db->fetchOne('SELECT * FROM planets WHERE id=26');$host=$db->fetchOne('SELECT * FROM planets WHERE id=27');
$guard([(int)$home['owner_id'],(int)$host['owner_id']] === [9,10]);
$guard([(int)$home['galaxy'],(int)$home['system'],(int)$home['planet'],(int)$home['planet_type']] === [1,6,2,1]);
$guard([(int)$host['galaxy'],(int)$host['system'],(int)$host['planet'],(int)$host['planet_type']] === [1,10,5,1]);
$source=Coordinate::object(1,7,3,ObjectType::ASTEROID_SECTOR,siteId:19);
$base=Coordinate::object((int)$home['galaxy'],(int)$home['system'],(int)$home['planet'],ObjectType::PLANET);
if($mode!=='--inspect'){
 $socket=@stream_socket_client('tcp://127.0.0.1:'.Environment::getInt('WS_PORT',9501),$errno,$error,.5);
 if(is_resource($socket)){fclose($socket);throw new LogicException('Сначала остановите main.');}
 $guard($db->fetchOne('SELECT id FROM resource_bases WHERE id=19')===null);
 $site=$db->fetchOne('SELECT galaxy,system,orbit,site_type,sector FROM orbital_resource_sites WHERE id=19');
 $guard(array_map('intval',array_values($site))===[1,7,3,2,2]);
 $now=(float)sprintf('%.6F',microtime(true));$end=$now+($mode==='--after'?90:25);
 $planet=$mode==='--after'?$host:$home;
 $target=Coordinate::object((int)$planet['galaxy'],(int)$planet['system'],(int)$planet['planet'],ObjectType::PLANET);
 $radius=(float)$db->fetchOne('SELECT distance FROM galaxy_orbits WHERE galaxy=? AND system=? AND orbit=?',[$planet['galaxy'],$planet['system'],$planet['planet']])['distance'];
 $factor=(float)$db->fetchOne("SELECT value FROM config WHERE name='SpeedPlanets'")['value'];
 $point=static function(float$t)use($planet,$radius,$factor):array{
  $a=fmod((float)$planet['deg']+max(0,$t-(float)$planet['update_time'])/3600*(float)$planet['speed']*$factor/24*((int)$planet['rotation']===0?-1:1),360);
  return Coordinate::point((int)$planet['galaxy'],(int)$planet['system'],$radius,$a<0?$a+360:$a)->toArray();
 };
 $start=$point($now);$start['angle']=fmod($start['angle']+355,360);
 $insert=static function(string$t,array$r)use($db):void{$db->query('INSERT INTO `'.$t.'` (`'.implode('`,`',array_keys($r)).'`) VALUES ('.implode(',',array_fill(0,count($r),'?')).')',array_values($r));};
 $template=$db->fetchOne('SELECT * FROM fleets WHERE id=122');$ships=$db->fetchOne('SELECT * FROM fleets_ships WHERE id=122');
 $guard((int)$template['owner_id']===9&&(int)$template['status']===0);
 $db->beginTransaction();try{
  $id=(int)$db->fetchOne('SELECT MAX(id) n FROM fleets')['n'];$cid=(int)$db->fetchOne('SELECT MAX(id) n FROM fleet_commands')['n'];
  foreach($mode==='--before'?['before']:($mode==='--move-only'?['moveonly']:['legacy','route','snapshot'])as$kind){
   $guard($db->fetchOne('SELECT id FROM fleets WHERE name=?',['QA B016 '.$kind])===null);++$id;
   $row=array_replace($template,['id'=>$id,'name'=>'QA B016 '.$kind,'mission'=>10,'status'=>1,'home_planet_id'=>26,'home_site_id'=>0,'fallback_planet_id'=>26,
    'fuel_total'=>5030,'fuel_current'=>5000,'resource_metal'=>31,'resource_crystal'=>17,'resource_deuterium'=>9,'is_return'=>0,
    'current_coordinate_type'=>2,'current_galaxy'=>$start['galaxy'],'current_system'=>$start['system'],'current_orbit'=>0,'current_object_type'=>0,'current_site_id'=>0,
    'current_distance'=>$radius,'current_angle'=>$start['angle'],'current_position_time'=>$now,
    'start_coordinate_type'=>1,'start_galaxy'=>1,'start_system'=>7,'start_orbit'=>3,'start_object_type'=>5,'start_site_id'=>19,
    'target_coordinate_type'=>1,'target_galaxy'=>$planet['galaxy'],'target_system'=>$planet['system'],'target_orbit'=>$planet['planet'],'target_object_type'=>1,'target_site_id'=>0,
    'base_fuel_time'=>0,'base_fuel_rate'=>0,'base_fuel_reserve'=>0,'base_fuel_speed'=>0]);$insert('fleets',$row);
   $s=$ships;foreach($s as$key=>&$value)if(str_ends_with($key,'_count')||str_ends_with($key,'_experience'))$value=0;unset($value);
   $insert('fleets_ships',array_replace($s,['id'=>$id,'fleet_id'=>$id,'big_ship_cargo_count'=>1,'big_ship_cargo_experience'=>11]));
   $defs=[];
   if($kind==='route'){
    foreach([[10,['target'=>$base]],[32,['target'=>$base]],[43,['target'=>$source,'cancelled'=>true]],[10,['target'=>$target]],[11,['target'=>$target]]]as[$type,$params]){
     $defs[]=['type'=>$type,'params'=>$params,'executed'=>1,'time'=>$now-60];
    }
   }
   if($kind==='moveonly'){
    $at=Coordinate::object((int)$host['galaxy'],(int)$host['system'],(int)$host['planet'],ObjectType::PLANET);
    $defs[]=['type'=>10,'params'=>['target'=>$at,'planStepId'=>'b016:move'],'executed'=>1,'time'=>$now-90];
    $defs[]=['type'=>11,'params'=>['target'=>$target,'planStepId'=>'b016:transport'],'executed'=>1,'time'=>$now-60];
   }
   $defs[]=['type'=>10,'params'=>['target'=>$target,'motionStart'=>$start,'motionEnd'=>$point($end),'startedAt'=>$now,'fuelCost'=>30,'speedFactor'=>10]
    +($kind==='moveonly'?['planStepId'=>'b016:transport']:[]), 'time'=>$end];
   $delivery=['target'=>$target,'percent'=>100,'unloadFuel'=>false,'speedFactor'=>10];
   if($kind==='snapshot'){
    foreach(Builder::buildTransport($source,$target)as$c)if($c['type']->value===32){$delivery+=$c['params'];break;}
    $guard(($delivery['transportSource']??null)===$source->toArray());
   }
   $defs[]=['type'=>32,'params'=>$delivery];
   foreach(Builder::buildReturn($target,$base)as$c)$defs[]=['type'=>$c['type']->value,'params'=>$c['params']+['returnLeg'=>true,'speedFactor'=>10]];
   foreach($defs as$c)$insert('fleet_commands',['id'=>++$cid,'fleet_id'=>$id,'command_type'=>$c['type'],'execute_time'=>$c['time']??0,
    'trigger_event'=>'','target_id'=>0,'params'=>json_encode($c['params'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'executed'=>$c['executed']??0,'create_time'=>$now]);
  }
  $db->commit();
 }catch(Throwable$e){$db->rollBack();throw$e;}
}
$fleets=$db->fetchAll("SELECT id,name,status,home_planet_id,fuel_current FROM fleets WHERE name LIKE 'QA B016 %' ORDER BY id");
echo json_encode(['fleets'=>$fleets],JSON_THROW_ON_ERROR),PHP_EOL;
