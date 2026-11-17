<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{PlanetBattles, SpaceBattles};
use Swoole\Timer;

/** Временный вызов из onStart основного dev. Одна служебная applied-запись
 * без флотов/игроков/добычи. Её сохраняет и удаляет только штатный saver,
 * освобождает штатный cleanupApplied; probe SQL не исполняет.
 */
return static function (string $path, bool $atLimit = false, string $repository = SpaceBattles::class): void {
    if (file_exists($path)) return;
    if (!in_array($repository, [PlanetBattles::class, SpaceBattles::class], true)) throw new InvalidArgumentException('Не боевой checkpoint.');
    $id = $repository === PlanetBattles::class ? 1900700002 : 1900700001;
    if ($repository::findExistingById($id) !== null) throw new LogicException('Ключ lifecycle probe уже занят.');
    $started = microtime(true);
    $battleId = bin2hex(random_bytes(16));
    $state = ['id' => $battleId, 'phase' => 'applied', 'fleets' => [], 'job' => null,
        'mission' => ['participants' => []], 'resolution' => ['syncRows' => []], 'qaLifecycle' => true];
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
        'cleanupBytes' => null];
    $write = static function (array $proof) use ($path): void {
        file_put_contents($path, json_encode($proof, JSON_THROW_ON_ERROR), LOCK_EX); chmod($path, 0600);
    };
    $write($proof);
    Timer::tick(100, static function (int $timer) use ($id, $repository, $battleId, $started, $markers, &$proof, $write): void {
        $row = $repository::state($id);
        if ($row !== null && $row['id'] !== $battleId) throw new LogicException('Заменён собственный lifecycle checkpoint.');
        if (!$proof['sawCleanupRequested'] && !empty($row['cleanupRequested'])) {
            $proof['sawCleanupRequested'] = true; $proof['phase'] = 'delete_pending';
            $stored = $repository::findExistingById($id) ?? throw new LogicException('Исчез cleanup-marker lifecycle.');
            $proof['cleanupBytes'] = strlen($stored['state']); $write($proof);
        }
        if ($row === null) {
            $proof['phase'] = 'complete'; $proof['rowGone'] = true;
            $proof['markerGone'] = !$markers->exists((string)$id);
            $proof['elapsedSeconds'] = microtime(true) - $started;
            Timer::clear($timer); $write($proof);
        } elseif (microtime(true) - $started > 180) {
            $proof['phase'] = 'timeout'; Timer::clear($timer); $write($proof);
        }
    });
};
