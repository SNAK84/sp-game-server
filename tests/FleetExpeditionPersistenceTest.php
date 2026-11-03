<?php

declare(strict_types=1);

/** Изолированная Swoole Table + TEMPORARY MySQL; не читает и не меняет игровые строки. */
require __DIR__.'/bootstrap.php';

use SPGame\Core\{Database,Environment,Logger};
use SPGame\Game\Repositories\FleetExpeditions as States;
use Swoole\Table;

if(!extension_loaded('swoole')) exit(77);
Environment::init();
$reflection=new ReflectionClass(States::class);
$table=new Table(8); $table->column('id',Table::TYPE_INT,8); $table->column('state',Table::TYPE_STRING,States::MAX_BYTES); $table->create();
$sync=new Table(16);
foreach(['need_update','need_delete','last_id'] as $key) $sync->column($key,Table::TYPE_INT,8);
$sync->create(); $sync->set('meta',['need_update'=>0,'need_delete'=>0,'last_id'=>0]);
$reflection->getProperty('table')->setValue(null,$table);
$reflection->getProperty('syncTable')->setValue(null,$sync);
$reflection->getProperty('logger')->setValue(null,Logger::getInstance());
$property=$reflection->getProperty('tableName'); $original=$property->getValue();
$name='qa_expedition_'.bin2hex(random_bytes(6)); $db=Database::getInstance();
$db->query('CREATE TEMPORARY TABLE `'.$name.'` (id BIGINT UNSIGNED PRIMARY KEY,state MEDIUMTEXT NOT NULL,execute_time DOUBLE(17,6) NOT NULL DEFAULT 0.0)');
$property->setValue(null,$name);
try {
    foreach(['holding','combat','returning'] as $phase) {
        $state=['phase'=>$phase,'commandId'=>123,'ownerId'=>77,'seed'=>str_repeat('01',32),
            'startedAt'=>1000.125,'endsAt'=>11800.125,'coordinate'=>['type'=>1,'galaxy'=>1,'system'=>10,'orbit'=>18,'angle'=>45.5],
            'hourly'=>true,'timeFactor'=>2.0,'summarySent'=>false,
            'events'=>[['at'=>4600.125,'event'=>'credits','credits'=>127],['at'=>8200.125,'event'=>'nothing','credits'=>0]],
            'plan'=>['event'=>'credits','credits'=>127,'resources'=>[901=>0.0,902=>23.5,903=>0.0]],'description'=>'Экспедиционный snapshot'];
        States::store(77,$state); States::syncToDatabase(1);
        $pending=States::getPendingSync();
        if(($pending['update']??[])!==[]) throw new RuntimeException('Успешный saver не очистил marker.');
        $row=$db->fetchOne('SELECT * FROM `'.$name.'` WHERE id=?',[77]);
        $table->del('77');
        $table->set('77',['id'=>(int)$row['id'],'state'=>$row['state']]);
        if(States::state(77)!==$state) throw new RuntimeException('Изменился сохранённый checkpoint экспедиции.');
        echo 'PASS MySQL expedition checkpoint '.$phase."\n";
    }
    // PDO передаёт float как строку с precision, JSON сохраняет serialize_precision.
    // В сочетании с DOUBLE(17,6) это воспроизводит расхождение после restart.
    $state['startedAt'] = 1792557171.5656018;
    $state['endsAt'] = $state['startedAt'] + 10800;
    $state['events'] = [];
    States::store(77, $state); States::syncToDatabase(1);
    $due = $state['startedAt'] + 3600;
    $precision = ini_get('precision');
    try {
        ini_set('precision', '14');
        $db->query('UPDATE `'.$name.'` SET execute_time=? WHERE id=?', [$due, 77]);
    } finally {
        ini_set('precision', $precision);
    }
    $row = $db->fetchOne('SELECT * FROM `'.$name.'` WHERE id=?', [77]);
    $eventAt = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR)['startedAt'] + 3600;
    if ($eventAt !== $due || (float)$row['execute_time'] >= $eventAt || $eventAt - (float)$row['execute_time'] > .001) {
        throw new RuntimeException('Не воспроизведено расхождение числового времени и JSON checkpoint.');
    }
    echo "PASS MySQL deadline precision: JSON retains exact hour; numeric deadline rounds earlier\n";
    States::delete(['id'=>77]); States::syncToDatabase(1);
    if($db->fetchOne('SELECT * FROM `'.$name.'` WHERE id=77')!==null) throw new RuntimeException('Завершённое состояние не удалено из SQL.');
    echo "RESULT expedition checkpoints: 3 reloads, deadline precision and cleanup passed\n";
} finally {
    $property->setValue(null,$original);
    $db->query('DROP TEMPORARY TABLE `'.$name.'`');
}
