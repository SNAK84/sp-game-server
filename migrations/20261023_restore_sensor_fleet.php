<?php

declare(strict_types=1);

// Одноразовое восстановление B005, только snak84 / Fleet #38.
// По умолчанию read-only. --apply допустим только при остановленном WS-сервере.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\{Fleets, FleetsShips, Vars};
use SPGame\Game\Services\FleetsData;

function restoreSensorAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function restoreSensorFingerprint(Database $db): array
{
    $result = [];
    foreach (['fleets', 'fleets_ships', 'fleet_commands', 'ships', 'resources_planet', 'resources_user'] as $table) {
        $where = match ($table) {
            'fleets' => ' WHERE id<>38', 'fleets_ships' => ' WHERE fleet_id<>38', default => '',
        };
        $result[$table] = hash('sha256', json_encode($db->fetchAll("SELECT * FROM `$table`$where ORDER BY id"), JSON_THROW_ON_ERROR));
    }
    return $result;
}

if (PHP_SAPI !== 'cli') throw new RuntimeException('Разрешён только CLI.');
Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$apply = in_array('--apply', $argv, true);
$owner = $db->fetchOne('SELECT u.id,u.account_id FROM users u JOIN accounts a ON a.id=u.account_id WHERE a.login=?', ['snak84']);
restoreSensorAssert($owner !== null && (int)$owner['id'] === 1 && (int)$owner['account_id'] === 1, 'Не совпал владелец восстанавливаемого флота.');
$home = $db->fetchOne('SELECT id,owner_id,galaxy,system,planet,planet_type FROM planets WHERE id=9');
restoreSensorAssert($home !== null && array_map('intval', array_values($home)) === [9,1,1,10,7,1], 'Домашняя планета #9 изменилась.');
$commands = $db->fetchAll('SELECT * FROM fleet_commands WHERE fleet_id=38 ORDER BY id');
restoreSensorAssert(array_map('intval', array_column($commands, 'id')) === range(25,34)
    && array_map('intval', array_column($commands, 'command_type')) === [31,10,11,10,43,10,11,10,32,52], 'Сохранённый маршрут #38 изменился.');
$decode = static fn(array $row):array => json_decode((string)$row['params'], true, 512, JSON_THROW_ON_ERROR);
$load = $decode($commands[0]);$hold = $decode($commands[4]);
$point = ['type'=>2,'galaxy'=>1,'system'=>9,'distance'=>6000,'angle'=>0];
restoreSensorAssert(($hold['target'] ?? null) === $point && ($hold['sensorsEnabled'] ?? false) === true
    && (int)$commands[4]['executed'] === 0 && (float)$commands[4]['execute_time'] > microtime(true), 'Нет прежнего активного сенсорного удержания.');
restoreSensorAssert((float)($load['fuel'] ?? 0) === 2515000.0 && (int)($load['sourcePlanetId'] ?? 0) === 9
    && array_sum($load['resources'] ?? []) == 0, 'Не совпала исходная погрузка #38.');
$travelFuel = 0.0;
foreach (array_slice($commands, 1, 3) as $command) {
    restoreSensorAssert((int)$command['executed'] === 1, 'Исходящий перелёт не завершён.');
    $travelFuel += (float)$decode($command)['fuelCost'];
}
$fuel = (float)$load['fuel'] - $travelFuel;
restoreSensorAssert($fuel === 2511925.0 && abs((float)$hold['hold']['rate'] - 575/3600) < 1e-12
    && (float)$hold['hold']['lastAt'] === (float)$hold['hold']['startedAt'], 'Baseline удержания требует отдельного восстановления.');
restoreSensorAssert(abs((float)$commands[4]['execute_time'] - ((float)$hold['hold']['lastAt'] + $fuel/(float)$hold['hold']['rate'])) < .001,
    'Топливо не соответствует сохранённому времени исчерпания.');
$before = ['fleet'=>$db->fetchOne('SELECT * FROM fleets WHERE id=38'),
    'ships'=>$db->fetchOne('SELECT * FROM fleets_ships WHERE fleet_id=38'), 'commands'=>$commands];
if ($before['fleet'] !== null || $before['ships'] !== null) {
    restoreSensorAssert($before['fleet'] !== null && $before['ships'] !== null
        && (int)$before['fleet']['owner_id'] === 1 && $before['fleet']['name'] === 'Сенсор'
        && (int)$before['ships']['sensor_scout_count'] === 15 && (int)$before['ships']['big_ship_cargo_count'] === 100,
        'ID #38 занят или восстановлен частично: перезапись запрещена.');
    echo "ALREADY_RESTORED #38; записей и ресурсов не изменено\n";
    exit(0);
}
echo json_encode(['apply'=>$apply,'fleetId'=>38,'owner'=>'snak84','name'=>'Сенсор',
    'ships'=>[221=>15,203=>100],'position'=>$point,'fuelBaseline'=>$fuel,
    'fuelAtNow'=>max(0,$fuel-(float)$hold['hold']['rate']*(microtime(true)-(float)$hold['hold']['lastAt'])),
    'commandIds'=>range(25,34),'sensorsEnabled'=>true], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), "\n";
if (!$apply) exit(0);

$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT',9501), $errno, $errstr, .5);
if (is_resource($socket)) { fclose($socket); throw new RuntimeException('Сначала остановите основной WS-сервер.'); }
restoreSensorAssert(!method_exists(\SPGame\Game\Fleet\Fleet::class, '__destruct'), 'B005 ещё не исправлен.');
$fingerprints = restoreSensorFingerprint($db);
$backup = dirname(__DIR__) . '/logs/maintenance-backups/restore-sensor-38-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
if (!is_dir(dirname($backup)) && !mkdir(dirname($backup),0700,true)) throw new RuntimeException('Не создан каталог backup.');
restoreSensorAssert(file_put_contents($backup,json_encode(['before'=>$before,'unrelatedHashes'=>$fingerprints],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)!==false,
    'Не сохранён backup перед восстановлением.');
chmod($backup,0600);

// Собственные runtime Tables maintenance-процесса. Никаких INSERT вручную
// в живую БД, списаний ресурсов планеты или повторной активации LOAD.
Vars::init();Fleets::init();FleetsShips::init();
restoreSensorAssert(Vars::$resource[221] === 'sensor_scout' && Vars::$resource[203] === 'big_ship_cargo', 'Изменились идентификаторы кораблей.');
$row = Fleets::castRowToSchema(['id'=>38,'owner_id'=>1,'name'=>'Сенсор','home_planet_id'=>9,
    'start_coordinate_type'=>1,'start_galaxy'=>1,'start_system'=>10,'start_orbit'=>7,'start_object_type'=>1,
    'target_coordinate_type'=>2,'target_galaxy'=>1,'target_system'=>9,'target_distance'=>6000,'target_angle'=>0,
    'current_coordinate_type'=>2,'current_galaxy'=>1,'current_system'=>9,'current_distance'=>6000,'current_angle'=>0,
    'current_position_time'=>(float)$hold['hold']['startedAt'],'mission'=>30,'status'=>1,
    'fuel_total'=>(float)$load['fuel'],'fuel_current'=>$fuel,
    // Исходное принятие ActionFleetToOrbit; субсекундное время создания объекта не сохранилось.
    'create_time'=>1792748542.360524,'update_time'=>microtime(true)], true);
$ships = FleetsShips::castRowToSchema(['id'=>38,'fleet_id'=>38,'owner_id'=>1,
    'sensor_scout_count'=>15,'big_ship_cargo_count'=>100], true);
restoreSensorAssert(Fleets::add($row) === 38 && FleetsShips::add($ships) === 38, 'Не добавлены runtime строки #38.');
Fleets::setLastId(max(38,Fleets::getLastId()));FleetsShips::setLastId(max(38,FleetsShips::getLastId()));
$fleet = FleetsData::getInstance()->get(38);
restoreSensorAssert($fleet !== null && $fleet->getTotalShips() === 115 && $fleet->getCapacity() === 2515000.0, 'Не совпали состав/вместимость runtime Fleet.');
$copy = new \SPGame\Game\Fleet\Fleet(38);unset($copy);gc_collect_cycles();
restoreSensorAssert(Fleets::findExistingById(38) === $row && FleetsShips::findExistingById(38) === $ships, 'GC изменил восстановленные строки.');
Fleets::syncToDatabase();FleetsShips::syncToDatabase();
foreach (['fleets'=>$row,'fleets_ships'=>$ships] as $table=>$expected) {
    $actual = $db->fetchOne("SELECT * FROM `$table` WHERE id=38");
    restoreSensorAssert($actual !== null, "Не сохранён #38 в $table.");
    foreach ($expected as $key=>$value) restoreSensorAssert(array_key_exists($key,$actual)
        && (is_numeric($value) ? abs((float)$actual[$key]-(float)$value)<.001 : $actual[$key]===$value), "Не совпало $table.$key.");
}
restoreSensorAssert(restoreSensorFingerprint($db) === $fingerprints, 'Изменились другие флоты, команды или ресурсы.');
restoreSensorAssert(Fleets::getPendingSync()['delete'] === [] && FleetsShips::getPendingSync()['delete'] === [], 'После recovery остались DELETE markers.');
echo json_encode(['restored'=>38,'backup'=>$backup,'commandsUnchanged'=>true,'otherFleetsAndResourcesUnchanged'=>true,
    'nativeGcSafe'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), "\n";
