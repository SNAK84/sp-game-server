#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Узкая подготовка двух QA на основном dev, только при остановленном мире. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};

$options = getopt('', ['prepare', 'restore', 'snapshot:']);
$path = $options['snapshot'] ?? '';
if (isset($options['prepare']) === isset($options['restore']) || !is_string($path)
    || !preg_match('~^/tmp/sp-game-sensor-cache-qa\.[a-zA-Z0-9_-]+/fixture\.json$~D', $path)) {
    throw new InvalidArgumentException('Нужны --prepare/--restore и приватный --snapshot.');
}
Environment::init(__DIR__ . '/../.env');
$socket = @fsockopen('127.0.0.1', Environment::getInt('WS_PORT', 9501), $error, $message, .3);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала штатно остановите основной сервер.'); }
$db = Database::independent();
$db->beginTransaction();
try {
    $identities = $db->fetchAll("SELECT a.login,u.id user_id,u.main_planet planet_id FROM accounts a
        JOIN users u ON u.account_id=a.id WHERE a.login IN ('qaown_260924','qafor_260924') ORDER BY a.login");
    if (count($identities) !== 2) throw new LogicException('Нет двух QA.');
    $rows = [];
    foreach ($identities as $identity) {
        $tech = $db->fetchOne('SELECT sensor_tech FROM techs WHERE id=? FOR UPDATE', [$identity['user_id']]);
        if ($tech === null) throw new LogicException('Нет исследований QA.');
        $rows[] = $identity + ['sensor' => (int)$tech['sensor_tech']];
    }
    $foreign = $rows[0];
    if ($foreign['login'] !== 'qafor_260924') throw new LogicException('Другой QA.');
    $ships = $db->fetchOne('SELECT sensor_scout FROM ships WHERE id=? FOR UPDATE', [$foreign['planet_id']]);
    if ($ships === null) throw new LogicException('Нет ангара QA.');
    if (isset($options['prepare'])) {
        if (file_exists($path)) throw new LogicException('Не перезаписываем before-image.');
        $sensor = (int)$db->fetchOne('SELECT MAX(spy_tech) spy FROM techs')['spy'] + 4;
        foreach ($rows as &$row) $row['prepared'] = max($row['sensor'], $sensor);
        unset($row);
        $fixture = ['qa' => $rows, 'scouts' => (int)$ships['sensor_scout']];
        $handle = fopen($path, 'x');
        if ($handle === false) throw new RuntimeException('Не создан before-image.');
        chmod($path, 0600);
        try {
            $json = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Не сохранён before-image.');
        } finally { fclose($handle); }
        foreach ($rows as $row) $db->query('UPDATE techs SET sensor_tech=? WHERE id=?', [$row['prepared'], $row['user_id']]);
        $db->query('UPDATE ships SET sensor_scout=? WHERE id=?', [$fixture['scouts'] + 1, $foreign['planet_id']]);
    } else {
        $fixture = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (array_column($fixture['qa'], 'login') !== array_column($rows, 'login')
            || array_column($fixture['qa'], 'user_id') !== array_column($rows, 'user_id')
            || array_column($fixture['qa'], 'planet_id') !== array_column($rows, 'planet_id')
            || array_column($fixture['qa'], 'prepared') !== array_column($rows, 'sensor')
            || (int)$ships['sensor_scout'] !== $fixture['scouts'] + 1) throw new LogicException('QA изменён или разведчик ещё не вернулся в ангар.');
        foreach ($fixture['qa'] as $row) $db->query('UPDATE techs SET sensor_tech=? WHERE id=?', [$row['sensor'], $row['user_id']]);
        $db->query('UPDATE ships SET sensor_scout=? WHERE id=?', [$fixture['scouts'], $foreign['planet_id']]);
    }
    $db->commit();
    echo json_encode(['ok' => true, 'phase' => isset($options['prepare']) ? 'prepared' : 'restored']) . PHP_EOL;
} catch (Throwable $error) { $db->rollBack(); throw $error; }
