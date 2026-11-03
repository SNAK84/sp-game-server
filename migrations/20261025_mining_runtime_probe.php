<?php

declare(strict_types=1);

/**
 * Контролируемый dev-probe: только QA9/Fleet42/site19, с разрешения владельца.
 * Не создаёт второго runtime. --prepare пишет ТОЛЬКО журнал; основной сервер
 * сам применяет эффекты. --prepare-partial допускается лишь при остановленном
 * сервере и создаёт явно обозначенный durable crash-fixture одной строки.
 */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\{MiningCheckpoints as Journal, OrbitalResourceSites as Sites,
    ResourceSurveys as Knowledge, Fleets, BaseRepository};
use SPGame\Game\Services\FleetMiningService;

Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance();
$mode = $argv[1] ?? '--inspect';
if (!in_array($mode, ['--inspect', '--prepare', '--prepare-partial', '--verify'], true)) {
    throw new InvalidArgumentException('Неизвестный режим runtime-probe.');
}
$siteId = 19; $ownerId = 9; $fleetId = 42;
$purpose = 'qa-mining-runtime-20261025';
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$read = static function () use ($db, $siteId, $ownerId, $fleetId): array {
    // Ресурсные колонки Fleets добавляются в init из Vars. Здесь init НЕ
    // вызывается: второй runtime не создаём, приводим только нужный SQL-показатель.
    $fleet = $db->fetchOne('SELECT * FROM fleets WHERE id=?', [$fleetId]) ?? [];
    return [
    'site' => Sites::castRowToSchema($db->fetchOne('SELECT * FROM orbital_resource_sites WHERE id=?', [$siteId]) ?? []),
    'knowledge' => Knowledge::castRowToSchema($db->fetchOne('SELECT * FROM resource_surveys WHERE owner_id=? AND site_id=?', [$ownerId, $siteId]) ?? []),
    'fleet' => Fleets::castRowToSchema($fleet) + ['resource_metal' => (float)($fleet['resource_metal'] ?? 0)],
    ];
};
Journal::worldLocked(static fn() => Journal::locked($siteId, static function () use ($mode, $db, $siteId,
    $ownerId, $fleetId, $purpose, $read, $assert, $argv): void {
    $rows = $read(); $head = Journal::head($siteId);
    $assert(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924', 'Другой QA-аккаунт.');
    $assert(($rows['fleet']['owner_id'] ?? 0) === $ownerId && ($rows['knowledge']['owner_id'] ?? 0) === $ownerId, 'Другой владелец.');
    if ($mode === '--prepare' || $mode === '--prepare-partial') {
        $partial = $mode === '--prepare-partial';
        if ($partial) {
            $socket = @fsockopen('127.0.0.1', Environment::getInt('WS_PORT', 9501), $errno, $error, 1);
            if ($socket !== false) { fclose($socket); throw new LogicException('Crash-fixture требует остановленного основного сервера.'); }
        }
        $assert(Journal::pending() === [], 'Есть незавершённый checkpoint.');
        $assert($head !== null && $head['phase'] === 'applied'
            && in_array(json_decode($head['metadata'], true)['purpose'] ?? '', [$purpose, 'qa-identity-checkpoint-20261025'], true), 'Чужая операция.');
        $assert($rows['fleet']['status'] === 0 && $rows['fleet']['home_planet_id'] === 26
            && $rows['fleet']['current_site_id'] === 0, 'QA-флот уже в рейсе; не меняем его.');
        $quantity = $partial ? 6.25 : 12.5;
        $personal = json_decode($rows['knowledge']['state'], true, 512, JSON_THROW_ON_ERROR);
        $assert($rows['site']['metal_stock'] >= $quantity && $personal['reserve'][901] >= $quantity, 'Недостаточно тестового запаса.');
        $personal['reserve'][901] -= $quantity;
        $after = [
            'site' => ['metal_stock' => round($rows['site']['metal_stock'] - $quantity, 6)],
            'knowledge' => ['state' => json_encode($personal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)],
            'fleet' => ['resource_metal' => $rows['fleet']['resource_metal'] + $quantity, 'fuel_current' => $rows['fleet']['fuel_current']],
        ];
        $guardFields = ['site' => ['site_type', 'galaxy', 'system', 'orbit', 'sector', 'resource_update_time'],
            'knowledge' => ['owner_id', 'site_id', 'coverage', 'reserve_time'],
            'fleet' => ['owner_id', 'status', 'mission', 'home_planet_id', 'home_site_id', 'current_site_id']];
        $entries = [];
        foreach ($after as $kind => $patch) {
            $repository = ['site' => Sites::class, 'knowledge' => Knowledge::class, 'fleet' => Fleets::class][$kind];
            $before = array_intersect_key($rows[$kind], $patch);
            $guard = array_intersect_key($rows[$kind], array_flip($guardFields[$kind]));
            foreach ([$before, $patch, $guard] as $values) {
                if ($kind === 'fleet' && array_key_exists('resource_metal', $values)) {
                    BaseRepository::assertCheckpointNumber($values['resource_metal'], 'DOUBLE');
                    unset($values['resource_metal']);
                }
                $repository::assertCheckpointValues($values);
            }
            $entries[] = ['kind' => $kind, 'id' => $rows[$kind]['id'], 'before' => $before, 'after' => $patch, 'guard' => $guard];
        }
        $token = bin2hex(random_bytes(16));
        Journal::prepare($siteId, $ownerId, (int)$head['revision'], $token, microtime(true), $entries,
            ['purpose' => $purpose, 'quantity' => $quantity, 'partialFixture' => $partial, 'fuelSpent' => 0]);
        if ($partial) {
            // Только остановленный dev-сервер: имитируем первую сохранённую
            // сущность, остальные остаются before. Не живой SQL-обход runtime.
            $db->query('UPDATE orbital_resource_sites SET metal_stock=? WHERE id=? AND metal_stock=?',
                [json_encode($after['site']['metal_stock'], JSON_PRESERVE_ZERO_FRACTION), $siteId,
                    json_encode($rows['site']['metal_stock'], JSON_PRESERVE_ZERO_FRACTION)]);
            $assert(FleetMiningService::recoveryPatch($read()['site'], $entries[0]) === null, 'Не создан partial fixture.');
        }
        echo json_encode(['token' => $token, 'phase' => 'prepared', 'quantity' => $quantity,
            'durableAfter' => $partial ? ['site'] : [], 'gameWrites' => $partial ? 1 : 0, 'fuelSpent' => 0]), PHP_EOL;
        return;
    }
    if ($head === null) { echo "Нет checkpoint.\n"; return; }
    $batch = Journal::load($siteId);
    $meta = json_decode($head['metadata'], true, 512, JSON_THROW_ON_ERROR);
    if ($mode === '--verify') {
        $assert(($argv[2] ?? '') === $head['token'] && ($meta['purpose'] ?? '') === $purpose, 'Укажите token именно этого runtime-probe.');
        $assert($head['phase'] === 'applied', 'Операция ещё не завершена сервером.');
        foreach ($batch['entries'] as $entry) $assert(FleetMiningService::recoveryPatch($rows[$entry['kind']], $entry) === null, 'Не сохранён after.');
    }
    echo json_encode(['phase' => $head['phase'], 'revision' => $head['revision'], 'token' => $head['token'],
        'quantity' => $meta['quantity'] ?? null, 'partialFixture' => $meta['partialFixture'] ?? null,
        'fleetMetal' => $rows['fleet']['resource_metal'], 'siteMetal' => $rows['site']['metal_stock'],
        'reserveMetal' => json_decode($rows['knowledge']['state'], true)['reserve'][901],
        'fuel' => $rows['fleet']['fuel_current'], 'pending' => Journal::pending()]), PHP_EOL;
}), in_array($mode, ['--prepare', '--prepare-partial'], true));
