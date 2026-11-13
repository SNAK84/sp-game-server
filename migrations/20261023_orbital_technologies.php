<?php

declare(strict_types=1);

// php migrations/20261023_orbital_technologies.php --apply; без флага только проверка.
require_once __DIR__ . '/../vendor/autoload.php';

$database = SPGame\Core\Database::getInstance();
$definitions = [
    135 => ['sensor_tech', 2000, 4000, 2000, [31 => 4, 106 => 4]],
    136 => ['extraterrestrial_mining_tech', 4000, 8000, 4000, [31 => 5, 124 => 3, 135 => 2]],
];
// Проверить обе записи до первой записи. Чужие ID/цены не перезаписывать.
foreach ($definitions as $id => [$name, $metal, $crystal, $deuterium, $requirements]) {
    $existing = $database->fetchOne('SELECT * FROM vars WHERE elementID=? OR name=?', [$id, $name]);
    if ($existing !== null && ((int)$existing['elementID'] !== $id || $existing['name'] !== $name
        || (int)$existing['class'] !== 100 || (int)$existing['type'] !== 2 || (float)$existing['factor'] !== 2.0
        || (int)$existing['cost901'] !== $metal || (int)$existing['cost902'] !== $crystal || (int)$existing['cost903'] !== $deuterium)) {
        throw new RuntimeException('ID/параметры новой технологии уже заняты: ' . $id);
    }
    foreach ($database->fetchAll('SELECT requireID,requireLevel FROM vars_requirements WHERE elementID=?', [$id]) as $row) {
        if (($requirements[(int)$row['requireID']] ?? null) !== (int)$row['requireLevel']) throw new RuntimeException('Требования технологии уже отличаются: ' . $id);
    }
}
if (!in_array('--apply', $argv, true)) {
    echo "Проверено: технологии 135/136, для применения укажите --apply.\n";
    return;
}
foreach ($definitions as $id => [$name, $metal, $crystal, $deuterium, $requirements]) {
    if ($database->fetchOne('SELECT elementID FROM vars WHERE elementID=?', [$id]) === null) {
        $database->query("INSERT INTO vars (elementID,name,class,type,onPlanetType,onePerPlanet,factor,maxLevel,cost901,cost902,cost903)
            VALUES (?,?,100,2,'1,3',0,2,255,?,?,?)", [$id, $name, $metal, $crystal, $deuterium]);
    }
    foreach ($requirements as $requireId => $level) {
        if ($database->fetchOne('SELECT requireLevel FROM vars_requirements WHERE elementID=? AND requireID=?', [$id, $requireId]) === null) {
            $database->query('INSERT INTO vars_requirements (elementID,requireID,requireLevel) VALUES (?,?,?)', [$id, $requireId, $level]);
        }
    }
}
echo "Технологии 135/136 добавлены. Колонки/нулевые уровни создаст штатный Techs::init() при старте; чужие уровни не менялись.\n";
