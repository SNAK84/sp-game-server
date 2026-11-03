<?php
declare(strict_types=1);

// Только новые QA-флоты основного dev; исходный Fleet86/его маршрут не меняем.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\RepositoryIdRanges;
Environment::init(__DIR__ . '/../.env');
if (($argv[1] ?? '') !== '--prepare') throw new LogicException('Нужен --prepare при остановленном main.');
$socket = @stream_socket_client('tcp://127.0.0.1:9501', $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите основной сервер.'); }
$db = Database::getInstance();
if (($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') !== 'qaown_260924'
    || ($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login'] ?? '') !== 'qafor_260924') {
    throw new LogicException('Другой QA-контекст.');
}
if ($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'") !== null
    || $db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") !== null
    || $db->fetchOne('SELECT id FROM resource_bases WHERE id=1') !== null
    || $db->fetchOne('SELECT id FROM fleets WHERE name LIKE ?', ['QA B030 %']) !== null) {
    throw new LogicException('Сцена уже занята или есть незавершённые операции.');
}
$site = $db->fetchOne('SELECT id,galaxy,system,orbit,site_type FROM orbital_resource_sites WHERE id=1');
$fuel = $db->fetchOne('SELECT * FROM fleets WHERE id=86');
$idle = $db->fetchOne('SELECT * FROM fleets WHERE id=83');
$enemy = $db->fetchOne('SELECT * FROM fleets WHERE id=79');
$original = $db->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id=86 ORDER BY id');
$future = array_values(array_filter($original, static fn(array $c): bool => (int)$c['executed'] === 0));
$first = json_decode($future[0]['params'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
if ((int)($site['site_type'] ?? 0) !== 2 || (int)($site['galaxy'] ?? 0) !== 1 || (int)($site['system'] ?? 0) !== 10
    || (int)($fuel['owner_id'] ?? 0) !== 9 || (int)($idle['owner_id'] ?? 0) !== 9 || (int)($enemy['owner_id'] ?? 0) !== 10
    || count($future) !== 5 || (int)$future[0]['command_type'] !== 10 || ($first['pause'] ?? null) !== 'fuel'
    || (int)$enemy['status'] !== 0) throw new LogicException('Изменились исходные QA-шаблоны.');
$fleetIds = new RepositoryIdRanges('fleets', (int)$db->fetchOne('SELECT MAX(id) n FROM fleets')['n']);
$commandIds = new RepositoryIdRanges('fleet_commands', (int)$db->fetchOne('SELECT MAX(id) n FROM fleet_commands')['n']);
$insert = static function(string $table, array $row) use ($db): void {
    $db->query('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES ('
        . implode(',', array_fill(0,count($row),'?')) . ')', array_values($row));
};
$now = (float)sprintf('%.6F',microtime(true));$result=[];
$db->beginTransaction();
try {
    $insert('resource_bases',['id'=>1,'owner_id'=>9,'created_at'=>$now,'updated_at'=>$now]);
    foreach (['guard','fuel','manual','attacker'] as $role) {
        $id=$fleetIds->next();$row=$role==='attacker'?$enemy:($role==='guard'?$idle:$fuel);
        $row['id']=$id;$row['name']='QA B030 '.$role;$row['current_position_time']=$now;
        foreach ($row as $key=>$_) if (str_starts_with($key,'base_fuel_')) $row[$key]=0;
        if ($role!=='attacker') {
            $row['current_coordinate_type']=1;$row['current_galaxy']=1;$row['current_system']=10;
            $row['current_orbit']=(int)$site['orbit'];$row['current_object_type']=5;$row['current_site_id']=1;
            $row['current_distance']=0;$row['current_angle']=0;
            $row['fuel_current']=$role==='fuel'?2.197604725758235:500.0;$row['fuel_total']=500.0;
            $row['resource_metal']=$role==='guard'?0.0:333.0;$row['resource_crystal']=$role==='guard'?0.0:111.0;
            if ($role==='guard') {$row['home_planet_id']=0;$row['home_site_id']=1;$row['fallback_planet_id']=26;}
        }
        $insert('fleets',$row);
        $ships=$db->fetchOne('SELECT * FROM fleets_ships WHERE id=?',[$role==='attacker'?79:86]);
        $ships['id']=$id;$ships['fleet_id']=$id;$ships['owner_id']=$row['owner_id'];
        if (in_array($role,['guard','attacker'],true)) {
            foreach ($ships as $key=>$_) {
                if (str_ends_with($key,'_count') || str_ends_with($key,'_damage') || str_ends_with($key,'_experience')) $ships[$key]=0;
            }
            $ships['big_ship_cargo_count']=$role==='attacker'?4:1;
        }
        $insert('fleets_ships',$ships);
        if (in_array($role,['fuel','manual'],true)) foreach ($future as $index=>$command) {
            $command['id']=$commandIds->next();$command['fleet_id']=$id;$command['create_time']=$now;
            if ($index===0 && $role==='manual') {$p=json_decode($command['params'],true,512,JSON_THROW_ON_ERROR);$p['pause']='manual';$command['params']=json_encode($p,JSON_THROW_ON_ERROR);}
            $insert('fleet_commands',$command);
        }
        $result[$role]=$id;
    }
    $db->commit();
} catch (Throwable $e) {$db->rollBack();throw $e;}
echo json_encode(['siteId'=>1,'fleets'=>$result,'original86Hash'=>hash('sha256',json_encode([$fuel,$original]))]),PHP_EOL;
