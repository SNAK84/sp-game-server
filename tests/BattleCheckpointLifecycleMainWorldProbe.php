<?php
declare(strict_types=1);

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Repositories\{FleetCommands, PlanetBattles, SpaceBattles};
use SPGame\Game\Services\FleetSpaceBattleService;
use Swoole\Timer;

/** Временный вызов из onStart основного dev. Одна служебная applied-запись
 * без флотов/игроков/добычи. Её сохраняет и удаляет только штатный saver,
 * освобождает штатный cleanupApplied; probe SQL не исполняет. lateDirty
 * добавляет только безопасную команду без Fleet и удаляет её через saver.
 */
return static function (string $path, bool $atLimit = false, string $repository = SpaceBattles::class, bool $lateDirty = false): void {
    if (file_exists($path)) return;
    if (!in_array($repository, [PlanetBattles::class, SpaceBattles::class], true)) throw new InvalidArgumentException('Не боевой checkpoint.');
    if ($lateDirty && $repository !== SpaceBattles::class) throw new InvalidArgumentException('Late dirty проверяется в пространстве.');
    $id = $repository === PlanetBattles::class ? 1900700002 : 1900700001;
    if ($repository::findExistingById($id) !== null) throw new LogicException('Ключ lifecycle probe уже занят.');
    $started = microtime(true);
    $battleId = bin2hex(random_bytes(16));
    $state = ['id' => $battleId, 'phase' => 'applied', 'fleets' => [], 'job' => null,
        'mission' => ['participants' => []], 'resolution' => ['syncRows' => []], 'qaLifecycle' => true];
    $commandId = null;
    if ($lateDirty) {
        $fleetId = 1900700003; // Самого Fleet нет: это только безопасная pending-команда без execute_time.
        if (count(FleetCommands::view($fleetId)) > 0) throw new LogicException('Занят ключ команды lifecycle.');
        $commandId = FleetCommands::nextId();
        FleetCommands::add(['id' => $commandId, 'fleet_id' => $fleetId, 'command_type' => FleetCommandType::ATTACK->value,
            'execute_time' => 0.0, 'create_time' => $started, 'executed' => 0,
            'params' => json_encode(['battleWait' => ['scope' => 'space', 'key' => $id, 'id' => $battleId]], JSON_THROW_ON_ERROR)]);
        $state['fleets'] = [$fleetId]; $state['mission']['participants'][$fleetId] = [];
        $state['resolution']['syncRows'] = ['commands' => [$commandId]];
    }
    if ($atLimit) {
        $state['padding'] = '';
        $state['padding'] = str_repeat('x', $repository::MAX_STATE_BYTES
            - strlen(json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)));
    }
    $repository::store($id, $state);
    $markers = (new ReflectionProperty($repository, 'syncTable'))->getValue();
    if (($markers->get((string)$id)['need_update'] ?? 0) !== 1) throw new RuntimeException('Не опубликован lifecycle checkpoint.');
    $stored = $repository::findExistingById($id) ?? throw new LogicException('Не опубликована lifecycle строка.');
    $proof = ['id' => $id, 'repository' => (new ReflectionClass($repository))->getShortName(),
        'battleId' => $battleId, 'pid' => getmypid(), 'phase' => 'created',
        'sawCleanupRequested' => false, 'rowGone' => false, 'markerGone' => false, 'runtimeSqlWrites' => 0,
        'atLimit' => $atLimit, 'checkpointBytes' => strlen($stored['state']),
        'cleanupBytes' => null, 'lateDirty' => $lateDirty, 'commandId' => $commandId,
        'dependencyDirtyAtCleanup' => false, 'dependencyDeleteRequested' => false];
    $commandMarkers = (new ReflectionProperty(FleetCommands::class, 'syncTable'))->getValue();
    $write = static function (array $proof) use ($path): void {
        file_put_contents($path, json_encode($proof, JSON_THROW_ON_ERROR), LOCK_EX); chmod($path, 0600);
    };
    $write($proof);
    Timer::tick(100, static function (int $timer) use ($id, $repository, $battleId, $started, $markers, $commandMarkers,
        $commandId, &$proof, $write): void {
        $row = $repository::state($id);
        if ($row !== null && $row['id'] !== $battleId) throw new LogicException('Заменён собственный lifecycle checkpoint.');
        if ($commandId !== null && !$proof['dependencyDirtyAtCleanup'] && $row !== null && !$markers->exists((string)$id)) {
            // Общий COMMIT уже подтвердил applied. Вносим более позднее изменение
            // зависимой команды и проверяем именно при dirty, не в щели после ACK.
            FleetCommands::update(['id' => $commandId, 'executed' => 1, 'params' => '{}']);
            if (($commandMarkers->get((string)$commandId)['need_update'] ?? 0) !== 1) throw new RuntimeException('Зависимость не dirty.');
            FleetSpaceBattleService::cleanupApplied();
            $row = $repository::state($id);
            if (empty($row['cleanupRequested'])) throw new RuntimeException('Late dirty удерживает сохранённый бой.');
            $proof['dependencyDirtyAtCleanup'] = true;
        }
        if (!$proof['sawCleanupRequested'] && !empty($row['cleanupRequested'])) {
            $proof['sawCleanupRequested'] = true; $proof['phase'] = 'delete_pending';
            $stored = $repository::findExistingById($id) ?? throw new LogicException('Исчез cleanup-marker lifecycle.');
            $proof['cleanupBytes'] = strlen($stored['state']); $write($proof);
        }
        if ($row === null) {
            $proof['rowGone'] = true;
            $proof['markerGone'] = !$markers->exists((string)$id);
            if ($commandId !== null && !$proof['dependencyDeleteRequested']) {
                FleetCommands::delete(['id' => $commandId]);
                $proof['dependencyDeleteRequested'] = true; $proof['phase'] = 'dependency_delete_pending'; $write($proof);
            }
            if ($commandId === null || !$commandMarkers->exists((string)$commandId)) {
                $proof['phase'] = 'complete';
                $proof['dependencyGone'] = $commandId === null || FleetCommands::getCommand($commandId) === null;
                $proof['elapsedSeconds'] = microtime(true) - $started;
                Timer::clear($timer); $write($proof);
            }
        }
        if ($proof['phase'] !== 'complete' && microtime(true) - $started > 180) {
            $proof['phase'] = 'timeout'; Timer::clear($timer); $write($proof);
        }
    });
};
