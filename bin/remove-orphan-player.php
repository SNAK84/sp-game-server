#!/usr/bin/env php
<?php
declare(strict_types=1);

/** B046: разовое удаление только подтверждённого Player9, не общий API удаления. */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};

$options = getopt('', ['apply', 'backup:']);
$apply = isset($options['apply']);
Environment::init(__DIR__ . '/../.env');
$socket = @fsockopen('127.0.0.1', Environment::getInt('WS_PORT', 9501), $error, $message, .3);
if (is_resource($socket)) {
    fclose($socket);
    throw new LogicException('Сначала штатно остановите основную игру; иначе RAM восстановит удалённые строки.');
}
$db = Database::independent();
// Явный закрытый набор: новые зависимости запрещают удаление, а не расширяют его.
$targets = [
    'queues' => ['id IN (3472,3473) AND user_id=2 AND planet_id=12', 2],
    'entity_settings' => ['planet_id=12', 7],
    'builds' => ['id=12', 1], 'ships' => ['id=12', 1], 'defenses' => ['id=12', 1],
    'resources_planet' => ['id=12', 1], 'techs' => ['id=2', 1],
    'resources_user' => ['id=2', 1], 'planets' => ['id=12 AND owner_id=2', 1],
    'users' => ["id=2 AND account_id=9 AND name='Player9' AND main_planet=12 AND current_planet=12", 1],
];
$db->beginTransaction();
try {
    $user = $db->fetchOne('SELECT * FROM users WHERE id=2 FOR UPDATE');
    $planet = $db->fetchOne('SELECT * FROM planets WHERE id=12 FOR UPDATE');
    if ($user === null || $planet === null || $db->fetchOne('SELECT id FROM accounts WHERE id=9') !== null
        || (int)$planet['owner_id'] !== 2 || (int)$planet['galaxy'] !== 1
        || (int)$planet['system'] !== 1 || (int)$planet['planet'] !== 4 || (int)$planet['planet_type'] !== 1) {
        throw new LogicException('B046 больше не соответствует согласованному набору.');
    }
    $guards = [
        'planets' => 'owner_id=2 AND id<>12 OR galaxy=1 AND system=1 AND planet=4 AND id<>12',
        'users' => 'id<>2 AND (account_id=9 OR main_planet=12 OR current_planet=12)',
        'queues' => '(user_id=2 OR planet_id=12) AND NOT (id IN (3472,3473) AND user_id=2 AND planet_id=12)',
        'fleets' => 'owner_id=2 OR home_planet_id=12 OR fallback_planet_id=12'
            . ' OR current_galaxy=1 AND current_system=1 AND current_orbit=4'
            . ' OR target_galaxy=1 AND target_system=1 AND target_orbit=4',
        'fleets_ships' => 'owner_id=2', 'fleet_commands' => 'target_id=12',
        'friends' => 'user_low=2 OR user_high=2 OR requester_id=2',
        'messages' => 'to_id=2 OR from_id=2', 'resource_bases' => 'owner_id=2',
        'resource_surveys' => 'owner_id=2', 'mining_checkpoints' => 'owner_id=2',
        'planet_battles' => 'id=12', 'battle_report_saves' => 'user_id=2',
        'fleet_attack_groups' => 'owner_id=2 OR target_id=12', 'protocol_commands' => 'player_id=2',
    ];
    foreach ($guards as $table => $where) {
        if ((int)$db->fetchOne("SELECT COUNT(*) n FROM `$table` WHERE $where")['n'] !== 0) {
            throw new LogicException('Требуется отдельная проверка зависимости: ' . $table);
        }
    }
    $checkReferences = static function (mixed $value, string $path = '') use (&$checkReferences): void {
        if (is_array($value)) {
            foreach ($value as $key => $part) $checkReferences($part, $path . '.' . $key);
        } elseif (is_scalar($value) && (
            preg_match('/\.(user_?id|owner_?id|player_?id)$/i', $path) && (string)$value === '2'
            || preg_match('/\.account_?id$/i', $path) && (string)$value === '9'
            || preg_match('/\.(planet_?id|homePlanetId|fallbackPlanetId|sourcePlanetId|destinationPlanetId)$/i', $path) && (string)$value === '12'
            || preg_match('/\.planet\.id$/', $path) && (string)$value === '12'
        )) {
            throw new LogicException('Обнаружена дополнительная JSON-зависимость: ' . $path);
        }
    };
    foreach (['fleet_commands' => 'params', 'planet_battles' => 'state', 'space_battles' => 'state',
        'survey_results' => 'state', 'mining_checkpoint_rows' => 'payload',
        'mining_checkpoints' => 'metadata', 'player_queue_restart' => 'event_data'] as $table => $column) {
        foreach ($db->fetchAll("SELECT `$column` FROM `$table`") as $row) {
            $checkReferences(json_decode($row[$column], true, 512, JSON_THROW_ON_ERROR));
        }
    }
    $orbit = $db->fetchOne('SELECT * FROM galaxy_orbits WHERE galaxy=1 AND system=1 AND orbit=4 FOR UPDATE');
    // Проверенные значения текущего GalaxyOrbits: PLANET=5, EMPTY=0. Swoole в CLI не запускаем.
    if ($orbit === null || (int)$orbit['id'] !== 4 || (int)$orbit['type'] !== 5
        || (int)$orbit['distance'] !== (int)$planet['distance']) throw new LogicException('Изменилась орбита B046.');
    $before = [];
    foreach ($targets as $table => [$where, $expected]) {
        $before[$table] = $db->fetchAll("SELECT * FROM `$table` WHERE $where FOR UPDATE");
        if (count($before[$table]) !== $expected) throw new LogicException('Изменился набор строк: ' . $table);
    }
    // Хеши всех остальных строк: ни одна чужая запись не должна измениться в транзакции.
    // Полные чужие данные/credentials не выводятся и не попадают в before-image.
    $protectedState = static function () use ($db, $targets): array {
        $state = [];
        foreach ($db->fetchAll('SHOW TABLES') as $entry) {
            $table = (string)array_values($entry)[0];
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new LogicException('Неверное имя таблицы.');
            $where = isset($targets[$table]) ? ' WHERE NOT (' . $targets[$table][0] . ')' : '';
            $hashes = [];
            foreach ($db->fetchAll("SELECT * FROM `$table`$where") as $row) {
                if ($table === 'galaxy_orbits' && (int)$row['id'] === 4) $row['type'] = 0;
                $hashes[] = hash('sha256', serialize($row));
            }
            sort($hashes, SORT_STRING);
            $state[$table] = ['count' => count($hashes), 'sha256' => hash('sha256', implode('', $hashes))];
        }
        ksort($state);
        return $state;
    };
    $protected = $protectedState();
    $summary = ['userId' => 2, 'missingAccountId' => 9, 'planetId' => 12, 'coordinate' => '[1:1:4]',
        'rows' => array_map('count', $before), 'orbitFreed' => true];
    if (!$apply) {
        $db->rollBack();
        echo json_encode(['dryRun' => true, ...$summary], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $path = $options['backup'] ?? null;
    $parent = is_string($path) ? realpath(dirname($path)) : false;
    $privateRoot = realpath(__DIR__ . '/../.secrets');
    if (!is_string($path) || $parent === false || $privateRoot === false
        || !str_starts_with($parent, $privateRoot . DIRECTORY_SEPARATOR)
        || (fileperms($parent) & 0077) !== 0 || basename($path) !== 'before.json'
        || $path !== $parent . '/before.json') throw new LogicException('Нужен новый private .secrets/*/before.json.');
    $backup = ['version' => 1, 'bug' => 'B046', 'createdAt' => gmdate('c'),
        'database' => $db->fetchOne('SELECT DATABASE() name')['name'],
        'rows' => $before, 'orbitBefore' => $orbit, 'protected' => $protected];
    $oldMask = umask(0077);
    try { $handle = fopen($path, 'x'); }
    finally { umask($oldMask); }
    if ($handle === false) throw new RuntimeException('Не создан новый before-image.');
    try {
        $json = json_encode($backup, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
            throw new RuntimeException('Before-image не записан полностью.');
        }
    } finally { fclose($handle); }
    foreach ($targets as $table => [$where, $expected]) {
        if ($db->executeAffected("DELETE FROM `$table` WHERE $where") !== $expected) {
            throw new RuntimeException('Не совпало количество удаляемых строк: ' . $table);
        }
    }
    if ($db->executeAffected('UPDATE galaxy_orbits SET type=0 WHERE id=4 AND type=5') !== 1) {
        throw new RuntimeException('Не освобождена исходная орбита.');
    }
    if ($protectedState() !== $protected) throw new RuntimeException('Изменены данные вне согласованного набора.');
    $db->commit();
    echo json_encode(['ok' => true, ...$summary, 'otherRowsUnchanged' => true,
        'backup' => $path], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    $db->rollBack();
    throw $error;
}
