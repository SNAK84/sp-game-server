<?php

declare(strict_types=1);
define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__.'/FleetPlanSwooleTest.php';

use SPGame\Core\Message;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Enums\FleetCommandType as SensorCommand;
use SPGame\Game\Repositories\{FleetCommands, Fleets, FleetsShips, SensorContacts, Ships, Techs, Vars};
use SPGame\Game\Services\{AccountData, FleetControlService as SensorControl, FleetSensorService as Sensor, FleetRendezvousService, FleetsData, PageActionService};

function sensorFixture(): array
{
    nativePlanFixture();
    Vars::$resource[221]='sensor_scout';
    Vars::$resource[135]='sensor_tech';
    Vars::$reslist['fleet'][]=221;
    Vars::$attributes[221]=['capacity'=>1000,'speed'=>10000,'consumption'=>50,'consumption2'=>50,'tech'=>2,'missions'=>[30]];
    Vars::$pricelist[221]=[901=>10000,902=>20000,903=>10000];
    Vars::$CombatCaps[221]=['attack'=>5,'shield'=>50];
    $rows=Techs::getAll();
    foreach($rows as &$row){$row['sensor_tech']=$row['id']===1?3:0;$row['spy_tech']=3;}unset($row);
    researchRepository(Techs::class,$rows,['sensor_tech','spy_tech']);
    $rows=FleetsShips::getAll();
    foreach($rows as &$row){$row['sensor_scout_count']=$row['fleet_id']===1?1:0;$row['small_cargo_count']=$row['fleet_id']===2?100:10;}unset($row);
    defendColumns(FleetsShips::class,['sensor_scout_count'=>Swoole\Table::TYPE_INT,'sensor_scout_status'=>Swoole\Table::TYPE_INT,
        'sensor_scout_morale'=>Swoole\Table::TYPE_FLOAT,'sensor_scout_experience'=>Swoole\Table::TYPE_FLOAT,'sensor_scout_damage'=>Swoole\Table::TYPE_FLOAT]);
    researchRepository(FleetsShips::class,$rows);
    researchRepository(Ships::class,Ships::getAll(),['sensor_scout']);
    foreach([1,2]as$id){
        Fleets::update(['id'=>$id,'mission'=>30,'status'=>1,'current_coordinate_type'=>2,'current_distance'=>1000*$id,
            'current_angle'=>10*$id,'current_position_time'=>1000,'fuel_current'=>100,'fuel_total'=>100]);
        $command=SensorControl::add($id,SensorCommand::OBSERVE,['target'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>1000*$id,'angle'=>10*$id],
            'hold'=>['rate'=>.001,'startedAt'=>1000,'lastAt'=>1000]]);
        SensorControl::patchCommand($command['id'],['execute_time'=>100000.0]);
    }
    $table=new Swoole\Table(16);$table->column('expires_at',Swoole\Table::TYPE_FLOAT);$table->column('payload',Swoole\Table::TYPE_STRING,SensorContacts::MAX_BYTES);$table->create();
    (new ReflectionProperty(SensorContacts::class,'table'))->setValue(null,$table);
    (new ReflectionProperty(Sensor::class,'nextScanAt'))->setValue(null,0.0);
    (new ReflectionProperty(SPGame\Game\Services\FleetsData::class,'instance'))->setValue(null,null);
    return[new AccountData(1),new Fleet(1),new Fleet(2)];
}

if (defined('SP_GAME_SENSOR_FIXTURE_ONLY') && SP_GAME_SENSOR_FIXTURE_ONLY) return;

researchCase('sensors: точные границы score, большие группы, без циклов по кораблям',static function():void{
    foreach([-1,0,3,4,7,8]as$score)researchAssert(Sensor::score(3,3,1,1<<max(0,$score),false)===max(0,$score),'Граница логарифма.');
    researchAssert(Sensor::score(3,4,1,2,false)===-1,'Отрицательная граница.');
    researchAssert(Sensor::score(3,3,1,100,true)===8&&Sensor::score(3,3,1,100,false)===6,'Движение не добавило 2.');
    researchAssert(Sensor::score(3,3,PHP_INT_MAX,PHP_INT_MAX,false)===124,'Integer log2 потерял точность.');
    defendRejected(static fn()=>Sensor::score(3,3,0,1,false));
});
researchCase('sensors: явный режим, ACK без скана/расхода, snapshot tiers, приватность',static function():void{
    [$owner,$source]=sensorFixture();
    researchAssert(Sensor::tick(1000)===[]&&Sensor::contacts(1,1000)===[],'Обычный OBSERVE уже сканирует.');
    // Контракт ACK на настоящем runtime-времени проверяется отдельно от искусственных часов fixture.
    Sensor::setMode($source,$owner,true,1000);
    researchAssert(Sensor::tick(1010)===[1],'Нет affected account для ActualData.');
    $c=Sensor::contacts(1,1010)[2];
    researchAssert($c['tier']===2&&$c['owner_name']==='Player2'&&$c['countRange']===[100,999],'Ошибочный tier/диапазон.');
    foreach(['ships','route','resources','fuel','commands','mission','sourceId','viewerId','score']as$key)researchAssert(!isset($c[$key]),'Утечка '.$key);
    researchAssert(Fleets::getById(1)['fuel_current']===100.0,'Скан отдельно расходует топливо.');
    Techs::update(['id'=>1,'sensor_tech'=>4]);Sensor::tick(1020);
    researchAssert(Sensor::contacts(1,1020)[2]['ships'][0]['count']===100,'Tier3 не раскрыл состав.');
    Techs::update(['id'=>2,'spy_tech'=>6]);Sensor::tick(1030);
    $low=Sensor::contacts(1,1030)[2];researchAssert($low['tier']===1&&!isset($low['owner_name'],$low['countRange'],$low['ships']),'Tier1 раскрыл лишнее.');
});
researchCase('sensors: immutable точка, interval/TTL/последняя отметка, restart',static function():void{
    [$owner,$source]=sensorFixture();Sensor::setMode($source,$owner,true,1000);Sensor::tick(1000);
    $first=Sensor::contacts(1,1000)[2];
    Fleets::update(['id'=>2,'current_angle'=>99.0]);
    Sensor::tick(1001);researchAssert(Sensor::contacts(1,1001)[2]['position']===$first['position'],'Запрос подменил snapshot живой точкой.');
    Sensor::setMode($source,$owner,false,1002);
    researchAssert(!Sensor::contacts(1,1002)[2]['fresh'],'Выключенный источник продлил живой контакт.');
    Sensor::setMode($source,$owner,true,1003);
    researchAssert(!Sensor::contacts(1,1003)[2]['fresh'],'Пере-включение оживило старый snapshot.');
    Sensor::tick(1010);researchAssert(Sensor::contacts(1,1010)[2]['position']['angle']===99.0,'Новый скан не обновил позицию.');
    researchAssert(!Sensor::contacts(1,1040)[2]['fresh']&&Sensor::contacts(1,1310)===[],'TTL нарушен.');
    SensorContacts::put(1,[]);researchAssert(Sensor::contacts(1,1020)===[],'После очистки/restart контакт восстановлен.');
});
researchCase('sensors: скрытые фазы, потеря источника, нулевое топливо, запрет live ID',static function():void{
    [$owner,$source]=sensorFixture();Sensor::setMode($source,$owner,true,1000);Sensor::tick(1000);
    defendRejected(static fn()=>FleetRendezvousService::target(1,2));
    $targets=FleetRendezvousService::targets(1,1000);$target=array_values(array_filter($targets,fn($c)=>$c['id']===2))[0];
    researchAssert($target['selectable']===true&&$target['interceptable']===true&&$target['delivery']===false,
        'Контакт разрешает только перехват по снимку, не live-доставку.');
    $cmd=FleetCommands::getActiveCommand(2);
    foreach([11,12,40]as$type){SensorControl::patchCommand($cmd['id'],['command_type'=>$type]);researchAssert(!Sensor::contacts(1,1001)[2]['fresh'],'Скрытая фаза доступна.');}
    SensorControl::patchCommand($cmd['id'],['command_type'=>43]);
    Fleets::update(['id'=>1,'fuel_current'=>0]);researchAssert(!Sensor::contacts(1,1001)[2]['fresh'],'Нулевое топливо сканирует.');
    Fleets::update(['id'=>1,'fuel_current'=>100]);Fleets::delete(['id'=>1]);
    researchAssert(!Sensor::contacts(1,1001)[2]['fresh'],'Потерянный источник доступен через cache.');
});
researchCase('sensors: set mode проверяет владение, типы, eligibility и replay',static function():void{
    [$owner,$source,$target]=sensorFixture();
    defendRejected(static fn()=>Sensor::validateMode($source,$owner,'true',1000));
    defendRejected(static fn()=>Sensor::setMode($target,$owner,true,1000));
    Sensor::setMode($source,$owner,true,1000);$command=FleetCommands::getActiveCommand(1);
    Sensor::setMode($source,$owner,true,1001);researchAssert(FleetCommands::getActiveCommand(1)===$command,'Replay поменял поколение.');
    SensorControl::patchCommand($command['id'],['execute_time'=>0.0]);
    defendRejected(static fn()=>Sensor::setMode($source,$owner,true,1001));
});
researchCase('sensors: настоящий MOVE добавляет 2, остановка и скрытые фазы оставляют только отметку',static function():void{
    [$owner,$source]=sensorFixture();Sensor::setMode($source,$owner,true,1000);
    $cmd=FleetCommands::getActiveCommand(2);
    SensorControl::patchCommand($cmd['id'],['command_type'=>10,'execute_time'=>1200.0,'params'=>json_encode([
        'target'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>3000,'angle'=>20],
        'motionStart'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>2000,'angle'=>20],
        'motionEnd'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>3000,'angle'=>20],'speedFactor'=>10])]);
    Sensor::tick(1010);$moving=Sensor::contacts(1,1010)[2];
    researchAssert($moving['tier']===3&&$moving['ships'][0]['count']===100,'Реальный MOVE не повысил tier.');
    researchAssert(abs($moving['course']-20)<.000001,'Курс не совпал с направлением прямой.');
    researchAssert(isset($moving['route'])&&$moving['route']['endsAt']===1200.0
        &&$moving['route']['motionEnd']['distance']===3000.0,'Tier3 не раскрыл текущий участок.');
    foreach(['target','segment_start','segment_end','start_time','end_time','motionStart','motionEnd']as$key) {
        researchAssert(!array_key_exists($key,$moving),'Участок раскрыл лишние корневые поля: '.$key);
    }
    SensorControl::patchCommand($cmd['id'],['command_type'=>43]);Sensor::tick(1020);
    researchAssert(Sensor::contacts(1,1020)[2]['tier']===2&&!isset(Sensor::contacts(1,1020)[2]['course'])
        &&!isset(Sensor::contacts(1,1020)[2]['route']),'Стоянка сохранила бонус/курс/участок движения.');
    foreach([11,12,40]as$i=>$type){
        SensorControl::patchCommand($cmd['id'],['command_type'=>$type]);Sensor::tick(1030+$i*10);
        $hidden=Sensor::contacts(1,1030+$i*10)[2];
        researchAssert(!$hidden['fresh']&&$hidden['observedAt']===1020.0,'Скан раскрыл скрытую фазу.');
    }
    Fleets::update(['id'=>2,'status'=>0]);Sensor::tick(1060);
    researchAssert(!Sensor::contacts(1,1060)[2]['fresh'],'Базирующийся флот обнаружен.');
});
researchCase('sensors B004: ожидающий, paused и завершённый MOVE не повышают заметность',static function():void{
    foreach([
        'pending'=>[0.0,null,0], 'manual'=>[0.0,'manual',0], 'fuel'=>[0.0,'fuel',0],
        'activePaused'=>[1200.0,'manual',0], 'due'=>[1010.0,null,0],
        'expired'=>[1009.0,null,0], 'executed'=>[1200.0,null,1],
    ]as$kind=>[$until,$pause,$executed]){
        [$owner,$source]=sensorFixture();Sensor::setMode($source,$owner,true,1000);
        $cmd=FleetCommands::getActiveCommand(2);
        $params=['target'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>3000,'angle'=>20],
            'motionStart'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>2000,'angle'=>20],
            'motionEnd'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>3000,'angle'=>20]];
        if($pause!==null)$params['pause']=$pause;
        SensorControl::patchCommand($cmd['id'],['command_type'=>10,'execute_time'=>$until,
            'executed'=>$executed,'params'=>json_encode($params)]);
        Sensor::tick(1010);$contact=Sensor::contacts(1,1010)[2];
        researchAssert($contact['tier']===2&&!isset($contact['ships'])&&!isset($contact['route'])
            &&!isset($contact['course']),$kind.': неподвижная цель получила бонус/состав/движение.');
    }
});
researchCase('sensors: курс — азимут скорости, immutable snapshot; пауза и конец MOVE без курса',static function():void{
    foreach([[0,0,1000,0,0],[0,0,1000,90,90],[0,0,1000,180,180],[0,0,1000,270,270],[1000,0,1000,90,135]]as[$r1,$a1,$r2,$a2,$expected]){
        [$owner,$source]=sensorFixture();Sensor::setMode($source,$owner,true,1000);
        $cmd=FleetCommands::getActiveCommand(2);
        $params=['target'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>$r2,'angle'=>$a2],
            'motionStart'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>$r1,'angle'=>$a1],
            'motionEnd'=>['type'=>2,'galaxy'=>1,'system'=>1,'distance'=>$r2,'angle'=>$a2]];
        SensorControl::patchCommand($cmd['id'],['command_type'=>10,'execute_time'=>1200.0,'params'=>json_encode($params)]);
        Sensor::tick(1010);$old=Sensor::contacts(1,1010)[2];
        researchAssert(abs($old['course']-$expected)<.000001,'Азимут скорости перепутан с полярным углом.');
        $params['motionEnd']['angle']=45;
        SensorControl::patchCommand($cmd['id'],['params'=>json_encode($params)]);
        Sensor::tick(1011);$same=Sensor::contacts(1,1011)[2];
        researchAssert($same['course']===$old['course']&&$same['position']===$old['position'],'Чтение оживило курс/позицию старого снимка.');
        $params['pause']='fuel';SensorControl::patchCommand($cmd['id'],['execute_time'=>0.0,'params'=>json_encode($params)]);
        Sensor::tick(1020);researchAssert(!isset(Sensor::contacts(1,1020)[2]['course']),'Ожидающая/остановленная команда имеет курс.');
        unset($params['pause']);SensorControl::patchCommand($cmd['id'],['execute_time'=>1025.0,'params'=>json_encode($params)]);
        Sensor::tick(1030);researchAssert(!isset(Sensor::contacts(1,1030)[2]['course']),'Истёкший MOVE имеет курс.');
    }
});

class SensorFailTable extends Swoole\Table
{
    public bool $fail=false;
    public function set(string $key,array $value):bool
    {
        if($this->fail){$this->fail=false;return false;}
        return parent::set($key,$value);
    }
}
researchCase('sensors: native set failure, старый snapshot сохранён, retry и overflow',static function():void{
    [$owner,$source]=sensorFixture();Sensor::setMode($source,$owner,true,1000);Sensor::tick(1000);
    $old=SensorContacts::get(1);
    $table=new SensorFailTable(16);$table->column('expires_at',Swoole\Table::TYPE_FLOAT);$table->column('payload',Swoole\Table::TYPE_STRING,SensorContacts::MAX_BYTES);$table->create();
    (new ReflectionProperty(SensorContacts::class,'table'))->setValue(null,$table);SensorContacts::put(1,$old);
    $table->fail=true;researchAssert(Sensor::tick(1010)===[]&&SensorContacts::get(1)===$old,'Отказ записи уничтожил старую отметку.');
    researchAssert(Sensor::tick(1020)===[1]&&SensorContacts::get(1)[2]['observedAt']===1020.0,'Retry не восстановился.');
    $old=SensorContacts::get(1);$large=$old;$large[2]['owner_name']=bin2hex(random_bytes(30000));
    defendRejected(static fn()=>SensorContacts::put(1,$large));researchAssert(SensorContacts::get(1)===$old,'Переполнение обрезало старый снимок.');
});
researchCase('sensors: ACK → PlayerQueue → настоящий EventLoop, отказ command set и rollback',static function():void{
    [$owner,$source]=sensorFixture();$now=microtime(true);$command=FleetCommands::getActiveCommand(1);
    SensorControl::patchCommand($command['id'],['execute_time'=>$now+1000,'params'=>json_encode(['hold'=>['rate'=>.001,'startedAt'=>$now,'lastAt'=>$now]])]);
    $message=new Message(['mode'=>'fleets','action'=>'SetFleetSensors','data'=>['fleetId'=>1,'enabled'=>true]]);$response=new Message();
    researchAssert(PageActionService::handle($message,$owner,1,$response)&&$response->source()['status']==='ok','Нет ACK режима.');
    researchAssert(!Sensor::state($source)['enabled']&&SensorContacts::get(1)===[],'ACK уже применил режим.');
    $event=SPGame\Game\Repositories\PlayerQueue::getByAccount(1)[0];$loop=new SPGame\Game\Services\EventLoop();
    researchAssert((new ReflectionMethod($loop,'processPlayerEvent'))->invoke($loop,$event,1)===true&&Sensor::state(new Fleet(1))['enabled'],'EventLoop не включил сенсоры.');
    $schema=(new ReflectionProperty(FleetCommands::class,'tableSchema'))->getValue();$table=new SensorFailTable(128);
    foreach($schema['columns']as$name=>$col)$table->column($name,$col['swoole'][0],$col['swoole'][1]??8);
    $table->create();foreach(FleetCommands::getAll()as$row)$table->set((string)$row['id'],$row);
    (new ReflectionProperty(FleetCommands::class,'table'))->setValue(null,$table);$before=FleetCommands::getActiveCommand(1);$table->fail=true;
    try{Sensor::setMode(new Fleet(1),$owner,false,$now+1);throw new RuntimeException('Не инъецирован отказ.');}catch(RuntimeException $e){researchAssert($e->getMessage()!=='Не инъецирован отказ.','Не инъецирован отказ.');}
    researchAssert(FleetCommands::getActiveCommand(1)===$before,'Rollback не восстановил режим.');
    Sensor::setMode(new Fleet(1),$owner,false,$now+2);researchAssert(!Sensor::state(new Fleet(1),$now+2)['enabled'],'Retry режима не сработал.');
});
researchCase('sensors: несколько источников не складывают силу, потеря сильного понижает tier',static function():void{
    [$owner,$source]=sensorFixture();Sensor::setMode($source,$owner,true,1000);
    Fleets::update(['id'=>3,'owner_id'=>1,'status'=>1,'mission'=>30,'current_coordinate_type'=>2,'current_distance'=>1200,'current_angle'=>40,'fuel_current'=>100]);
    FleetsShips::update(['id'=>3,'sensor_scout_count'=>4]);
    $row=SensorControl::add(3,SensorCommand::OBSERVE,['hold'=>['rate'=>.001,'startedAt'=>1000,'lastAt'=>1000]]);
    SensorControl::patchCommand($row['id'],['execute_time'=>100000.0]);Sensor::setMode(new Fleet(3),$owner,true,1000);
    Sensor::tick(1000);researchAssert(Sensor::contacts(1,1000)[2]['tier']===3,'Не выбран сильный источник.');
    Sensor::setMode(new Fleet(3),$owner,false,1001);researchAssert(!Sensor::contacts(1,1001)[2]['fresh'],'Сильный источник продолжает работу.');
    Sensor::tick(1010);researchAssert(Sensor::contacts(1,1010)[2]['fresh']&&Sensor::contacts(1,1010)[2]['tier']===2,'Потеря источника не понизила сведения.');
});

researchCase('sensors: сканы, чтение freshness и GC не удаляют источник или цели',static function():void{
    [$owner,$source]=sensorFixture();
    $registry=FleetsData::getInstance();$managedSource=$registry->get(1);$managedTarget=$registry->get(2);
    Sensor::setMode($source,$owner,true,1000);
    $rows=Fleets::getAll();$ships=FleetsShips::getAll();$commands=FleetCommands::getAll();
    for($i=0;$i<30;$i++){
        Sensor::tick(1010+$i);$contacts=Sensor::contacts(1,1010+$i);
        researchAssert(isset($contacts[2])&&$contacts[2]['fresh'],'Пропал сенсорный контакт.');
        gc_collect_cycles();
        researchAssert(Fleets::getAll()===$rows&&FleetsShips::getAll()===$ships&&FleetCommands::getAll()===$commands,
            'Read-only scan/freshness + GC изменили игровые Tables.');
        researchAssert($registry->get(1)===$managedSource&&$registry->get(2)===$managedTarget,'GC удалил registry Fleet.');
        researchAssert(Fleets::getPendingSync()['delete']===[]&&FleetsShips::getPendingSync()['delete']===[],
            'Read-only сенсоры выставили DELETE marker.');
    }
});
researchCase('sensors: освобождение временного Fleet не меняет runtime и не подменяет исходное исключение',static function():void{
    sensorFixture();$managed=FleetsData::getInstance()->get(1);
    $before=Fleets::getAll();$ships=FleetsShips::getAll();
    $copy=new Fleet(1);$weak=WeakReference::create($copy);unset($copy);gc_collect_cycles();
    researchAssert($weak->get()===null,'Временный Fleet не освобождён GC.');
    researchAssert(Fleets::getAll()===$before&&FleetsShips::getAll()===$ships,'Освобождение копии удалило Fleet/Ships.');
    try{new Fleet(999999);throw new RuntimeException('Нет исключения отсутствующего Fleet.');}
    catch(Throwable $e){researchAssert($e->getMessage()!=='Нет исключения отсутствующего Fleet.'
        &&!str_contains($e->getMessage(),'must not be accessed before initialization'),'Destructor подменил ошибку конструктора.');}
    researchAssert(FleetsData::getInstance()->get(1)===$managed&&Fleets::getAll()===$before,'Ошибка чтения повредила registry.');
});
researchCase('sensors: явный destroy сохранён; GC старой копии не удаляет восстановленный ID',static function():void{
    sensorFixture();$registry=FleetsData::getInstance();$managed=$registry->get(1);$oldCopy=new Fleet(1);
    $row=Fleets::getById(1);$ships=FleetsShips::findExistingById(1);
    $managed->destroy();
    researchAssert(Fleets::getById(1)===null&&FleetsShips::findExistingById(1)===null&&$registry->get(1)===null,'Явное удаление не работает.');
    researchAssert(in_array(1,Fleets::getPendingSync()['delete'],true)&&in_array(1,FleetsShips::getPendingSync()['delete'],true),
        'Явное удаление не выставило markers.');
    Fleets::restoreDeletedRow($row);FleetsShips::restoreDeletedRow($ships);$restored=$registry->get(1);
    unset($managed,$oldCopy);gc_collect_cycles();
    researchAssert($registry->get(1)===$restored&&Fleets::getById(1)===$row&&FleetsShips::findExistingById(1)===$ships,
        'Старая копия удалила новую сущность с тем же ID.');
    $registry->remove(1,false);unset($restored);gc_collect_cycles();
    researchAssert(Fleets::getById(1)===$row&&FleetsShips::findExistingById(1)===$ships,'Eviction из registry удалил repositories.');
});

researchCase('sensors: ограниченный native буфер на 1000 наблюдателей, по 100 контактов',static function():void{
    SensorContacts::init();
    $contacts=[];
    for($i=1;$i<=100;$i++)$contacts[$i]=['id'=>$i,'viewerId'=>1,'sourceId'=>1,'sourceCommandId'=>1,'sourceVersion'=>1,
        'targetCommandId'=>$i,'tier'=>3,'fresh'=>true,'course'=>(float)(($i*3)%360),'position'=>['type'=>2,'galaxy'=>1,'system'=>8,'distance'=>$i*100,'angle'=>$i],
        'observedAt'=>1000.0,'freshUntil'=>1030.0,'retainUntil'=>1300.0,'owner_name'=>'QA-'.$i,
        'ships'=>[['id'=>202,'count'=>1000000],['id'=>221,'count'=>1]],
        '_observation'=>['id'=>$i,'observedAt'=>1000.0,'position'=>['type'=>2,'galaxy'=>1,'system'=>8,'distance'=>$i*100.0,'angle'=>(float)$i],
            'signature'=>hash('sha256','trajectory-'.$i),'validUntil'=>null,
            'motion'=>['kind'=>'course','vx'=>$i*.01,'vy'=>$i*.02,'radiusLimit'=>20000.0]]];
    $start=microtime(true);
    for($i=1;$i<=1000;$i++)SensorContacts::put($i,$contacts);
    for($i=1;$i<=1000;$i++)researchAssert(SensorContacts::get($i)===$contacts,'Повреждён снимок буфера.');
    $table=(new ReflectionProperty(SensorContacts::class,'table'))->getValue();
    echo 'BUFFER '.json_encode(['viewers'=>count(SensorContacts::viewers()),'contactsPerViewer'=>100,
        'seconds'=>round(microtime(true)-$start,3),'nativeBytes'=>method_exists($table,'getMemorySize')?$table->getMemorySize():null,
        'snapshotBytes'=>strlen($table->get('1')['payload'])]),"\n";
});

echo "RESULT sensors: $passed passed / $failed failed; SQL calls=".\SPGame\Core\Database::$calls."\n";
restore_error_handler();
exit($failed>0||\SPGame\Core\Database::$calls>0?1:0);
