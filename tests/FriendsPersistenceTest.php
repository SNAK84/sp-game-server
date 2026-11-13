<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Core\{Database, Environment, Logger};
use SPGame\Game\Repositories\Friends;
use SPGame\Game\Services\RepositorySaver;
use Swoole\Table;

if (!extension_loaded('swoole')) exit(77);
Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$name = 'qa_friends_' . bin2hex(random_bytes(6));
$schema = (new ReflectionProperty(Friends::class, 'tableSchema'))->getValue();
$columns = [];
foreach ($schema['columns'] as $key => $column) $columns[] = '`' . $key . '` ' . $column['sql'];
// Только временная таблица текущего соединения. Игровая friends не читается/не меняется.
$db->query('CREATE TEMPORARY TABLE `' . $name . '` (' . implode(',', $columns) . ', PRIMARY KEY (`id`))');
try {
    (new ReflectionProperty(Friends::class, 'tableName'))->setValue(null, $name);
    (new ReflectionProperty(Friends::class, 'logger'))->setValue(null, Logger::getInstance());
    $table = new Table(16);
    foreach ($schema['columns'] as $key => $column) $table->column($key, $column['swoole'][0], $column['swoole'][1] ?? 8);
    $table->create();
    (new ReflectionProperty(Friends::class, 'table'))->setValue(null, $table);
    $sync = new Table(16);
    foreach (['need_update', 'need_delete', 'last_id'] as $key) $sync->column($key, Table::TYPE_INT, 8);
    $sync->create(); $sync->set('meta', ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0]);
    (new ReflectionProperty(Friends::class, 'syncTable'))->setValue(null, $sync);
    $indexes = [];
    foreach ((new ReflectionProperty(Friends::class, 'indexes'))->getValue() as $key => $unused) {
        $index = new Table(16); $index->column('id', Table::TYPE_INT, 8); $index->column('ids', Table::TYPE_STRING, 256);
        $index->create(); $indexes[$key] = $index;
    }
    (new ReflectionProperty(Friends::class, 'indexTables'))->setValue(null, $indexes);
    $note = str_repeat('🚀', 498) . "\nя";
    $row = Friends::castRowToSchema(['user_low' => 1, 'user_high' => 2, 'requester_id' => 1,
        'status' => Friends::PENDING, 'created_at' => time(), 'request_text' => $note], true);
    Friends::add($row);
    $saver = new RepositorySaver(); $saver->register(Friends::class); $saver->saveAll();
    $saved = $db->fetchOne('SELECT * FROM `' . $name . '` WHERE id=?', [$row['id']]);
    if ($saved === null || Friends::castRowToSchema($saved, true) !== $row || Friends::getPendingSync()['update'] !== []) {
        throw new RuntimeException('INSERT текста заявки/dirty marker не прошёл.');
    }
    Friends::update(['id' => $row['id'], 'status' => Friends::ACCEPTED]);
    $saver->saveAll();
    $saved = $db->fetchOne('SELECT * FROM `' . $name . '` WHERE id=?', [$row['id']]);
    if ((int)$saved['status'] !== Friends::ACCEPTED || $saved['request_text'] !== $note
        || Friends::getPendingSync()['update'] !== []) throw new RuntimeException('UPDATE связи не прошёл.');
    // Холодная загрузка: читаем именно MySQL, а не прежнюю runtime строку.
    $table->del((string)$row['id']);
    Friends::add(Friends::castRowToSchema($saved, true), false);
    if (!Friends::areFriends(1, 2) || Friends::pair(1, 2)['request_text'] !== $note) {
        throw new RuntimeException('Reload не восстановил связь/Unicode-текст.');
    }
    echo "PASS Friends: real Swoole → RepositorySaver → temporary MySQL → cold reload; 500 Unicode characters intact\n";
} finally {
    $db->query('DROP TEMPORARY TABLE IF EXISTS `' . $name . '`');
}
