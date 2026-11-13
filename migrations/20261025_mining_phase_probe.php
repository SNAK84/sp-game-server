<?php

declare(strict_types=1);

/**
 * Только разрешённый QA9/Fleet42/site19. Второго runtime нет.
 * --prepare: raw fuel/clock/cargo из актуального WS по stdin, остальные строки
 * из SQL после штатного saver. Пишет только journal, эффект применяет main.
 * Это fixture фазы, НЕ легальная игровая добыча транспортом/разведчиком.
 */
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\{MiningCheckpoints as Journal, OrbitalResourceSites as Sites,
    ResourceSurveys as Knowledge, Fleets, FleetCommands};
use SPGame\Game\Services\{FleetMiningPhaseService, FleetMiningService};

Environment::init(__DIR__ . '/../.env');
$db = Database::getInstance(); $mode = $argv[1] ?? '--inspect';
if (!in_array($mode, ['--inspect', '--prepare', '--close', '--verify'], true)) throw new InvalidArgumentException('Неизвестный режим фазы.');
$assert = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$input = in_array($mode, ['--prepare', '--close'], true) ? json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR) : [];
Journal::worldLocked(static fn() => Journal::locked(19, static function () use ($db, $mode, $input, $assert, $argv): void {
    $assert(($db->fetchOne('SELECT login FROM accounts WHERE id=15')['login'] ?? '') === 'qaown_260924', 'Другой аккаунт.');
    $raw = $db->fetchOne('SELECT * FROM fleets WHERE id=42');
    $assert($raw !== null && (int)$raw['owner_id'] === 9, 'Нет QA-флота.');
    $fleet = Fleets::castRowToSchema($raw);
    foreach (['metal', 'crystal', 'deuterium'] as $name) $fleet['resource_' . $name] = (float)$raw['resource_' . $name];
    $site = Sites::castRowToSchema($db->fetchOne('SELECT * FROM orbital_resource_sites WHERE id=19'));
    $known = Knowledge::castRowToSchema($db->fetchOne('SELECT * FROM resource_surveys WHERE owner_id=9 AND site_id=19'));
    $previous = Journal::load(19); $head = $previous['head'];
    $meta = json_decode($head['metadata'], true, 512, JSON_THROW_ON_ERROR);
    if (in_array($mode, ['--prepare', '--close'], true)) {
        $assert(Journal::pending() === [], 'Есть prepared, сначала восстановить.');
        $assert(in_array($meta['purpose'] ?? '', ['qa-mining-runtime-20261025', 'qa-mining-phase-20261025', 'qa-mining-close-20261025'], true), 'Другой probe.');
        $command = $db->fetchOne('SELECT * FROM fleet_commands WHERE fleet_id=42 AND executed=0 ORDER BY id LIMIT 1');
        $assert($command !== null, 'Нет приостановленной команды QA-рейса.');
        $command = FleetCommands::castRowToSchema($command);
        $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
        $assert(isset($params['pause']) && $command['execute_time'] === 0.0, 'Рейс не остановлен/ещё не сохранён.');
        $assert(($input['fleetId'] ?? null) === 42 && is_array($input['row'] ?? null), 'Нужен свежий raw WS-снимок.');
        foreach (['fuel_current', 'current_position_time', 'resource_metal', 'resource_crystal', 'resource_deuterium'] as $field) {
            $value = $input['row'][$field] ?? null;
            $assert((is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0, 'Неверный raw field.');
            // Прежняя SQL-строка может отличаться ТОЛЬКО известной точностью поля.
            $storedValue = $field === 'current_position_time' ? (float)sprintf('%.6F', $value) : (float)$value;
            $assert($storedValue === (float)$fleet[$field], 'SQL ещё не соответствует raw WS: ' . $field);
            $fleet[$field] = (float)$value;
        }
        if ($mode === '--close') {
            $assert(strtolower($db->fetchOne("SHOW COLUMNS FROM fleets LIKE 'fuel_current'")['Type']) === 'double', 'Сначала штатный restart для расширения точности бака.');
            $plan = FleetMiningPhaseService::close($previous, ['site' => [19 => $site],
                'knowledge' => [$known['id'] => $known], 'fleet' => [42 => $fleet],
                'command' => [$command['id'] => $command]], [42], 'recall');
            $token = bin2hex(random_bytes(16));
            Journal::prepare(19, 9, (int)$head['revision'], $token, (float)$head['checkpoint_time'], $plan['entries'],
                $plan['metadata'] + ['purpose' => 'qa-mining-close-20261025', 'testFixture' => true,
                    'closeRequest' => hash('sha256', json_encode([19, 9, [42], 'recall'], JSON_THROW_ON_ERROR))]);
            echo json_encode(['token' => $token, 'phase' => 'prepared', 'closing' => true,
                'entries' => count($plan['entries'])]), PHP_EOL;
            return;
        }
        $ledger = $params['miningCheckpoint'] ?? null;
        $from = $ledger === null ? max(microtime(true) - 10, $fleet['current_position_time']) : (float)$ledger['at'];
        $until = $from + 1.23456789;
        $assert($until <= microtime(true), 'Интервал не должен быть в будущем.');
        $config = ['rates' => [901 => 1650.0, 902 => 0.0, 903 => 0.0],
            'capacity' => (float)$input['capacity'], 'fuelRate' => (float)$input['consumption'] * .1,
            'fuelReserve' => 1.0, 'endsAt' => $ledger['endsAt'] ?? ($from + 60)];
        $assert($config['capacity'] === 37000.0 && $config['fuelRate'] === 121.0 && $fleet['fuel_current'] > 2, 'Изменился тестовый состав/бак.');
        $snapshot = ['site' => $site, 'knowledge' => $known, 'fleets' => [42 => $fleet], 'commands' => [42 => $command]];
        $plan = FleetMiningPhaseService::build($snapshot, [42 => $config], $from, $until, $previous);
        $token = bin2hex(random_bytes(16));
        Journal::prepare(19, 9, (int)$head['revision'], $token, $plan['phase']['state']['time'], $plan['entries'],
            $plan['metadata'] + ['purpose' => 'qa-mining-phase-20261025', 'testFixture' => true]);
        echo json_encode(['token' => $token, 'phase' => 'prepared', 'elapsed' => $plan['phase']['elapsed'],
            'metalDelta' => $plan['phase']['cargo'][42][901],
            'fuelSpent' => ($fleet['fuel_current'] + (float)($ledger['fuelCarry'] ?? 0)) - $plan['phase']['fleets'][42]['fuel'],
            'stockCarry' => $plan['metadata']['miningCheckpoint']['stockCarry'], 'entries' => count($plan['entries'])]), PHP_EOL;
        return;
    }
    $cmd = $db->fetchOne('SELECT * FROM fleet_commands WHERE fleet_id=42 AND executed=0 ORDER BY id LIMIT 1');
    $command = $cmd === null ? null : FleetCommands::castRowToSchema($cmd);
    $ledger = $command === null ? null : (json_decode($command['params'], true)['miningCheckpoint'] ?? null);
    if ($mode === '--verify') {
        $assert(($argv[2] ?? '') === $head['token'] && in_array($meta['purpose'] ?? '', ['qa-mining-phase-20261025', 'qa-mining-close-20261025'], true), 'Другой token/probe.');
        $assert($head['phase'] === 'applied', 'Фаза ещё не применена.');
        foreach ($previous['entries'] as $entry) {
            $row = ['site' => $site, 'knowledge' => $known, 'fleet' => $fleet, 'command' => $command][$entry['kind']];
            $assert($row !== null && FleetMiningService::recoveryPatch($row, $entry) === null, 'Не сохранён after.');
        }
    }
    echo json_encode(['phase' => $head['phase'], 'revision' => $head['revision'], 'token' => $head['token'],
        'fleetMetal' => $fleet['resource_metal'], 'fuel' => $fleet['fuel_current'], 'worker' => $ledger,
        'stockCarry' => $meta['miningCheckpoint']['stockCarry'] ?? null,
        'closed' => $command === null ? null : (json_decode($command['params'], true)['miningClosed'] ?? null),
        'pending' => Journal::pending()], JSON_UNESCAPED_UNICODE), PHP_EOL;
}), in_array($mode, ['--prepare', '--close'], true));
