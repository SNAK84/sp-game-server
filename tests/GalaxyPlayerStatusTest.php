<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Game\Pages\GalaxyPage;

// Чистый расчёт публичных статусов: без БД, Swoole и изменения игроков.
$method = new ReflectionMethod(GalaxyPage::class, 'playerStatus');
$now = 2000000000;
$cases = [
    'Нет владельца' => [null, []],
    'Нет времени активности' => [[], []],
    'Нулевое время неизвестно' => [['online_time' => 0], []],
    'Активный игрок' => [['online_time' => $now], []],
    'Время в будущем' => [['online_time' => $now + 60], []],
    'Ровно 7 дней' => [['online_time' => $now - 7 * 86400], []],
    'Более 7 дней' => [['online_time' => $now - 7 * 86400 - 1], ['inactive']],
    'Ровно 28 дней' => [['online_time' => $now - 28 * 86400], ['inactive']],
    'Более 28 дней' => [['online_time' => $now - 28 * 86400 - 1], ['inactive', 'longinactive']],
];
foreach ($cases as $name => [$owner, $expected]) {
    if ($method->invoke(null, $owner, $now) !== $expected) {
        throw new RuntimeException('Неверный статус: ' . $name);
    }
    echo "OK {$name}\n";
}
