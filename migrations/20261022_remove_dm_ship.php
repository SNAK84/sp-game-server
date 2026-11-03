<?php

declare(strict_types=1);

use SPGame\Core\{Database, Environment};

/** Только при остановленном игровом сервере: его Tables ещё содержат прежние колонки. */
function removeDmShip(Database $db, bool $apply = false, ?string $backupDirectory = null): array
{
    $definition = $db->fetchAll('SELECT * FROM vars WHERE elementID=220');
    if ($definition !== [] && ($definition[0]['name'] !== 'dm_ship' || (int)$definition[0]['class'] !== 200)) {
        throw new RuntimeException('ID 220 занят другим объектом. Удаление отменено.');
    }
    $snapshot = ['definition'=>$definition,
        'requirements'=>$db->fetchAll('SELECT * FROM vars_requirements WHERE elementID=220 OR requireID=220'),
        'rapidfire'=>$db->fetchAll('SELECT * FROM vars_rapidfire WHERE elementID=220 OR rapidfireID=220'),
        'storage'=>[]];
    foreach (['ships'=>['dm_ship'], 'fleets_ships'=>['dm_ship_count','dm_ship_damage','dm_ship_experience','dm_ship_morale','dm_ship_status']] as $table=>$names) {
        $columns = array_values(array_filter($db->fetchAll("SHOW COLUMNS FROM `$table`"), static fn($c)=>in_array($c['Field'],$names,true)));
        $countColumn = $table === 'ships' ? 'dm_ship' : 'dm_ship_count';
        if (in_array($countColumn,array_column($columns,'Field'),true)
            && (int)$db->fetchOne("SELECT COUNT(*) total FROM `$table` WHERE `$countColumn`<>0")['total'] > 0) {
            throw new RuntimeException('Есть корабли dm_ship в '.$table.'. Нужна отдельная конвертация; удаление отменено.');
        }
        if ($columns !== []) {
            $fields = '`id`,'.implode(',',array_map(static fn($c)=>'`'.$c['Field'].'`',$columns));
            $snapshot['storage'][$table] = ['columns'=>$columns,'rows'=>$db->fetchAll("SELECT $fields FROM `$table`")];
        }
    }
    if ((int)$db->fetchOne('SELECT COUNT(*) total FROM queues WHERE object_id=220')['total'] > 0) {
        throw new RuntimeException('Есть заказы dm_ship. Удаление отменено.');
    }
    foreach ($db->fetchAll('SELECT state FROM fleet_expeditions') as $row) {
        $state = json_decode($row['state'],true,512,JSON_THROW_ON_ERROR);
        if (!empty($state['plan']['ships'][220])) throw new RuntimeException('Есть рассчитанная находка dm_ship. Удаление отменено.');
    }
    $changed = $snapshot['definition'] !== [] || $snapshot['requirements'] !== [] || $snapshot['rapidfire'] !== [] || $snapshot['storage'] !== [];
    $result = ['changed'=>$changed,'applied'=>false,'backup'=>null,
        'definitions'=>count($definition),'requirements'=>count($snapshot['requirements']),'rapidfire'=>count($snapshot['rapidfire']),
        'columns'=>array_map(static fn($data)=>array_column($data['columns'],'Field'),$snapshot['storage'])];
    if (!$apply || !$changed) return $result;

    // Резервируем только удаляемые определения, колонки и их значения; без паролей/токенов.
    $backupDirectory ??= __DIR__.'/../logs/maintenance-backups';
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory,0700,true)) throw new RuntimeException('Не создан каталог резервной копии.');
    $path = tempnam($backupDirectory,'remove-dm-ship-');
    if ($path === false || !chmod($path,0600)
        || file_put_contents($path,json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX) === false) {
        throw new RuntimeException('Не сохранена резервная копия. Удаление не выполнялось.');
    }
    $result['backup'] = $path;
    // DDL не транзакционен: сохранённая копия остаётся также при частичном отказе.
    $db->query('DELETE FROM vars_requirements WHERE elementID=220 OR requireID=220');
    $db->query('DELETE FROM vars_rapidfire WHERE elementID=220 OR rapidfireID=220');
    $db->query("DELETE FROM vars WHERE elementID=220 AND name='dm_ship'");
    foreach ($snapshot['storage'] as $table=>$data) {
        $drops = implode(',',array_map(static fn($c)=>'DROP COLUMN `'.$c['Field'].'`',$data['columns']));
        $db->query("ALTER TABLE `$table` $drops");
    }
    $result['applied'] = true;
    return $result;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__.'/../vendor/autoload.php';
    Environment::init(__DIR__.'/../.env');
    $mode = $argv[1] ?? '--check';
    if (!in_array($mode,['--check','--apply'],true)) throw new InvalidArgumentException('Режим: --check или --apply; --apply только при остановленном сервере.');
    echo json_encode(removeDmShip(Database::getInstance(),$mode === '--apply'),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
}
