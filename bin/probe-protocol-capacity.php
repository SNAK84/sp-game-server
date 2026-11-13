#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Ограниченная проба расширения: нет init мира, MySQL, сокетов и игровых данных. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\Connect;
use SPGame\Game\Repositories\{ProtocolResources, ProtocolSubscriptions};

if (!in_array('--execute', $argv, true) || !extension_loaded('swoole')) {
    fwrite(STDERR, "Запуск в swoole_app: php bin/probe-protocol-capacity.php --execute\n");
    exit(64);
}
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$started = microtime(true);

Connect::init();
for ($fd = 1; $fd <= 2048; ++$fd) check(Connect::set($fd, '127.0.0.1', 10000 + $fd, null), 'Недостаточно мест соединений.');
check(Connect::getCount() === 2048 && !Connect::set(2049, '127.0.0.1', 12049, null), 'Не работает admission при заполнении.');
Connect::unset(1000);
check(Connect::set(2049, '127.0.0.1', 12049, null) && Connect::getCount() === 2048, 'Не используется освобождённое место.');

ProtocolResources::init();
$keys = [];
for ($i = 0; $i < 20000; ++$i) {
    $keys[$i] = substr(hash('sha256', 'capacity-projection-' . $i), 0, 32);
    $last = (int)ProtocolResources::revision($keys[$i], ['sample' => $i]);
    check($last === $i + 1, 'Sequence пропущена или откатилась.');
}
$table = (new ReflectionProperty(ProtocolResources::class, 'table'))->getValue();
check($table->count() === 16384, 'Кэш вышел за бюджет.');
check((int)ProtocolResources::revision($keys[19999], ['sample' => 19999]) === $last, 'Неизменный снимок получил новую версию.');
check((int)ProtocolResources::revision($keys[19999], ['sample' => -1]) > $last && $table->count() === 16384, 'Обновление полного кэша удаляет лишнюю запись.');
foreach ($keys as $i => $key) if (!$table->exists($key)) {
    check((int)ProtocolResources::revision($key, ['sample' => $i]) > $last, 'Eviction откатил revision.');
    break;
}

ProtocolSubscriptions::init();
$id = static function (string $value): string {
    $hex = md5($value);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
};
for ($fd = 1; $fd <= 1000; ++$fd) {
    $connection = md5('connection-' . $fd);
    for ($slot = 0; $slot < 8; ++$slot) {
        ProtocolSubscriptions::put($fd, $fd, $connection, $id((string)$slot), 'overview.get', ['context' => ['planetId' => (string)$fd], 'data' => []]);
    }
}
check(count(ProtocolSubscriptions::pending()) === 1000, 'Не сохранены 8000 подписок.');
try {
    ProtocolSubscriptions::put(1, 1, md5('connection-1'), $id('ninth'), 'overview.get', []);
    throw new RuntimeException('Разрешена девятая подписка.');
} catch (DomainException $error) { check($error->getMessage() === 'subscription.limit', 'Неверный код лимита.'); }
ProtocolSubscriptions::put(1, 1, md5('connection-1'), $id('3'), 'fleets.get', []);
check(count(ProtocolSubscriptions::all(1, md5('connection-1'))) === 8, 'Замена подписки расходует новый слот.');
ProtocolSubscriptions::remove(md5('connection-1'), $id('4'));
check(count(ProtocolSubscriptions::all(1, md5('connection-1'))) === 7, 'Unsubscribe удаляет не один слот.');
ProtocolSubscriptions::put(1, 1, md5('connection-1'), $id('4'), 'overview.get', []);
for ($slot = 0; $slot < 8; ++$slot) ProtocolSubscriptions::delivered(md5('connection-1'), $id((string)$slot), '123');
check(count(ProtocolSubscriptions::pending()) === 999, 'Доставка не снимает dirty.');
ProtocolSubscriptions::dirty(1, 1, md5('connection-1'));
check(count(ProtocolSubscriptions::pending()) === 1000, 'Не обновляется полный реестр.');
ProtocolSubscriptions::remove(md5('connection-1'));
check(ProtocolSubscriptions::all(1, md5('connection-1')) === [], 'Удаление соединения оставляет подписки.');
for ($fd = 1001; $fd <= 1025; ++$fd) for ($slot = 0; $slot < 8; ++$slot) {
    ProtocolSubscriptions::put($fd, $fd, md5('connection-' . $fd), $id((string)$slot), 'overview.get', []);
}
try {
    ProtocolSubscriptions::put(1026, 1026, md5('connection-1026'), $id('0'), 'overview.get', []);
    throw new RuntimeException('Не работает глобальный лимит подписок.');
} catch (DomainException $error) { check($error->getMessage() === 'subscription.limit', 'Неверный код глобального лимита.'); }
ProtocolSubscriptions::put(2, 2, md5('connection-2'), $id('0'), 'fleets.get', []);
echo 'PROTOCOL_CAPACITY_PROBE_OK ' . json_encode(['connections' => 2048, 'subscriptions' => 8192,
    'projections' => 20000, 'cacheLimit' => 16384, 'elapsedSeconds' => round(microtime(true) - $started, 3)], JSON_THROW_ON_ERROR) . "\n";
