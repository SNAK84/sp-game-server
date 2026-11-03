<?php

declare(strict_types=1);

// Настоящий журнал dev-БД, но только IDENTITY before=after для QA9/site19.
// Ни один режим не пишет в игровые таблицы и не запускает Swoole-сервер.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\MiningCheckpoints as Journal;
use SPGame\Game\Services\FleetMiningService;

Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$mode = $argv[1] ?? '--inspect';
$siteId = 19; $ownerId = 9; $fleetId = 42;
$purpose = 'qa-identity-checkpoint-20261025';
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
if ($mode === '--hold-lock') {
    Journal::locked($siteId, static function (): void { echo "LOCK_HELD\n"; flush(); usleep(10000000); });
    exit;
}
if ($mode === '--contend') {
    try { Journal::locked($siteId, static function (): void {}); }
    catch (RuntimeException $e) {
        $assert($e->getMessage() === 'Ресурсный объект уже обрабатывается.', 'Неожиданный отказ блокировки');
        echo "LOCK_BUSY: независимый процесс не вошёл\n"; exit;
    }
    throw new RuntimeException('Нет ожидаемого конкурирующего владельца блокировки.');
}
if (!in_array($mode, ['--prepare', '--inspect', '--verify', '--resume'], true)) throw new InvalidArgumentException('Неизвестный режим journal probe.');
if ($mode !== '--prepare') {
    $table = $db->fetchOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['mining_checkpoints']);
    if ((int)$table['n'] === 0) { echo "Журнал ещё не установлен; запись не выполнялась.\n"; exit; }
}
$read = static fn(): array => [
    'site' => $db->fetchOne('SELECT * FROM orbital_resource_sites WHERE id=?', [$siteId]),
    'knowledge' => $db->fetchOne('SELECT * FROM resource_surveys WHERE owner_id=? AND site_id=?', [$ownerId, $siteId]),
    'fleet' => $db->fetchOne('SELECT * FROM fleets WHERE id=?', [$fleetId]),
];
$fingerprint = static fn(array $rows): string => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
if ($mode === '--prepare') Journal::init();
Journal::locked($siteId, static function () use ($db, $mode, $siteId, $ownerId, $fleetId, $purpose, $read, $fingerprint, $assert, $argv): void {
    if ($mode === '--prepare') {
        $assert(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? null) === 'qaown_260924', 'Другой QA-аккаунт');
        $rows = $read();
        $assert(!in_array(null, $rows, true) && (int)$rows['fleet']['owner_id'] === $ownerId
            && (int)$rows['fleet']['status'] === 0 && (int)$rows['fleet']['home_planet_id'] === 26
            && (int)$rows['knowledge']['owner_id'] === $ownerId, 'Исходное QA-состояние изменилось');
        $head = Journal::head($siteId);
        $assert($head === null || $head['phase'] === 'applied'
            && (json_decode($head['metadata'], true)['purpose'] ?? null) === $purpose, 'Есть чужая/незавершённая операция; не изменяем её');
        $entries = [];
        $fields = ['site' => ['metal_stock', 'crystal_stock', 'deuterium_stock', 'resource_update_time'],
            'knowledge' => ['reserve_time', 'state'],
            'fleet' => ['fuel_current', 'resource_metal', 'resource_crystal', 'resource_deuterium', 'current_position_time', 'base_fuel_time']];
        $guards = ['site' => ['site_type', 'galaxy', 'system', 'orbit', 'sector'],
            'knowledge' => ['owner_id', 'site_id', 'coverage'],
            'fleet' => ['owner_id', 'status', 'mission', 'home_planet_id', 'home_site_id', 'current_site_id']];
        foreach ($rows as $kind => $row) {
            $patch = array_intersect_key($row, array_flip($fields[$kind]));
            $entries[] = ['kind' => $kind, 'id' => (int)$row['id'], 'before' => $patch, 'after' => $patch,
                'guard' => array_intersect_key($row, array_flip($guards[$kind]))];
        }
        $token = bin2hex(random_bytes(16));
        $batch = Journal::prepare($siteId, $ownerId, (int)($head['revision'] ?? 0), $token,
            microtime(true), $entries, ['purpose' => $purpose, 'sourceHash' => $fingerprint($rows)]);
        $assert($fingerprint($read()) === $fingerprint($rows), 'Игровые строки изменились во время подготовки');
        echo json_encode(['mode' => 'prepared-no-ack', 'token' => $token, 'head' => $batch['head'],
            'entries' => count($batch['entries']), 'gameRowsUnchanged' => true], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
        return; // Завершаем PHP-процесс, НЕ подтверждая prepared.
    }
    $batch = Journal::load($siteId); $head = $batch['head'];
    $meta = json_decode($head['metadata'], true, 512, JSON_THROW_ON_ERROR);
    $assert(($meta['purpose'] ?? null) === $purpose, 'Это не наш identity-probe');
    if ($mode === '--inspect') {
        echo json_encode(['head' => $head, 'entries' => count($batch['entries']), 'gameRowsUnchanged' => $fingerprint($read()) === $meta['sourceHash']], JSON_UNESCAPED_UNICODE), PHP_EOL;
        return;
    }
    $assert(($argv[2] ?? '') === $head['token'], 'Нужно явно указать token этого probe');
    $rows = $read();
    $assert($fingerprint($rows) === $meta['sourceHash'], 'Исходные игровые строки изменились; не подтверждаем');
    foreach ($batch['entries'] as $entry) {
        $assert($entry['before'] === $entry['after'], 'Probe не применяет ненулевые изменения экономики');
        $assert(FleetMiningService::recoveryPatch($rows[$entry['kind']], $entry) === null, 'Identity не распознан как after');
    }
    if ($mode === '--verify') {
        $revision = (int)$head['revision'] - 1;
        $same = Journal::prepare($siteId, $ownerId, $revision, $head['token'], (float)$head['checkpoint_time'], array_reverse($batch['entries']), $meta);
        $assert($same === $batch, 'Idempotent replay изменил batch');
        $rejected = 0;
        foreach (['changed-token-payload', 'other-operation', 'oversized-metadata'] as $case) {
            try {
                $entries = $batch['entries']; $metadata = $meta; $token = $head['token']; $expected = $revision;
                if ($case === 'changed-token-payload') $entries[0]['after'][array_key_first($entries[0]['after'])] += 1;
                if ($case === 'other-operation') { $token = bin2hex(random_bytes(16)); $expected = $head['phase'] === 'prepared' ? (int)$head['revision'] : $revision; }
                if ($case === 'oversized-metadata') $metadata['padding'] = str_repeat('x', Journal::MAX_METADATA_BYTES);
                Journal::prepare($siteId, $ownerId, $expected, $token, (float)$head['checkpoint_time'], $entries, $metadata);
            } catch (LogicException|OverflowException $expectedError) { ++$rejected; continue; }
            throw new RuntimeException('Не отклонён случай ' . $case);
        }
        $assert($rejected === 3 && Journal::load($siteId) === $batch && $fingerprint($read()) === $meta['sourceHash'], 'Отказ изменил журнал/экономику');
        echo json_encode(['replay' => 'same batch', 'negativeCasesRejected' => $rejected, 'phase' => $head['phase'], 'gameRowsUnchanged' => true]), PHP_EOL;
        return;
    }
    $result = Journal::acknowledge($siteId, $head['token']);
    $assert($result['head']['phase'] === 'applied' && $fingerprint($read()) === $meta['sourceHash'], 'Не подтверждён identity checkpoint');
    echo json_encode(['token' => $head['token'], 'phase' => $result['head']['phase'], 'entries' => count($result['entries']),
        'gameRowsUnchanged' => true, 'gameWrites' => 0]), PHP_EOL;
});
