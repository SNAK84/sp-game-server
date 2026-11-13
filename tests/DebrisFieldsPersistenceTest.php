<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Core\Database;
use SPGame\Core\Environment;
use SPGame\Game\Repositories\DebrisFields;
use SPGame\Game\Services\RepositorySaver;

if (!class_exists('Swoole\\Table')) {
    echo "SKIP: необходимы Swoole и тестовая MySQL.\n";
    exit(0);
}

/** Изолированная таблица; игровые debris_fields не изменяются. */
class PersistenceTestDebris extends DebrisFields
{
    protected static int $tableSize = 64;

    public static function useTestTable(string $name): void
    {
        static::$tableName = $name;
        static::$className = 'PersistenceTestDebris';
    }
}

Environment::init();
$db = Database::getInstance();
$table = 'test_debris_' . bin2hex(random_bytes(6));
PersistenceTestDebris::useTestTable($table);
$saver = new RepositorySaver();
try {
    PersistenceTestDebris::init($saver);
    $field = PersistenceTestDebris::addResources(1, 2, 3, 123.5, 300, microtime(true));
    PersistenceTestDebris::addResources(1, 2, 3, 10, 600, microtime(true));
    $saver->saveAll();
    $saved = $db->fetchOne("SELECT * FROM `{$table}` WHERE id = ?", [$field['id']]);
    if ((float)$saved['metal'] !== 133.5 || (float)$saved['crystal'] !== 900.0
        || PersistenceTestDebris::getPendingSync()['update'] !== []) {
        throw new RuntimeException('Runtime → RepositorySaver → MySQL не совпали.');
    }
    // Новые Swoole Tables имитируют загрузку репозитория после рестарта.
    PersistenceTestDebris::init();
    $restored = PersistenceTestDebris::at(1, 2, 3);
    if ((float)$restored['metal'] !== 133.5 || (float)$restored['crystal'] !== 900.0) {
        throw new RuntimeException('Обломки не восстановились после повторной инициализации.');
    }
    PersistenceTestDebris::subtractResources(1, 2, 3, 133.5, 900, microtime(true));
    $saver->saveAll();
    PersistenceTestDebris::init();
    $empty = PersistenceTestDebris::at(1, 2, 3);
    if ($empty === null || $empty['metal'] !== 0.0 || $empty['crystal'] !== 0.0) {
        throw new RuntimeException('После сохранения/reload собранные ресурсы появились снова.');
    }
    echo "OK Swoole → RepositorySaver → MySQL → reload; пополнение 133.5 / 900 и полное списание 0 / 0\n";
} finally {
    // Удаляется только таблица, созданная этим тестом с уникальным именем.
    $db->query("DROP TABLE IF EXISTS `{$table}`");
}
