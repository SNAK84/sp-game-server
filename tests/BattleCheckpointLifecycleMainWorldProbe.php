<?php
declare(strict_types=1);

use SPGame\Game\Repositories\SpaceBattles;
use Swoole\Timer;

/** Временный вызов из onStart основного dev. Одна служебная applied-запись
 * без флотов/игроков/добычи. Её сохраняет и удаляет только штатный saver,
 * освобождает штатный cleanupApplied; probe SQL не исполняет.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $id = 1900700001;
    if (SpaceBattles::findExistingById($id) !== null) throw new LogicException('Ключ lifecycle probe уже занят.');
    $started = microtime(true);
    $battleId = bin2hex(random_bytes(16));
    $state = ['id' => $battleId, 'phase' => 'applied', 'fleets' => [], 'job' => null,
        'mission' => ['participants' => []], 'resolution' => ['syncRows' => []], 'qaLifecycle' => true];
    SpaceBattles::store($id, $state);
    $markers = (new ReflectionProperty(SpaceBattles::class, 'syncTable'))->getValue();
    if (($markers->get((string)$id)['need_update'] ?? 0) !== 1) throw new RuntimeException('Не опубликован lifecycle checkpoint.');
    $proof = ['id' => $id, 'battleId' => $battleId, 'pid' => getmypid(), 'phase' => 'created',
        'sawCleanupRequested' => false, 'rowGone' => false, 'markerGone' => false, 'runtimeSqlWrites' => 0];
    $write = static function (array $proof) use ($path): void {
        file_put_contents($path, json_encode($proof, JSON_THROW_ON_ERROR), LOCK_EX); chmod($path, 0600);
    };
    $write($proof);
    Timer::tick(100, static function (int $timer) use ($id, $battleId, $started, $markers, &$proof, $write): void {
        $row = SpaceBattles::state($id);
        if ($row !== null && $row['id'] !== $battleId) throw new LogicException('Заменён собственный lifecycle checkpoint.');
        if (!$proof['sawCleanupRequested'] && !empty($row['cleanupRequested'])) {
            $proof['sawCleanupRequested'] = true; $proof['phase'] = 'delete_pending'; $write($proof);
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
