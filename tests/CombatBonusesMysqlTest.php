<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Core\{Database,Environment,Logger,LogLevel};
use SPGame\Game\Repositories\Vars;

Environment::init();
$db = Database::getInstance();
$logger = Logger::getInstance();
(new ReflectionProperty($logger,'islogFile'))->setValue($logger,false);
(new ReflectionProperty($logger,'minLevel'))->setValue($logger,LogLevel::ERROR);
$original = $db->fetchAll('SELECT * FROM vars WHERE elementID IN (109,110,111) ORDER BY elementID');
$copy = 'qa_combat_vars_'.bin2hex(random_bytes(6));
$shadow = false;
try {
    // TEMPORARY vars видна только этому соединению. Таблица игры остаётся неизменной.
    $db->query("CREATE TEMPORARY TABLE `$copy` AS SELECT * FROM vars");
    $db->query("CREATE TEMPORARY TABLE vars LIKE `$copy`");
    $shadow = true;
    $db->query("INSERT INTO vars SELECT * FROM `$copy`");
    $db->query('UPDATE vars SET bonusAttack=0,bonusDefensive=0,bonusShield=0 WHERE elementID IN (109,110,111)');
    $sql = preg_replace('/^--.*$/m','',file_get_contents(__DIR__.'/../migrations/20261019_combat_technology_bonuses.sql'));
    $before = $db->fetchAll('SELECT * FROM vars ORDER BY elementID');
    for ($pass=0;$pass<2;++$pass) {
        foreach (array_filter(array_map('trim',explode(';',$sql))) as $statement) $db->query($statement);
        Vars::init();
        foreach ([109=>'Attack',110=>'Defensive',111=>'Shield'] as $id=>$key) {
            if ((float)Vars::$bonus[$id][$key][0]!==0.1 || (int)Vars::$bonus[$id][$key][1]!==0
                || !in_array($id,Vars::$reslist['bonus'],true)) throw new RuntimeException('Vars не загрузил боевой бонус '.$id);
        }
        $after = $db->fetchAll('SELECT * FROM vars ORDER BY elementID');
        foreach ($before as $i=>$row) {
            $expected = $row;
            $column = [109=>'bonusAttack',110=>'bonusDefensive',111=>'bonusShield'][$row['elementID']] ?? null;
            if ($column!==null) {$expected[$column]=0.1; $expected[$column.'Unit']=0;}
            if ($after[$i]!=$expected) throw new RuntimeException('Миграция изменила лишние поля vars.');
        }
    }
    echo "OK migration + Vars loader: 10% bonuses, idempotency, unrelated fields preserved\n";
} finally {
    if ($shadow) $db->query('DROP TEMPORARY TABLE vars');
    $db->query("DROP TEMPORARY TABLE IF EXISTS `$copy`");
}
if ($db->fetchAll('SELECT * FROM vars WHERE elementID IN (109,110,111) ORDER BY elementID')!==$original) {
    throw new RuntimeException('Изменена реальная таблица vars.');
}
echo "OK actual game vars unchanged by isolated migration test\n";
