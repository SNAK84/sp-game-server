<?php
declare(strict_types=1);

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Repositories\{BaseRepository, FleetCommands, PlanetBattles, PlayerQueue};
use SPGame\Game\Services\{FleetAttackService, FleetBattleService, RepositorySaver};

/** Основной dev до сети/saver. Собственные ключи в существующих Table;
 * ACK моделируются только для своих записей. finally возвращает строки,
 * dirty и индексы. Монотонные ID/generation не откатываются; SQL/newTable0.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $text) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Planet cleanup: ' . $text);
        ++$checks;
    };
    $get = static fn(string $class, string $name): mixed => (new ReflectionProperty($class, $name))->getValue();
    $copy = static function (Swoole\Table $table): array { $rows = iterator_to_array($table); ksort($rows); return $rows; };
    $table = $get(PlanetBattles::class, 'table'); $sync = $get(PlanetBattles::class, 'syncTable');
    $before = [$copy($table), $copy($sync)];
    $commandTable = $get(FleetCommands::class, 'table'); $commandSync = $get(FleetCommands::class, 'syncTable');
    $indexes = $get(FleetCommands::class, 'indexTables');
    $positions = $get(BaseRepository::class, 'indexPositions')[FleetCommands::class]['table'];
    $commandState = static function () use ($copy, $commandTable, $commandSync, $indexes, $positions): array {
        $markers = $copy($commandSync); unset($markers['meta']); // ID allocator может оставить пропуск.
        return [$copy($commandTable), $markers, array_map($copy, $indexes), $copy($positions)];
    };
    $commandsBefore = $commandState(); $queueBefore = PlayerQueue::getAll();
    $key = 1901200001; $fleetId = 1901200002; $commandId = null;
    if ($table->exists((string)$key) || $sync->exists((string)$key) || count(FleetCommands::view($fleetId)) > 0) {
        throw new LogicException('Конфликт ключей probe.');
    }
    $battleId = bin2hex(random_bytes(16));
    $json = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $ack = static function () use ($key): array {
        $snapshot = [$key => PlanetBattles::captureSaveSnapshot(true)[$key]];
        PlanetBattles::acknowledgeSaveSnapshot($snapshot);
        return $snapshot;
    };
    try {
        $commandId = FleetCommands::nextId();
        FleetCommands::add(['id' => $commandId, 'fleet_id' => $fleetId, 'command_type' => FleetCommandType::ATTACK->value,
            'execute_time' => 1.0, 'create_time' => 1.0, 'executed' => 0,
            'params' => $json(['battleWait' => ['planetId' => $key, 'id' => $battleId]])]);
        $state = ['id' => $battleId, 'phase' => 'applied', 'fleets' => [$fleetId],
            'mission' => ['participants' => [], 'waiting' => [$fleetId => ['commandId' => $commandId]]],
            'resolution' => [], 'padding' => '']; // Включая старый applied без syncRows.
        $state['padding'] = str_repeat('x', PlanetBattles::MAX_STATE_BYTES - strlen($json($state)));
        PlanetBattles::store($key, $state);
        $check(strlen($table->get((string)$key)['state']) === PlanetBattles::MAX_STATE_BYTES, 'Предельный262144B checkpoint записан.');
        FleetAttackService::cleanupApplied();
        $check(PlanetBattles::state($key) === $state && $sync->get((string)$key)['need_update'] === 1, 'До общего ACK очистка не начинается.');
        $oldUpdate = $ack();
        FleetAttackService::cleanupApplied();
        $command = FleetCommands::getCommand($commandId);
        $check(PlanetBattles::state($key) === $state && $sync->get((string)$key) === false, 'Отложенный battleWait удерживает applied.');
        $check(!FleetAttackService::mustWait($command), 'Отложенная команда доступна, а не заблокирована отсутствующим checkpoint.');
        FleetCommands::update(['id' => $commandId, 'executed' => 1]);
        FleetAttackService::cleanupApplied();
        $check(PlanetBattles::state($key) === ['id' => $battleId, 'phase' => 'applied', 'cleanupRequested' => true],
            'Сохранённый legacy applied сжимается до identity.');
        $deleting = [$key => PlanetBattles::captureSaveSnapshot(true)[$key]];
        $entry = RepositorySaver::unpackSnapshotEntry($deleting[$key]);
        $check($entry['row'] === null && $entry['retainedRow'] === $table->get((string)$key), 'DELETE несёт точный retained before.');
        $check(PlanetBattles::captureSaveSnapshot()[$key] === $entry, 'Plain/packed совпадают.');
        PlanetBattles::acknowledgeSaveSnapshot($oldUpdate);
        $check($sync->get((string)$key)['need_delete'] === 1, 'Старый UPDATE ACK не подтверждает DELETE.');
        FleetAttackService::cleanupApplied();
        $check($table->exists((string)$key), 'До DELETE ACK слот не освобождается.');

        // На той же планете начинается новый бой до ACK старого DELETE.
        FleetBattleService::assertPlanetAvailable($key);
        $next = ['id' => bin2hex(random_bytes(16)), 'phase' => 'running', 'fleets' => [], 'mission' => [], 'resolution' => []];
        PlanetBattles::store($key, $next); $latest = $sync->get((string)$key);
        PlanetBattles::acknowledgeSaveSnapshot($deleting);
        FleetAttackService::cleanupApplied();
        $check(PlanetBattles::state($key) === $next && $sync->get((string)$key) === $latest, 'Старый DELETE ACK не стирает новый бой на том же ключе.');
        $check(!in_array($key, PlanetBattles::appliedKeys(), true), 'Metadata scan не считает running завершённым.');
        $next['phase'] = 'finished'; PlanetBattles::store($key, $next);
        FleetBattleService::releaseAfterCommit($key, $next['id']);
        $ack(); FleetAttackService::cleanupApplied();
        $newDelete = [$key => PlanetBattles::captureSaveSnapshot(true)[$key]];
        PlanetBattles::acknowledgeSaveSnapshot($deleting);
        $check($sync->get((string)$key)['need_delete'] === 1, 'ACK старого боя не подтверждает DELETE нового.');
        PlanetBattles::acknowledgeSaveSnapshot($newDelete);
        FleetAttackService::cleanupApplied();
        $check(!$table->exists((string)$key) && !$sync->exists((string)$key), 'После ACK освобождены RAM и marker.');
        $check(PlanetBattles::finishCleanup($key, $next['id']), 'Повторное завершение идемпотентно.');
        foreach (['running', 'finished', 'settled', 'failed'] as $phase) {
            $next['phase'] = $phase; PlanetBattles::store($key, $next); $ack();
            FleetAttackService::cleanupApplied();
            $check(PlanetBattles::state($key) === $next, 'Незавершённая фаза сохранена: ' . $phase);
        }
        $incomplete = ['id' => $battleId, 'phase' => 'applied', 'fleets' => []];
        PlanetBattles::store($key, $incomplete); $ack(); FleetAttackService::cleanupApplied();
        $check(PlanetBattles::state($key) === $incomplete, 'Applied без контекста адаптера не удаляется автоматически.');
    } finally {
        if ($commandId !== null) {
            $row = FleetCommands::findExistingById($commandId);
            if ($row !== null) FleetCommands::discardUncommittedRow($row);
            if ($commandSync->exists((string)$commandId)) $commandSync->del((string)$commandId);
        }
        if ($table->exists((string)$key)) $table->del((string)$key);
        if ($sync->exists((string)$key)) $sync->del((string)$key);
        // cleanupApplied мог осмотреть исходные applied; всё возвращается до сети/saver.
        foreach ($before[0] as $id => $row) $table->set((string)$id, $row);
        foreach ($copy($sync) as $id => $row) if (!isset($before[1][$id])) $sync->del((string)$id);
        foreach ($before[1] as $id => $row) $sync->set((string)$id, $row);
        $check([$copy($table), $copy($sync)] === $before, 'Исходные checkpoint/dirty возвращены.');
        $check($commandState() === $commandsBefore && PlayerQueue::getAll() === $queueBefore, 'Команды/индексы/позиции/dirty/PlayerQueue прежние.');
    }
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks, 'newTables' => 0,
        'sqlWrites' => 0, 'originalStateRestored' => true, 'idGapsAllowed' => true], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
