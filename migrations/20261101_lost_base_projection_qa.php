<?php
declare(strict_types=1);

// B015: точный QA-checkpoint основного dev; подготовка только при остановленном main.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\FleetCommandType as C;
use SPGame\Game\Services\FleetMissionBuilder;
use SPGame\Game\Space\{Coordinate, ObjectType};

Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--inspect', '--prepare', '--rearm', '--rearm-implicit'], true)) throw new LogicException('Нужен режим.');
$guard = static function (bool $ok): void { if (!$ok) throw new LogicException('Другая QA-сцена B015.'); };
$guard(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924');
$planet = $db->fetchOne('SELECT * FROM planets WHERE id=26');
$site = $db->fetchOne('SELECT * FROM orbital_resource_sites WHERE id=19');
$guard((int)$planet['owner_id'] === 9 && [(int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet']] === [1,6,2]);
$guard($site !== null && [(int)$site['galaxy'], (int)$site['system'], (int)$site['orbit'], (int)$site['site_type']] === [1,7,3,2]);
$rows = $db->fetchAll("SELECT * FROM fleets WHERE name IN ('QA B015 implicit','QA B015 explicit') ORDER BY id");
if ($mode !== '--inspect') {
    $socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT',9501), $errno, $error, .5);
    if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите main.'); }
    $guard($db->fetchOne('SELECT id FROM resource_bases WHERE id=19') === null);
    $now = (float)sprintf('%.6F', microtime(true));
    $end = $now + ($mode === '--prepare' ? 600 : 120);
    $radius = (float)$db->fetchOne('SELECT distance FROM galaxy_orbits WHERE galaxy=1 AND system=6 AND orbit=2')['distance'];
    $factor = (float)$db->fetchOne("SELECT value FROM config WHERE name='SpeedPlanets'")['value'];
    $point = static function (float $at) use ($planet, $radius, $factor): array {
        $angle = fmod((float)$planet['deg'] + max(0, $at - (float)$planet['update_time']) / 3600
            * (float)$planet['speed'] * $factor / 24 * ((int)$planet['rotation'] === 0 ? -1 : 1), 360);
        return Coordinate::point(1,6,$radius, $angle < 0 ? $angle + 360 : $angle)->toArray();
    };
    $start = $point($now); $start['angle'] = fmod($start['angle'] + 355, 360);
    $target = Coordinate::object(1,6,2,ObjectType::PLANET);
    $oldHome = Coordinate::object(1,7,3,ObjectType::ASTEROID_SECTOR, siteId:19);
    $active = ['target'=>$target->toArray(), 'speedFactor'=>10, 'returnLeg'=>false,
        'motionStart'=>$start, 'motionEnd'=>$point($end), 'fuelCost'=>50, 'startedAt'=>$now];
    $insert = static function (string $table, array $row) use ($db): void {
        $columns = array_keys($row);
        $db->query('INSERT INTO `' . $table . '` (`' . implode('`,`',$columns) . '`) VALUES ('
            . implode(',',array_fill(0,count($row),'?')) . ')', array_values($row));
    };
    $db->beginTransaction();
    try {
        if ($mode === '--prepare') {
            $guard($rows === []);
            $template = $db->fetchOne('SELECT * FROM fleets WHERE id=122');
            $ships = $db->fetchOne('SELECT * FROM fleets_ships WHERE id=122');
            $guard((int)$template['owner_id'] === 9 && (int)$template['status'] === 0);
            $id = (int)$db->fetchOne('SELECT MAX(id) AS n FROM fleets')['n'];
            $commandId = (int)$db->fetchOne('SELECT MAX(id) AS n FROM fleet_commands')['n'];
            foreach (['implicit','explicit'] as $kind) {
                ++$id;
                $row = array_replace($template, ['id'=>$id, 'name'=>'QA B015 '.$kind, 'mission'=>10, 'status'=>1,
                    'home_planet_id'=>26, 'home_site_id'=>0, 'fallback_planet_id'=>26, 'is_return'=>0,
                    'fuel_total'=>1050, 'fuel_current'=>1000, 'resource_metal'=>17, 'resource_crystal'=>11,
                    'resource_deuterium'=>5, 'current_coordinate_type'=>2, 'current_galaxy'=>1, 'current_system'=>6,
                    'current_orbit'=>0, 'current_object_type'=>0, 'current_site_id'=>0,
                    'current_distance'=>$start['distance'], 'current_angle'=>$start['angle'], 'current_position_time'=>$now,
                    'start_coordinate_type'=>1, 'start_galaxy'=>1, 'start_system'=>7, 'start_orbit'=>3,
                    'start_object_type'=>5, 'start_site_id'=>19, 'target_coordinate_type'=>1, 'target_galaxy'=>1,
                    'target_system'=>6, 'target_orbit'=>2, 'target_object_type'=>1, 'target_site_id'=>0,
                    'base_fuel_time'=>0, 'base_fuel_rate'=>0, 'base_fuel_reserve'=>0, 'base_fuel_speed'=>0]);
                $insert('fleets',$row);
                $shipRow = $ships;
                foreach ($shipRow as $key => &$value) if (str_ends_with($key,'_count') || str_ends_with($key,'_experience')) $value=0;
                unset($value);
                $insert('fleets_ships',array_replace($shipRow,['id'=>$id,'fleet_id'=>$id,'small_ship_cargo_count'=>1,'small_ship_cargo_experience'=>7]));
                $definitions = [['type'=>C::MOVE_IN_SYSTEM,'params'=>$active],
                    ['type'=>C::UNLOAD,'params'=>['target'=>$target,'percent'=>100,'unloadFuel'=>false,'speedFactor'=>10]],
                    ...FleetMissionBuilder::buildReturn($target,$oldHome)];
                foreach ($definitions as $index => $definition) {
                    $params = $definition['params'];
                    if ($index >= 2) $params += ['speedFactor'=>10,'returnLeg'=>$kind==='implicit']
                        + ($kind==='implicit' ? ['homeSiteId'=>19] : []);
                    $insert('fleet_commands',['id'=>++$commandId,'fleet_id'=>$id,'command_type'=>$definition['type']->value,
                        'execute_time'=>$index===0?$end:0,'trigger_event'=>'','target_id'=>0,
                        'params'=>json_encode($params,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                        'executed'=>0,'create_time'=>$now]);
                }
            }
        } else {
            $guard(count($rows)===2);
            foreach ($rows as $row) {
                if ($mode === '--rearm-implicit' && $row['name'] !== 'QA B015 implicit') continue;
                $guard((int)$row['owner_id']===9 && (int)$row['home_planet_id']===26 && (int)$row['home_site_id']===0);
                $commands=$db->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id=? ORDER BY id',[$row['id']]);
                $guard(count($commands)===6 && array_sum(array_column($commands,'executed'))===0);
                $guard((int)$commands[0]['command_type']===10);
                $db->query('UPDATE fleets SET current_position_time=?,current_distance=?,current_angle=? WHERE id=?',[$now,$start['distance'],$start['angle'],$row['id']]);
                $db->query('UPDATE fleet_commands SET execute_time=?,params=? WHERE id=?',[$end,json_encode($active,JSON_THROW_ON_ERROR),$commands[0]['id']]);
            }
        }
        $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}
$out=[];
foreach ($db->fetchAll("SELECT id,name,status,home_planet_id,home_site_id,fuel_current FROM fleets WHERE name IN ('QA B015 implicit','QA B015 explicit') ORDER BY id") as $row) {
    $row['commands']=$db->fetchAll('SELECT id,command_type,execute_time,executed,params FROM fleet_commands WHERE fleet_id=? ORDER BY id',[$row['id']]);
    $out[]=$row;
}
echo json_encode($out,JSON_THROW_ON_ERROR),PHP_EOL;
