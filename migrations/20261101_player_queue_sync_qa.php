<?php

declare(strict_types=1);

// B042/B036: два контролируемых pending-события основного dev. Не browser ACK.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};

Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--inspect', '--prepare', '--activate'], true)) throw new LogicException('Нужен --inspect/--prepare/--activate.');
$fleet = $db->fetchOne('SELECT id,name,owner_id,home_planet_id,status FROM fleets WHERE id=122');
if ($fleet === null || (int)$fleet['owner_id'] !== 9 || (int)$fleet['home_planet_id'] !== 26 || (int)$fleet['status'] !== 0
    || ($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') !== 'qaown_260924'
    || ($db->fetchOne('SELECT owner_id FROM planets WHERE id=26')['owner_id'] ?? 0) != 9) {
    throw new LogicException('Другая QA-сцена.');
}
if ($mode !== '--inspect') {
    $socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
    if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите main.'); }
    if ($fleet['name'] !== 'QA B004 source' || $db->fetchOne('SELECT id FROM accounts WHERE id=65535') !== null) {
        throw new LogicException('Fixture не свободен.');
    }
    $db->beginTransaction();
    try {
        $rows = $db->fetchAll('SELECT id,event_data FROM player_queue_restart ORDER BY id FOR UPDATE');
        if ($mode === '--prepare') {
            if ($rows !== []) throw new LogicException('Нельзя перезаписывать pending-события.');
            foreach ([5001 => ['FleetId' => 122, 'Name' => 'QA B042 small'],
                5002 => ['FleetId' => 122, 'Name' => 'QA B042 large', 'padding' => str_repeat('x', 600)]] as $id => $data) {
                $event = ['id' => $id, 'account_id' => 65535, 'user_id' => 9, 'planet_id' => 26,
                    'action' => 'ActionFleetRename', 'priority' => 1,
                    'added_at' => microtime(true) + ($id - 5001) * .001, 'data' => $data,
                    'ready' => 1, 'payload_ttl' => $id === 5002 ? 90.0 : null];
                $db->query('INSERT INTO player_queue_restart(id,event_data) VALUES(?,?)', [
                    $id, json_encode($event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
            }
        } else {
            if (array_map('intval', array_column($rows, 'id')) !== [5001, 5002]) throw new LogicException('Другой/уже исполненный snapshot.');
            foreach ($rows as $row) {
                $event = json_decode($row['event_data'], true, 64, JSON_THROW_ON_ERROR);
                $id = (int)$row['id'];
                if ($event['account_id'] !== 65535 || $event['user_id'] !== 9 || $event['planet_id'] !== 26
                    || $event['action'] !== 'ActionFleetRename' || $event['data']['FleetId'] !== 122
                    || $event['data']['Name'] !== ($id === 5001 ? 'QA B042 small' : 'QA B042 large')
                    || ($id === 5001 && $event['payload_ttl'] !== null)
                    || ($id === 5002 && (!is_numeric($event['payload_ttl']) || $event['payload_ttl'] <= 0 || $event['payload_ttl'] > 90
                        || $event['data']['padding'] !== str_repeat('x', 600)))) {
                    throw new LogicException('Snapshot повреждён или уже активирован.');
                }
                $event['account_id'] = 15;
                $db->query('UPDATE player_queue_restart SET event_data=? WHERE id=?', [
                    json_encode($event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $id]);
            }
        }
        $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
}
$rows = $db->fetchAll('SELECT id,event_data FROM player_queue_restart ORDER BY id');
$out = [];
foreach ($rows as $row) {
    $event = json_decode($row['event_data'], true, 64, JSON_THROW_ON_ERROR);
    $out[] = ['id' => (int)$row['id'], 'accountId' => $event['account_id'],
        'payloadBytes' => strlen(serialize($event['data'])), 'payloadTtl' => $event['payload_ttl']];
}
echo json_encode(['mode' => $mode, 'fleetId' => 122, 'fleetName' => $fleet['name'], 'events' => $out], JSON_THROW_ON_ERROR), PHP_EOL;
