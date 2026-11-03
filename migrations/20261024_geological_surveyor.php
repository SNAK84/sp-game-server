<?php

declare(strict_types=1);

// Только при остановленном сервере; без --apply выполняется read-only проверка.
require_once __DIR__ . '/../vendor/autoload.php';
SPGame\Core\Environment::init(__DIR__ . '/../.env');
$db = SPGame\Core\Database::getInstance();
$definition = ['elementID' => 222, 'name' => 'geological_surveyor', 'class' => 200, 'type' => 1,
    'onPlanetType' => '1,3', 'onePerPlanet' => 0, 'factor' => 1,
    'cost901' => 20000, 'cost902' => 30000, 'cost903' => 10000, 'capacity' => 2000,
    'consumption1' => 75, 'consumption2' => 75, 'speedTech' => 2, 'speed1' => 7500, 'speed2' => 7500,
    'attack' => 1, 'defend' => 25, 'fleetMissions' => '["transport","relocate","observe","merge","expedition","research"]'];
$requirements = [21 => 6, 135 => 3, 124 => 3, 117 => 4];
$old = $db->fetchOne('SELECT * FROM vars WHERE elementID=222 OR name=?', ['geological_surveyor']);
if ($old !== null) foreach ($definition as $key => $value) {
    if ((string)$old[$key] !== (string)$value) throw new RuntimeException('ID или баланс геологоразведчика уже отличается: ' . $key);
}
foreach ($db->fetchAll('SELECT requireID,requireLevel FROM vars_requirements WHERE elementID=222') as $row) {
    if (($requirements[(int)$row['requireID']] ?? null) !== (int)$row['requireLevel']) throw new RuntimeException('Требования геологоразведчика отличаются.');
}
if ($old === null && $db->fetchAll('SELECT elementID FROM vars_requirements WHERE requireID=222')) {
    throw new RuntimeException('ID 222 уже используется требованиями других объектов.');
}
if (!in_array('--apply', $argv, true)) { echo "Корпус 222 проверен; применение --apply только при остановленном сервере.\n"; return; }
if ($old === null) $db->query('INSERT INTO vars (`' . implode('`,`', array_keys($definition)) . '`) VALUES ('
    . implode(',', array_fill(0, count($definition), '?')) . ')', array_values($definition));
foreach ($requirements as $id => $level) {
    if ($db->fetchOne('SELECT requireLevel FROM vars_requirements WHERE elementID=222 AND requireID=?', [$id]) === null) {
        $db->query('INSERT INTO vars_requirements (elementID,requireID,requireLevel) VALUES (222,?,?)', [$id, $level]);
    }
}
echo "Геологоразведочный корабль 222 добавлен. Динамические колонки создаст штатный init.\n";
