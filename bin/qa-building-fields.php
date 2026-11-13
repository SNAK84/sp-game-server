#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Подготовка/восстановление только qaown_260924 при остановленной основной игре. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};

$options = getopt('', ['prepare', 'restore', 'snapshot:']);
if (isset($options['prepare']) === isset($options['restore'])
    || !is_string($options['snapshot'] ?? null)
    || !preg_match('~^/tmp/sp-game-fields-qa\.[a-zA-Z0-9]+/fixture\.json$~D', $options['snapshot'])) {
    throw new InvalidArgumentException('--prepare или --restore, --snapshot=/tmp/sp-game-fields-qa.XXXXXX/fixture.json');
}
Environment::init(__DIR__ . '/../.env');
$socket = @fsockopen('127.0.0.1', Environment::getInt('WS_PORT', 9501), $error, $message, .3);
if (is_resource($socket)) {
    fclose($socket);
    throw new LogicException('Сначала штатно остановите основную игру.');
}
$db = Database::independent();
$identity = $db->fetchOne("SELECT a.id account_id,u.id user_id,u.main_planet planet_id
    FROM accounts a JOIN users u ON u.account_id=a.id WHERE a.login='qaown_260924'");
if ($identity === null) throw new LogicException('Нет QA-игрока.');
$planetId = (int)$identity['planet_id'];
$userId = (int)$identity['user_id'];
if ((int)$db->fetchOne('SELECT COUNT(*) n FROM queues WHERE user_id=?', [$userId])['n'] !== 0) {
    throw new LogicException('Сначала завершите/отмените только тестовую очередь QA.');
}
$db->beginTransaction();
try {
    $planet = $db->fetchOne('SELECT id,owner_id,planet_type,fields FROM planets WHERE id=? FOR UPDATE', [$planetId]);
    $builds = $db->fetchOne('SELECT * FROM builds WHERE id=? FOR UPDATE', [$planetId]);
    $resources = $db->fetchOne('SELECT * FROM resources_planet WHERE id=? FOR UPDATE', [$planetId]);
    $techs = $db->fetchOne('SELECT * FROM techs WHERE id=? FOR UPDATE', [$userId]);
    if ($planet === null || (int)$planet['owner_id'] !== $userId || (int)$planet['planet_type'] !== 1
        || $builds === null || $resources === null || $techs === null) throw new LogicException('Неверная QA-база.');
    $names = [];
    foreach ($db->fetchAll('SELECT elementID,name FROM vars WHERE elementID IN (1,4,14,15,33,113)') as $row) {
        if (!preg_match('/^[a-z_]+$/D', $row['name'])) throw new LogicException('Некорректная колонка vars.');
        $names[(int)$row['elementID']] = $row['name'];
    }
    if (count($names) !== 6) throw new LogicException('Неполный набор vars.');
    $path = $options['snapshot'];
    if (isset($options['prepare'])) {
        if (file_exists($path)) throw new LogicException('Не перезаписываем before-image.');
        $beforeBuilds = [];
        $preparedBuilds = [];
        foreach ([1 => 25, 4 => 50, 14 => 0, 15 => 12, 33 => 5] as $id => $level) {
            $beforeBuilds[$names[$id]] = $builds[$names[$id]];
            $preparedBuilds[$names[$id]] = $level;
        }
        $factor = (int)$db->fetchOne("SELECT value FROM config WHERE name='FieldsByTerraformer'")['value'];
        $moonFactor = (int)$db->fetchOne("SELECT value FROM config WHERE name='FieldsByMoonBasis'")['value'];
        $projected = array_replace($builds, $preparedBuilds);
        unset($projected['id']);
        $used = (int)array_sum($projected);
        $fields = $used + 1 - 5 * $factor;
        if ($factor <= 1 || $fields < 1) throw new LogicException('Неожиданный баланс полей.');
        $snapshot = ['identity' => $identity, 'before' => ['fields' => $planet['fields'], 'builds' => $beforeBuilds,
            'resources' => $resources, 'techs' => [$names[113] => $techs[$names[113]]]],
            'fixture' => ['fields' => $fields, 'used' => $used, 'max' => $used + 1,
                'terraformerFields' => $factor, 'moonFields' => $moonFactor,
                'builds' => $preparedBuilds, 'techs' => [$names[113] => 12]], 'createdAt' => gmdate('c')];
        $handle = fopen($path, 'x');
        if ($handle === false) throw new RuntimeException('Не создан before-image.');
        chmod($path, 0600);
        try {
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Не записан before-image.');
        } finally { fclose($handle); }
        $target = ['fields' => $fields, 'builds' => $preparedBuilds,
            'resources' => ['metal' => 80000000, 'crystal' => 80000000, 'deuterium' => 80000000],
            'techs' => $snapshot['fixture']['techs']];
    } else {
        $snapshot = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if ($snapshot['identity'] !== $identity) throw new LogicException('Другой владелец before-image.');
        $target = $snapshot['before'];
    }
    $db->query('UPDATE planets SET fields=? WHERE id=? AND owner_id=?', [$target['fields'], $planetId, $userId]);
    foreach (['builds' => $planetId, 'resources_planet' => $planetId, 'techs' => $userId] as $table => $id) {
        $values = $target[$table === 'resources_planet' ? 'resources' : $table];
        unset($values['id']);
        $columns = array_keys($values);
        foreach ($columns as $column) if (!preg_match('/^[a-z_]+$/D', $column)) throw new LogicException('Неверная колонка before-image.');
        $db->query('UPDATE `' . $table . '` SET ' . implode(',', array_map(static fn(string $c): string => '`' . $c . '`=?', $columns)) . ' WHERE id=?', [...array_values($values), $id]);
    }
    $db->commit();
    echo json_encode(['ok' => true, 'phase' => isset($options['prepare']) ? 'prepared' : 'restored',
        'planetId' => $planetId, 'snapshot' => $path], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
