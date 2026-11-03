<?php

declare(strict_types=1);

/** Изолированные native Tables + TEMPORARY MySQL, игровые данные не используются.
 * php tests/FleetSpecialMissionsTest.php --export-special |
 * docker exec -i -w /var/www/html swoole_app php tests/FleetFreeSpaceSwooleTest.php
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/BattleReportsMemory.php';

use SPGame\Game\Repositories\{BaseRepository, FleetCommands, Fleets, FleetsShips, Galaxy, GalaxyOrbits, Techs, Vars};
use SPGame\Game\Services\{AccountData, FleetCommandsService as Commands, FleetControlService as Control,
    FleetRendezvousService as Rendezvous, FleetsData};
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Space\Coordinate;
use Swoole\Table;

if (!extension_loaded('swoole')) exit(77);
$fixture = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach ($fixture['vars'] as $key => $value) (new ReflectionProperty(Vars::class, $key))->setValue(null, $value);
class_exists(\SPGame\Game\Repositories\Resources::class);
(new ReflectionProperty(BaseRepository::class, 'logger'))->setValue(null, \SPGame\Core\Logger::getInstance());
foreach ($fixture['repositories'] as $class => $data) {
    $reflection = new ReflectionClass($class); $table = new Table(256); $schema = $data['schema'];
    foreach ($schema['columns'] as $name => &$column) {
        $column['swoole'][0] = match ($column['nativeKind']) { 'int' => Table::TYPE_INT, 'float' => Table::TYPE_FLOAT, default => Table::TYPE_STRING };
        unset($column['nativeKind']);
        $table->column($name, $column['swoole'][0], $column['swoole'][1] ?? 8);
    }
    unset($column); $table->create();
    foreach ($data['rows'] as $key => $row) if (!$table->set((string)$key, $row)) throw new RuntimeException('Fixture Table переполнена.');
    $reflection->getProperty('tableSchema')->setValue(null, $schema); $reflection->getProperty('table')->setValue(null, $table);
    $sync = new Table(512);
    foreach (['need_update','need_delete','last_id'] as $name) $sync->column($name,Table::TYPE_INT,8);
    $sync->create(); foreach ($data['sync'] as $key => $row) $sync->set((string)$key,$row);
    $reflection->getProperty('syncTable')->setValue(null,$sync);
    $indexes = [];
    foreach ($data['indexes'] as $name => $rows) {
        $index = new Table(256); $index->column('id',Table::TYPE_INT,8); $index->column('ids',Table::TYPE_STRING,16384); $index->create();
        foreach ($rows as $key => $row) $index->set((string)$key,$row);
        $indexes[$name]=$index;
    }
    $reflection->getProperty('indexTables')->setValue(null,$indexes);
}
function nativeControlAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

Vars::$attributes[204]['missions'][] = Mission::TRANSPORT->value;
Techs::update(['id'=>100,'computer_tech'=>5,'fleet_logistics_tech'=>1]);
$owner=new AccountData(1000);
// Fixture Techs может не содержать новых колонок: задаём только контекст изолированного аккаунта.
$owner['Techs']['computer_tech']=5; $owner['Techs']['fleet_logistics_tech']=1;
Galaxy::add(Galaxy::castRowToSchema(['id'=>1,'galaxy'=>1,'system'=>10,'max_orbits'=>8,'min_distance'=>1000,'max_distance'=>1600],true));
GalaxyOrbits::add(GalaxyOrbits::castRowToSchema(['id'=>50,'galaxy'=>1,'system'=>10,'orbit'=>9,'distance'=>2100,'type'=>GalaxyOrbits::SYSTEM_EDGE],true));
$source=FleetsData::getInstance()->get(1);
$plan=['version'=>Control::state($source)['version'],'fuel'=>500,'steps'=>[
    ['kind'=>'move','coordinates'=>Coordinate::point(1,10,1300,45)->toArray()],['kind'=>'hold']]];
Control::savePlan($source,$owner,$plan,100);
Commands::completeCommand(FleetCommands::getActiveCommand(1),$source,$owner);
nativeControlAssert(Control::state($source)['holding'],'Native OBSERVE не активирован.');
$row=Fleets::getById(1); $row['id']=2; $row['mission']=0; $row['status']=0;
$row['fuel_total']=$row['fuel_current']=0;
Fleets::add($row); $ships=FleetsShips::findExistingById(1); $ships['id']=$ships['fleet_id']=2; FleetsShips::add($ships);
$sender=FleetsData::getInstance()->get(2);
$receiverTime=$source->getCurrentPositionTime();
$sender->setCurrentCoordinate(Coordinate::point(1,10,1400,70),$receiverTime); $sender->save();
// Редактор idle требует фактическую базу: запускаем штатную транспортную миссию от базы fixture.
$sender->setCurrentCoordinate($source->getStartCoordinate(),$receiverTime); $sender->save();
nativeControlAssert(array_column(Rendezvous::targets(100,$receiverTime),'id')===[1],
    'Флот на базе не должен попадать в native список целей.');
try { Rendezvous::target(100,2,1); throw new RuntimeException('Native target принял флот на базе.'); }
catch (InvalidArgumentException) {}
$target=\SPGame\Game\Fleet\FleetTarget::fromCoordinates(['type'=>3,'fleetId'=>1]);
$travel=$sender->getTravelInfo($target,$owner,10,Mission::TRANSPORT);
Commands::startMission($sender,$owner,Mission::TRANSPORT->value,['type'=>3,'fleetId'=>1],10,ceil($travel['fuel']['max']),[903=>25],$receiverTime,null,null,true);
nativeControlAssert(array_column(Rendezvous::targets(100,$receiverTime),'id')===[1,2],
    'Летящий флот должен быть доступен после старта с базы.');
$before=$source->getFuelCurrent(); $move=FleetCommands::getActiveCommand(2);
$elapsed=(float)$move['execute_time']-$receiverTime; $rate=Control::state($source)['holdRate'];
Commands::completeCommand($move,$sender,$owner);
nativeControlAssert(abs($source->getFuelCurrent()-($before-$rate*$elapsed+25))<1e-6,'Native передача/settle удержания неверны.');
nativeControlAssert(Control::state($source)['holding'],'Заправка изменила режим получателя.');
$active=FleetCommands::getActiveCommand(2);
Control::stop($sender,$owner,($sender->getCurrentPositionTime()+(float)$active['execute_time'])/2);
nativeControlAssert(Control::state($sender)['holding'],'Native добровольная остановка не стала удержанием.');
// Сохраняем активное удержание, паузу и POINT через реальный saver; затем читаем SQL.
Commands::completeCommand(FleetCommands::getActiveCommand(1),$source,$owner);
nativeControlAssert(Control::state($source)['reason']==='fuel','Native исчерпание не приостановило флот.');
\SPGame\Core\Environment::init(); $db=\SPGame\Core\Database::getInstance();
foreach ([Fleets::class,FleetCommands::class] as $class) {
    $reflection=new ReflectionClass($class); $property=$reflection->getProperty('tableName'); $original=$property->getValue();
    $name='qa_fleet_control_'.bin2hex(random_bytes(6)); $columns=[];
    foreach($reflection->getProperty('tableSchema')->getValue()['columns'] as $key=>$column) {
        $sql=$column['sql']??match($column['swoole'][0]){Table::TYPE_INT=>'BIGINT NOT NULL DEFAULT 0',Table::TYPE_FLOAT=>'DOUBLE NOT NULL DEFAULT 0',default=>"TEXT"};
        $columns[]='`'.$key.'` '.$sql;
    }
    $db->query('CREATE TEMPORARY TABLE `'.$name.'` ('.implode(',',$columns).',PRIMARY KEY(id))');
    try {
        $property->setValue(null,$name); $class::syncToDatabase();
        foreach($class::getAll() as $expected) {
            $actual=$db->fetchOne('SELECT * FROM `'.$name.'` WHERE id=?',[$expected['id']]);
            nativeControlAssert($actual!==null,'Строка не сохранена в MySQL.');
            foreach($expected as $key=>$value) {
                nativeControlAssert(is_float($value)?abs((float)$actual[$key]-$value)<(abs($value)>1e8?.0001:.000001):$actual[$key]==$value,
                    'MySQL отличается: '.$key.' expected='.json_encode($value).' actual='.json_encode($actual[$key]));
            }
            $reflection->getProperty('table')->getValue()->set((string)$expected['id'], $class::castRowToSchema($actual,true));
        }
        $pending=$class::getPendingSync();
        nativeControlAssert(($pending['update']??[])===[],'Успешный saver не очистил markers.');
        echo 'OK native MySQL roundtrip '.basename(str_replace('\\','/',$class))."\n";
    } finally { $property->setValue(null,$original); $db->query('DROP TEMPORARY TABLE IF EXISTS `'.$name.'`'); }
}
// Runtime cache reload, без implicit resume после persistence.
$source->reloadFromRuntime(); $sender->reloadFromRuntime();
nativeControlAssert(Control::state($source)['paused']&&Control::state($sender)['holding'],'Reload изменил режим Fleet.');
nativeControlAssert(Rendezvous::position($source,$receiverTime)->getType()->value===2,'Позиция не POINT.');
echo "RESULT native hold/resupply/manual stop/exhaustion/persistence passed\n";
