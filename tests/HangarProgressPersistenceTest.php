<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\Queues;
use SPGame\Game\Services\RepositorySaver;

if (!class_exists('Swoole\\Table')) {
    echo "SKIP: необходимы Swoole и тестовая MySQL.\n";
    exit(0);
}

/** Только уникальная тестовая таблица, не игровая queues. */
class ProgressTestQueues extends Queues
{
    protected static int $tableSize = 64;

    public static function useTestTable(string $name): void
    {
        static::$tableName = $name;
        static::$className = 'ProgressTestQueues';
    }
}

Environment::init();
$db = Database::getInstance();
$table = 'test_hangar_progress_' . bin2hex(random_bytes(6));
ProgressTestQueues::useTestTable($table);
$saver = new RepositorySaver();
try {
    ProgressTestQueues::init($saver);
    $id = ProgressTestQueues::add(['user_id' => 1, 'planet_id' => 1, 'object_id' => 202,
        'type' => 'hangars', 'count' => 5, 'total_count' => 5, 'time' => 10,
        'start_time' => 1000, 'end_time' => 1050]);
    ProgressTestQueues::update(['id' => $id, 'count' => 3, 'start_time' => 1020]);
    $saver->saveAll();
    $saved = $db->fetchOne("SELECT count, total_count FROM `{$table}` WHERE id = ?", [$id]);
    if ((int)$saved['count'] !== 3 || (int)$saved['total_count'] !== 5
        || ProgressTestQueues::getPendingSync()['update'] !== []) {
        throw new RuntimeException('Новый объём партии не сохранился через RepositorySaver.');
    }
    ProgressTestQueues::init();
    $restored = ProgressTestQueues::findById($id);
    if ($restored['count'] !== 3 || $restored['total_count'] !== 5) {
        throw new RuntimeException('Размер партии изменился после reload runtime из MySQL.');
    }
    echo "OK hangar total_count: Swoole → RepositorySaver → MySQL → reload, remaining=3 / total=5\n";
} finally {
    // Удаляется только таблица, созданная этим тестом с уникальным именем.
    $db->query("DROP TABLE IF EXISTS `{$table}`");
}
