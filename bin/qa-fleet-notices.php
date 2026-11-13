#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Только параметры трёх уже остановленных QA-команд, при остановленном dev. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};

$options = getopt('', ['prepare', 'restore', 'snapshot:']);
$path = $options['snapshot'] ?? '';
if (isset($options['prepare']) === isset($options['restore']) || !is_string($path)
    || !preg_match('~^/tmp/sp-game-notices-qa\.[a-zA-Z0-9_]+/fixture\.json$~D', $path)) throw new InvalidArgumentException('Нужны --prepare/--restore и приватный --snapshot.');
Environment::init(__DIR__ . '/../.env');
$socket = @fsockopen('127.0.0.1', Environment::getInt('WS_PORT', 9501), $error, $message, .3);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите игру.'); }
$db = Database::independent();
$owner = $db->fetchOne("SELECT u.id FROM users u JOIN accounts a ON a.id=u.account_id WHERE a.login='qaown_260924'");
if ($owner === null) throw new LogicException('Нет QA.');
$db->beginTransaction();
try {
    if (isset($options['prepare'])) {
        if (file_exists($path)) throw new LogicException('Не перезаписываем before-image.');
        $rows = $db->fetchAll('SELECT c.* FROM fleet_commands c JOIN fleets f ON f.id=c.fleet_id
            WHERE f.owner_id=? AND f.status=1 AND c.executed=0 AND c.execute_time=0 AND c.command_type=10
            AND c.id=(SELECT MIN(n.id) FROM fleet_commands n WHERE n.fleet_id=c.fleet_id AND n.executed=0 AND n.execute_time=0)
            AND NOT EXISTS(SELECT 1 FROM fleet_commands a WHERE a.fleet_id=c.fleet_id AND a.executed=0 AND a.execute_time>0)
            ORDER BY c.id FOR UPDATE', [$owner['id']]);
        $commands = [];
        foreach ($rows as $row) {
            $params = json_decode($row['params'], true, 64, JSON_THROW_ON_ERROR);
            $role = ($params['pause'] ?? null) === 'fuel' && !isset($commands['fuel']) ? 'fuel'
                : (($params['pause'] ?? null) === 'manual' ? (!isset($commands['load']) ? 'load' : (!isset($commands['intercept']) ? 'intercept' : null)) : null);
            if ($role === null) continue;
            $prefix = $role === 'intercept' ? 'interception' : $role;
            $episode = max((int)($params[$prefix . 'Episode'] ?? 0) + 1, (int)floor(microtime(true) * 1000));
            $params['pause'] = $role === 'intercept' ? 'target' : $role;
            $params[$prefix . 'Episode'] = $episode;
            $marker = $role === 'intercept' ? 'interceptNotified' : $role . 'Notified';
            unset($params[$marker]);
            if ($role !== 'fuel') $params['planError'] = 'QA indexed notice ' . $role;
            if ($role === 'intercept') $params['interceptionStop'] = 'contact_lost';
            $prepared = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (strlen($prepared) > 1900) throw new LogicException('Нет места для marker.');
            $commands[$role] = ['row' => $row, 'prepared' => $prepared, 'marker' => $marker, 'episode' => $row['id'] . ':' . $episode];
        }
        if (count($commands) !== 3) throw new LogicException('Нужны fuel и две manual QA-остановки MOVE.');
        $fixture = ['owner' => $owner['id'], 'commands' => $commands];
        $handle = fopen($path, 'x');
        if ($handle === false) throw new RuntimeException('Не создан before-image.');
        chmod($path, 0600);
        try {
            $json = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Не сохранён before-image.');
        } finally { fclose($handle); }
        foreach ($commands as $item) $db->query('UPDATE fleet_commands SET params=? WHERE id=?', [$item['prepared'], $item['row']['id']]);
    } else {
        $fixture = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if ($fixture['owner'] !== $owner['id']) throw new LogicException('Другой QA.');
        foreach ($fixture['commands'] as $item) {
            $row = $db->fetchOne('SELECT c.* FROM fleet_commands c JOIN fleets f ON f.id=c.fleet_id WHERE c.id=? AND f.owner_id=? FOR UPDATE', [$item['row']['id'], $owner['id']]);
            if ($row === $item['row']) continue;
            if ($row === null || array_diff_assoc(array_diff_key($row, ['params' => 1]), array_diff_key($item['row'], ['params' => 1])) !== []) throw new LogicException('Команда была изменена помимо marker.');
            $actual = json_decode($row['params'], true, 64, JSON_THROW_ON_ERROR);
            $prepared = json_decode($item['prepared'], true, 64, JSON_THROW_ON_ERROR);
            unset($actual[$item['marker']], $prepared[$item['marker']]);
            if ($actual !== $prepared) throw new LogicException('Параметры изменены помимо marker.');
            $db->query('UPDATE fleet_commands SET params=? WHERE id=?', [$item['row']['params'], $row['id']]);
        }
    }
    $db->commit();
    echo json_encode(['ok' => true, 'phase' => isset($options['prepare']) ? 'prepared' : 'restored']) . PHP_EOL;
} catch (Throwable $error) { $db->rollBack(); throw $error; }
