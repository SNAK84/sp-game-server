<?php

declare(strict_types=1);

// Применять при остановленном игровом сервере; --apply, без флага read-only.
require_once __DIR__ . '/../vendor/autoload.php';
$db = SPGame\Core\Database::getInstance();
$definition = ['elementID'=>221,'name'=>'sensor_scout','class'=>200,'type'=>1,'onPlanetType'=>'1,3',
    'onePerPlanet'=>0,'factor'=>1,'cost901'=>10000,'cost902'=>20000,'cost903'=>10000,
    'capacity'=>1000,'consumption1'=>50,'consumption2'=>50,'speedTech'=>2,'speed1'=>10000,'speed2'=>10000,
    'attack'=>5,'defend'=>50,'fleetMissions'=>'["transport","relocate","observe","merge","expedition"]'];
$requirements = [21=>6,135=>3,106=>5,117=>4];
$old = $db->fetchOne('SELECT * FROM vars WHERE elementID=221 OR name=?', ['sensor_scout']);
if ($old !== null) foreach ($definition as $key=>$value) {
    if ((string)$old[$key] !== (string)$value) throw new RuntimeException('ID/баланс разведчика уже отличается: '.$key);
}
foreach ($db->fetchAll('SELECT requireID,requireLevel FROM vars_requirements WHERE elementID=221') as $row) {
    if (($requirements[(int)$row['requireID']] ?? null) !== (int)$row['requireLevel']) throw new RuntimeException('Требования разведчика отличаются.');
}
if (!$old && $db->fetchAll('SELECT elementID FROM vars_requirements WHERE requireID=221')) throw new RuntimeException('ID разведчика уже используется требованиями.');
if (!in_array('--apply',$argv,true)) { echo "Разведчик 221 проверен; для применения --apply на остановленном сервере.\n"; return; }
if ($old === null) $db->query('INSERT INTO vars (`'.implode('`,`',array_keys($definition)).'`) VALUES ('.implode(',',array_fill(0,count($definition),'?')).')',array_values($definition));
foreach ($requirements as $id=>$level) if ($db->fetchOne('SELECT requireLevel FROM vars_requirements WHERE elementID=221 AND requireID=?',[$id]) === null) {
    $db->query('INSERT INTO vars_requirements (elementID,requireID,requireLevel) VALUES (221,?,?)',[$id,$level]);
}
echo "Сенсорный разведчик 221 добавлен; нулевые колонки Ships создаст штатный init.\n";
