<?php
declare(strict_types=1);

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Repositories\{BaseRepository, FleetCommands, PlayerQueue, SpaceBattles};
use SPGame\Game\Services\FleetSpaceBattleService;

/** Основной dev после initialize, до сети/saver. Только свои checkpoint и
 * команда в существующих Table, без Fleet/игроков/SQL/newTable. Моделируется
 * общий ACK, затем более поздние изменения команды продолжившегося маршрута.
 * finally возвращает исходные строки/markers/индексы; ID не откатываются.
 */
return static function (string $path, bool $protected = true): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Space cleanup: ' . $message);
        ++$checks;
    };
    $get = static fn(string $class, string $name): mixed => (new ReflectionProperty($class, $name))->getValue();
    $copy = static function (Swoole\Table $table): array { $rows = iterator_to_array($table); ksort($rows); return $rows; };
    $table = $get(SpaceBattles::class, 'table'); $sync = $get(SpaceBattles::class, 'syncTable');
    $before = [$copy($table), $copy($sync)];
    $commands = $get(FleetCommands::class, 'table'); $commandSync = $get(FleetCommands::class, 'syncTable');
    $indexes = $get(FleetCommands::class, 'indexTables');
    $positions = $get(BaseRepository::class, 'indexPositions')[FleetCommands::class]['table'];
    $commandState = static function () use ($copy, $commands, $commandSync, $indexes, $positions): array {
        $markers = $copy($commandSync); unset($markers['meta']);
        return [$copy($commands), $markers, array_map($copy, $indexes), $copy($positions)];
    };
    $commandsBefore = $commandState(); $queueBefore = PlayerQueue::getAll();
    $key = 1901300001; $fleetId = 1901300002; $commandId = null;
    if ($table->exists((string)$key) || $sync->exists((string)$key) || count(FleetCommands::view($fleetId)) > 0) {
        throw new LogicException('Конфликт ключей probe.');
    }
    $battleId = bin2hex(random_bytes(16));
    $json = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR);
    $ack = static function () use ($key): void {
        SpaceBattles::acknowledgeSaveSnapshot([$key => SpaceBattles::captureSaveSnapshot(true)[$key]]);
    };
    try {
        $commandId = FleetCommands::nextId();
        FleetCommands::add(['id' => $commandId, 'fleet_id' => $fleetId, 'command_type' => FleetCommandType::ATTACK->value,
            'execute_time' => 1.0, 'create_time' => 1.0, 'executed' => 1, 'params' => '{}']);
        $state = ['id' => $battleId, 'phase' => 'applied', 'fleets' => [$fleetId],
            'mission' => ['participants' => [$fleetId => []]], 'resolution' => ['syncRows' => ['commands' => [$commandId]]]];
        SpaceBattles::store($key, $state);
        FleetSpaceBattleService::cleanupApplied();
        $check(SpaceBattles::state($key) === $state && $sync->get((string)$key)['need_update'] === 1,
            'До общего ACK applied не очищается.');
        $savedCommand = [$commandId => FleetCommands::captureSaveSnapshot(true)[$commandId]];
        $ack(); FleetCommands::acknowledgeSaveSnapshot($savedCommand);
        $check(!$sync->exists((string)$key) && !$commandSync->exists((string)$commandId), 'Общий ACK смоделирован для обоих репозиториев.');
        for ($tick = 2; $tick <= 4; ++$tick) {
            FleetCommands::update(['id' => $commandId, 'execute_time' => (float)$tick]);
            FleetSpaceBattleService::cleanupApplied();
            $check($commandSync->get((string)$commandId)['need_update'] === 1, 'Поздняя команда остаётся dirty.');
            if (!$protected) $check(SpaceBattles::state($key) === $state, 'Воспроизведён повторный отказ очистки из-за поздней dirty-команды.');
            else $check(!empty(SpaceBattles::state($key)['cleanupRequested']), 'Поздние dirty не удерживают уже сохранённый результат боя.');
        }
        if ($protected) {
            $latest = $commandSync->get((string)$commandId);
            FleetCommands::acknowledgeSaveSnapshot($savedCommand);
            $check($commandSync->get((string)$commandId) === $latest, 'Старый ACK команды не теряет её новое поколение.');
            $check($table->exists((string)$key) && $sync->get((string)$key)['need_delete'] === 1, 'До DELETE ACK checkpoint остаётся в RAM.');
            $ack(); FleetSpaceBattleService::cleanupApplied();
            $check(!$table->exists((string)$key) && !$sync->exists((string)$key)
                && $commandSync->get((string)$commandId) === $latest, 'После DELETE ACK освобождён только старый checkpoint.');

            // Незавершённый wait не должен исчезнуть вместе с полным контекстом.
            $wait = ['scope' => 'space', 'key' => $key, 'id' => $battleId];
            FleetCommands::update(['id' => $commandId, 'executed' => 0, 'params' => $json(['battleWait' => $wait])]);
            SpaceBattles::store($key, $state); $ack();
            FleetSpaceBattleService::cleanupApplied();
            $check(SpaceBattles::state($key) === $state && !$sync->exists((string)$key), 'Живой wait того же боя удерживает контекст.');
            FleetCommands::update(['id' => $commandId, 'executed' => 1]);
            FleetSpaceBattleService::cleanupApplied();
            $check(!empty(SpaceBattles::state($key)['cleanupRequested']), 'Исполненная команда больше не удерживает контекст.');
            $ack(); FleetSpaceBattleService::cleanupApplied();
            foreach (['key', 'id', 'scope'] as $field) {
                $other = array_replace($wait, [$field => $field === 'key' ? $key + 1 : 'other']);
                FleetCommands::update(['id' => $commandId, 'executed' => 0, 'params' => $json(['battleWait' => $other])]);
                SpaceBattles::store($key, $state); $ack(); FleetSpaceBattleService::cleanupApplied();
                $check(!empty(SpaceBattles::state($key)['cleanupRequested']), 'Wait другого scope/key/id не удерживает этот бой.');
                $ack(); FleetSpaceBattleService::cleanupApplied();
            }
            foreach (['running', 'finished', 'settled', 'failed'] as $phase) {
                $next = array_replace($state, ['phase' => $phase]); SpaceBattles::store($key, $next); $ack();
                FleetSpaceBattleService::cleanupApplied();
                $check(SpaceBattles::state($key) === $next, 'Не удалена фаза ' . $phase);
            }
            $incomplete = ['id' => $battleId, 'phase' => 'applied', 'fleets' => []];
            SpaceBattles::store($key, $incomplete); $ack(); FleetSpaceBattleService::cleanupApplied();
            $check(SpaceBattles::state($key) === $incomplete, 'Applied без контекста адаптера оставлен для диагностики.');
        }
    } finally {
        if ($commandId !== null) {
            $row = FleetCommands::findExistingById($commandId);
            if ($row !== null) FleetCommands::discardUncommittedRow($row);
            if ($commandSync->exists((string)$commandId)) $commandSync->del((string)$commandId);
        }
        if ($table->exists((string)$key)) $table->del((string)$key);
        if ($sync->exists((string)$key)) $sync->del((string)$key);
        foreach ($before[0] as $id => $row) $table->set((string)$id, $row);
        foreach ($copy($sync) as $id => $row) if (!isset($before[1][$id])) $sync->del((string)$id);
        foreach ($before[1] as $id => $row) $sync->set((string)$id, $row);
        $check([$copy($table), $copy($sync)] === $before, 'Исходные checkpoint/dirty возвращены.');
        $check($commandState() === $commandsBefore && PlayerQueue::getAll() === $queueBefore, 'Команды/индексы/dirty/PlayerQueue прежние.');
    }
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks, 'protected' => $protected,
        'sqlWrites' => 0, 'newTables' => 0, 'originalStateRestored' => true, 'idGapsAllowed' => true], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
