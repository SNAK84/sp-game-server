#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Только квитанции/планета QA при остановленном основном dev. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};

$options = getopt('', ['prepare', 'restore', 'snapshot:']);
$path = $options['snapshot'] ?? '';
if (isset($options['prepare']) === isset($options['restore']) || !is_string($path)
    || !preg_match('~^/tmp/sp-game-receipts-qa\.[a-zA-Z0-9_]+/fixture\.json$~D', $path)) {
    throw new InvalidArgumentException('Нужны --prepare/--restore и приватный --snapshot.');
}
Environment::init(__DIR__ . '/../.env');
$socket = @fsockopen('127.0.0.1', Environment::getInt('WS_PORT', 9501), $error, $message, .3);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите игру.'); }
$db = Database::independent();
$identity = $db->fetchOne("SELECT a.id account_id,u.id player_id,u.main_planet planet_id
    FROM accounts a JOIN users u ON u.account_id=a.id WHERE a.login='qaown_260924'");
if ($identity === null) throw new LogicException('Нет QA.');
$db->beginTransaction();
try {
    $planet = $db->fetchOne('SELECT id,name,owner_id FROM planets WHERE id=? FOR UPDATE', [$identity['planet_id']]);
    if ($planet === null || $planet['owner_id'] !== $identity['player_id']) throw new LogicException('Другой владелец.');
    $pending = $db->fetchAll('SELECT id,event_data FROM player_queue_restart ORDER BY id FOR UPDATE');
    if (isset($options['prepare'])) {
        $input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
        if ($pending !== [] || file_exists($path) || count($input['requests']) !== 4
            || count(array_unique($input['requests'])) !== 4) throw new LogicException('Нужны4 настоящие QA-квитанции и пустой снимок очереди.');
        $receipts = [];
        foreach ($input['requests'] as $id) {
            if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D', $id)) throw new LogicException('Неверный requestId.');
            $row = $db->fetchOne("SELECT * FROM protocol_commands WHERE player_id=? AND request_id=? FOR UPDATE", [$identity['player_id'], $id]);
            if ($row === null || $row['state'] !== 'applied' || $row['operation'] !== 'planet.rename') throw new LogicException('Нужна исполненная QA-команда переименования.');
            $receipts[] = $row;
        }
        $event = json_decode($input['event'], true, 64, JSON_THROW_ON_ERROR);
        $meta = $event['data']['_protocolV2'] ?? [];
        if ($event['account_id'] !== $identity['account_id'] || $event['user_id'] !== $identity['player_id']
            || $event['planet_id'] !== $planet['id'] || $event['action'] !== 'ActionRenamePlanet' || $event['ready'] !== 1
            || $meta !== ['requestId' => $receipts[3]['request_id'], 'claim' => $receipts[3]['claim'], 'name' => 'planet.rename']
            || $event['data']['Name'] !== $planet['name']) throw new LogicException('Нужен настоящий ранее захваченный QA-pending.');
        $fixture = ['identity' => $identity, 'planet' => $planet, 'receipts' => $receipts, 'event' => $input['event'], 'originalName' => $input['originalName']];
        $handle = fopen($path, 'x');
        if ($handle === false) throw new RuntimeException('Не создан before-image.');
        chmod($path, 0600);
        try {
            $json = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Не сохранён before-image.');
        } finally { fclose($handle); }
        $now = microtime(true);
        foreach ($receipts as $index => $row) {
            $db->query("UPDATE protocol_commands SET state='accepted',outcome='{}',created_at=?,expires_at=?,claim=? WHERE world_id=? AND player_id=? AND request_id=?",
                [$now - ($index === 1 ? 87000 : 600), $now + ($index === 1 ? -600 : 3600),
                    $index === 2 ? $receipts[3]['claim'] : $row['claim'], $row['world_id'], $row['player_id'], $row['request_id']]);
        }
        $db->query('UPDATE planets SET name=? WHERE id=?', [$input['originalName'], $planet['id']]);
        $db->query('INSERT INTO player_queue_restart (id,event_data) VALUES (?,?)', [$event['id'], $input['event']]);
    } else {
        $fixture = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if ($fixture['identity'] !== $identity) throw new LogicException('Другой QA.');
        $event = json_decode($fixture['event'], true, 64, JSON_THROW_ON_ERROR);
        foreach ($pending as $row) if ($row['id'] === $event['id']) {
            if ($row['event_data'] !== $fixture['event']) throw new LogicException('Событие заменено.');
            $db->query('DELETE FROM player_queue_restart WHERE id=?', [$row['id']]);
        }
        foreach ($fixture['receipts'] as $row) {
            $fields = array_keys($row);
            $db->query('INSERT INTO protocol_commands (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?'))
                . ') ON DUPLICATE KEY UPDATE ' . implode(',', array_map(static fn(string $f): string => $f . '=VALUES(' . $f . ')', $fields)), array_values($row));
        }
        $db->query('UPDATE planets SET name=? WHERE id=?', [$fixture['originalName'], $planet['id']]);
    }
    $db->commit();
    echo json_encode(['ok' => true, 'phase' => isset($options['prepare']) ? 'prepared' : 'restored']) . PHP_EOL;
} catch (Throwable $error) { $db->rollBack(); throw $error; }
