<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/../migrations/20261022_remove_dm_ship.php';

use SPGame\Core\{Database,Environment,Logger,LogLevel};
use SPGame\Game\Repositories\Vars;

Environment::init();
$db = Database::getInstance();
$logger = Logger::getInstance();
(new ReflectionProperty($logger,'islogFile'))->setValue($logger,false);
(new ReflectionProperty($logger,'minLevel'))->setValue($logger,LogLevel::ERROR);
$before = $db->fetchAll('SELECT * FROM vars WHERE elementID IN (202,220) ORDER BY elementID');
$temporary = [];
function removedAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    // Все изменения миграции ограничены TEMPORARY таблицами текущего соединения.
    foreach (['vars','vars_requirements','vars_rapidfire'] as $table) {
        $rows = $db->fetchAll("SELECT * FROM `$table`");
        $ddl = array_values($db->fetchOne("SHOW CREATE TABLE `$table`"))[1];
        $db->query(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl,1));
        $temporary[] = $table;
        foreach ($rows as $row) {
            $keys = '`'.implode('`,`',array_keys($row)).'`';
            $db->query("INSERT INTO `$table` ($keys) VALUES (".implode(',',array_fill(0,count($row),'?')).')',array_values($row));
        }
    }
    if ($db->fetchOne('SELECT * FROM vars WHERE elementID=220') === null) {
        $row = $db->fetchOne('SELECT * FROM vars WHERE elementID=202');
        $row['elementID']=220;$row['name']='dm_ship';$row['class']=200;
        $db->query('INSERT INTO vars (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
    }
    foreach ([
        'ships'=>'id INT PRIMARY KEY,dm_ship INT NOT NULL DEFAULT 0,small_cargo INT NOT NULL DEFAULT 0',
        'fleets_ships'=>'id INT PRIMARY KEY,dm_ship_count INT NOT NULL DEFAULT 0,dm_ship_damage DOUBLE DEFAULT 0,dm_ship_experience DOUBLE DEFAULT 0,dm_ship_morale DOUBLE DEFAULT 1,dm_ship_status INT DEFAULT 0,small_cargo_count INT DEFAULT 0',
        'queues'=>'id INT PRIMARY KEY,object_id INT',
        'fleet_expeditions'=>'id INT PRIMARY KEY,state MEDIUMTEXT',
    ] as $table=>$schema) {
        $db->query("CREATE TEMPORARY TABLE `$table` ($schema)");
        $temporary[]=$table;
    }
    $db->query('INSERT INTO ships (id,small_cargo) VALUES (1,17)');
    $db->query('INSERT INTO fleets_ships (id,small_cargo_count) VALUES (1,23)');
    $original202=$db->fetchOne('SELECT * FROM vars WHERE elementID=202');
    $check=removeDmShip($db);
    removedAssert($check['changed'] && !$check['applied'] && $check['backup']===null,'Dry-run изменил данные.');
    foreach (['ships','fleets_ships','queues','fleet_expeditions'] as $case) {
        if ($case==='ships') $db->query('UPDATE ships SET dm_ship=1');
        if ($case==='fleets_ships') $db->query('UPDATE fleets_ships SET dm_ship_count=1');
        if ($case==='queues') $db->query('INSERT INTO queues VALUES (1,220)');
        if ($case==='fleet_expeditions') $db->query('INSERT INTO fleet_expeditions VALUES (1,?)',[json_encode(['plan'=>['ships'=>[220=>1]]])]);
        $rejected=false;
        try { removeDmShip($db,true,sys_get_temp_dir()); } catch (RuntimeException) { $rejected=true; }
        removedAssert($rejected && $db->fetchOne('SELECT name FROM vars WHERE elementID=220')['name']==='dm_ship','Удаление при наличии '.$case);
        $db->query('UPDATE ships SET dm_ship=0');
        $db->query('UPDATE fleets_ships SET dm_ship_count=0');
        $db->query('DELETE FROM queues');
        $db->query('DELETE FROM fleet_expeditions');
    }
    echo "PASS guards: planet/fleet ships, queued order, pending expedition result\n";
    $db->query("UPDATE vars SET name='another_ship' WHERE elementID=220");
    $rejected=false;
    try { removeDmShip($db,true,sys_get_temp_dir()); } catch (RuntimeException) { $rejected=true; }
    removedAssert($rejected,'Удалён другой объект с ID 220.');
    $db->query("UPDATE vars SET name='dm_ship' WHERE elementID=220");
    $result=removeDmShip($db,true,sys_get_temp_dir());
    removedAssert($result['applied'] && is_file($result['backup']),'Нет резервной копии.');
    $backup=json_decode(file_get_contents($result['backup']),true,512,JSON_THROW_ON_ERROR);
    removedAssert($backup['definition'][0]['name']==='dm_ship' && count($backup['storage']['fleets_ships']['columns'])===5,'Неполная резервная копия.');
    removedAssert($db->fetchOne('SELECT name FROM vars WHERE elementID=220')===null
        && $db->fetchAll('SELECT * FROM vars_requirements WHERE elementID=220 OR requireID=220')===[]
        && $db->fetchAll('SELECT * FROM vars_rapidfire WHERE elementID=220 OR rapidfireID=220')===[],'Остались определения/зависимости.');
    removedAssert($db->fetchAll('SELECT * FROM ships')===[['id'=>1,'small_cargo'=>17]]
        && $db->fetchAll('SELECT * FROM fleets_ships')===[['id'=>1,'small_cargo_count'=>23]]
        && $db->fetchOne('SELECT * FROM vars WHERE elementID=202')===$original202,'Изменились другие корабли.');
    Vars::init();
    removedAssert(!isset(Vars::$resource[220]) && !in_array(220,Vars::$reslist['fleet'],true),'Vars снова зарегистрировал удалённый тип.');
    $repeat=removeDmShip($db,true,sys_get_temp_dir());
    removedAssert(!$repeat['changed'] && !$repeat['applied'] && $repeat['backup']===null,'Повтор небезопасен.');
    echo "PASS complete removal, backup, Vars reload, unrelated columns/ship preserved, idempotency\n";
} finally {
    foreach (array_reverse($temporary) as $table) $db->query("DROP TEMPORARY TABLE `$table`");
}
removedAssert($db->fetchAll('SELECT * FROM vars WHERE elementID IN (202,220) ORDER BY elementID')===$before,'Затронута игровая таблица vars.');
echo "RESULT game tables unchanged\n";
