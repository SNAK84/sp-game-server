#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Только текущий QA и только при остановленной основной игре. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};

$options = getopt('', ['prepare', 'restore', 'snapshot:']);
$path = $options['snapshot'] ?? '';
if (isset($options['prepare']) === isset($options['restore']) || !is_string($path)
    || !preg_match('~^/tmp/sp-game-clock-qa\.[a-zA-Z0-9]+/fixture\.json$~D', $path)) {
    throw new InvalidArgumentException('Нужны --prepare/--restore и приватный --snapshot.');
}
Environment::init(__DIR__ . '/../.env');
$socket = @fsockopen('127.0.0.1', Environment::getInt('WS_PORT', 9501), $error, $message, .3);
if (is_resource($socket)) {
    fclose($socket);
    throw new LogicException('Сначала штатно остановите игру.');
}
$db = Database::independent();
$identity = $db->fetchOne("SELECT a.id account_id,u.id user_id,u.main_planet planet_id
    FROM accounts a JOIN users u ON u.account_id=a.id WHERE a.login='qaown_260924'");
if ($identity === null) throw new LogicException('Нет QA.');
$planetId = (int)$identity['planet_id'];
$userId = (int)$identity['user_id'];
$db->beginTransaction();
try {
    $planet = $db->fetchOne('SELECT id,owner_id,galaxy,system,planet,planet_type,deg,update_time,speed,rotation,distance FROM planets WHERE id=? FOR UPDATE', [$planetId]);
    if ($planet === null || (int)$planet['owner_id'] !== $userId) throw new LogicException('Другой владелец.');
    $queues = $db->fetchAll('SELECT * FROM queues WHERE user_id=? ORDER BY id FOR UPDATE', [$userId]);
    if (isset($options['prepare'])) {
        if (file_exists($path)) throw new LogicException('Не перезаписываем before-image.');
        if ((int)$db->fetchOne('SELECT COUNT(*) n FROM fleet_commands c JOIN fleets f ON f.id=c.fleet_id
            WHERE f.owner_id=? AND c.executed=0 AND c.execute_time>0', [$userId])['n'] !== 0) throw new LogicException('У QA есть активный рейс.');
        $lab = array_values(array_filter($queues, static fn(array $q): bool => (int)$q['object_id'] === 31 && $q['type'] === 'builds' && $q['status'] === 'active'));
        $hangar = array_values(array_filter($queues, static fn(array $q): bool => (int)$q['object_id'] === 202 && $q['type'] === 'hangars'));
        if (count($queues) !== 2 || count($lab) !== 1 || count($hangar) !== 1
            || (int)$hangar[0]['count'] !== 3 || (int)$hangar[0]['total_count'] !== 3) throw new LogicException('Нужны штатные QA-лаборатория и партия3 транспорта.');
        $lab = $lab[0]; $hangar = $hangar[0];
        $build = $db->fetchOne('SELECT laboratory FROM builds WHERE id=? FOR UPDATE', [$planetId]);
        $ships = $db->fetchOne('SELECT small_ship_cargo FROM ships WHERE id=? FOR UPDATE', [$planetId]);
        $resources = $db->fetchOne('SELECT metal,crystal,deuterium FROM resources_planet WHERE id=? FOR UPDATE', [$planetId]);
        if ($build === null || $ships === null || $resources === null || (int)$lab['count'] !== (int)$build['laboratory'] + 1) throw new LogicException('QA-задача уже завершилась.');
        $refund = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        foreach ([$lab, $hangar] as $q) {
            $v = $db->fetchOne('SELECT cost901,cost902,cost903,factor FROM vars WHERE elementID=?', [$q['object_id']]);
            $factor = (float)$v['factor'];
            $scale = $q['type'] === 'hangars' ? (int)$q['total_count'] : (($factor === 0.0 || $factor === 1.0) ? 1.0 : pow($factor, (int)$q['count'] - 1));
            foreach (['metal' => 901, 'crystal' => 902, 'deuterium' => 903] as $name => $id) $refund[$name] += (float)$v['cost' . $id] * $scale;
        }
        $restoredResources = [];
        foreach ($resources as $name => $count) $restoredResources[$name] = (float)$count + $refund[$name];
        $fixture = ['identity' => $identity, 'planet' => $planet, 'laboratory' => (int)$build['laboratory'],
            'ships' => (int)$ships['small_ship_cargo'], 'resources' => $resources, 'restoredResources' => $restoredResources,
            'labQueue' => $lab, 'hangarQueue' => $hangar, 'lag' => 600.0,
            'speedPlanets' => (float)$db->fetchOne("SELECT value FROM config WHERE name='SpeedPlanets'")['value']];
        $handle = fopen($path, 'x');
        if ($handle === false) throw new RuntimeException('Не создан before-image.');
        chmod($path, 0600);
        try {
            $json = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Не сохранён before-image.');
        } finally { fclose($handle); }
        // Планету не двигаем: только уже оплаченное событие делаем запоздавшим.
        $end = (float)$planet['update_time'] - $fixture['lag'];
        $db->query('UPDATE queues SET start_time=?,end_time=? WHERE id=? AND user_id=?', [$end - (float)$lab['time'], $end, $lab['id'], $userId]);
    } else {
        $fixture = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if ($fixture['identity'] !== $identity || $queues !== []) throw new LogicException('Другой QA или осталась очередь.');
        $db->query('UPDATE planets SET deg=?,update_time=? WHERE id=? AND owner_id=?',
            [$fixture['planet']['deg'], $fixture['planet']['update_time'], $planetId, $userId]);
        $db->query('UPDATE builds SET laboratory=? WHERE id=?', [$fixture['laboratory'], $planetId]);
        $db->query('UPDATE ships SET small_ship_cargo=? WHERE id=?', [$fixture['ships'], $planetId]);
        $r = $fixture['restoredResources'];
        $db->query('UPDATE resources_planet SET metal=?,crystal=?,deuterium=? WHERE id=?', [$r['metal'], $r['crystal'], $r['deuterium'], $planetId]);
    }
    $db->commit();
    echo json_encode(['ok' => true, 'phase' => isset($options['prepare']) ? 'prepared' : 'restored', 'planetId' => $planetId]) . PHP_EOL;
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
