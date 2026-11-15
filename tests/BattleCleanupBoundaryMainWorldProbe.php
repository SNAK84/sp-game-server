<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{SpaceBattles, PlayerQueue};
use SPGame\Game\Services\{FleetBattleService, RepositorySaver};

/** Основной dev после GameProtocolV2::initialize, до сети/saver. Только свои
 * ключи существующих Table; finally восстанавливает все строки/markers.
 * SQL/нового мира/Table нет. ACK собственных fixture моделируется явно.
 */
return static function (string $path, bool $protected = true): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Cleanup boundary: ' . $message);
        ++$checks;
    };
    $table = (new ReflectionProperty(SpaceBattles::class, 'table'))->getValue();
    $sync = (new ReflectionProperty(SpaceBattles::class, 'syncTable'))->getValue();
    $copy = static function (Swoole\Table $table): array { $rows = iterator_to_array($table); ksort($rows); return $rows; };
    $current = static fn(): array => [$copy($table), $copy($sync), PlayerQueue::getAll()];
    $before = $current(); $fillers = []; $extraKey = null;
    $key = 1900700101;
    if ($table->exists((string)$key) || $sync->exists((string)$key)) throw new LogicException('Ключ probe занят.');
    $battleId = bin2hex(random_bytes(16));
    $state = ['id' => $battleId, 'phase' => 'applied', 'fleets' => [], 'job' => null,
        'mission' => ['participants' => []], 'resolution' => ['syncRows' => []], 'padding' => ''];
    $json = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $state['padding'] = str_repeat('x', SpaceBattles::MAX_STATE_BYTES - strlen($json($state)));
    $ackUpdate = static function () use ($key): array {
        $snapshot = [$key => SpaceBattles::captureSaveSnapshot(true)[$key]];
        SpaceBattles::acknowledgeSaveSnapshot($snapshot);
        return $snapshot;
    };
    $reject = static function (string $id) use ($key, $current, $check): void {
        $old = $current(); $rejected = false;
        try { SpaceBattles::requestCleanup($key, $id); } catch (LogicException) { $rejected = true; }
        $check($rejected && $current() === $old, 'Неподходящий checkpoint отклонён без изменений.');
    };
    $compactBytes = null;
    try {
        $check(strlen($json($state)) === SpaceBattles::MAX_STATE_BYTES, 'JSON ровно на границе262144B.');
        SpaceBattles::store($key, $state);
        $check(SpaceBattles::state($key) === $state && strlen($table->get((string)$key)['state']) === SpaceBattles::MAX_STATE_BYTES,
            'Предельная строка записана без усечения.');
        $reject($battleId); // До сохранения результата очистка запрещена.
        $savedUpdate = $ackUpdate();
        $reject($battleId . '-other');
        $stable = $current(); $overflow = false;
        try { SpaceBattles::store($key, array_replace($state, ['padding' => $state['padding'] . 'x'])); }
        catch (OverflowException) { $overflow = true; }
        $check($overflow && $current() === $stable, '262145B отклонены до изменения строки/markers.');

        if (!$protected) {
            $blocked = false;
            try { SpaceBattles::requestCleanup($key, $battleId); } catch (OverflowException) { $blocked = true; }
            $check($blocked && $current() === $stable, 'Воспроизведён отказ очистки полного checkpoint без потери данных.');
        } else {
            SpaceBattles::requestCleanup($key, $battleId);
            $compact = ['id' => $battleId, 'phase' => 'applied', 'cleanupRequested' => true];
            $check(SpaceBattles::state($key) === $compact, 'После ACK результата остаётся только cleanup identity.');
            $compactBytes = strlen($table->get((string)$key)['state']);
            $check($compactBytes < 128 && !isset(SpaceBattles::active()[$key]), 'Компактный applied не участвует в игровом цикле боя.');
            FleetBattleService::releaseSpaceAfterCommit($key, $battleId);
            $check(SpaceBattles::state($key) === $compact, 'Повторный release applied не требует полного combat.');
            $deleting = [$key => SpaceBattles::captureSaveSnapshot(true)[$key]];
            $entry = RepositorySaver::unpackSnapshotEntry($deleting[$key]);
            $check($entry['row'] === null && $entry['retainedRow'] === $table->get((string)$key), 'DELETE snapshot удерживает точный компактный образ.');
            $check(SpaceBattles::captureSaveSnapshot()[$key] === $entry, 'Plain/packed DELETE совпадают.');
            $check(!SpaceBattles::finishCleanup($key, $battleId), 'До DELETE ACK слот остаётся занят.');
            $reject($battleId);
            $stable = $current();
            SpaceBattles::acknowledgeSaveSnapshot($savedUpdate);
            $check($current() === $stable, 'Старый UPDATE ACK не снимает DELETE.');
            // Прежнее полное состояние восстанавливается без ограничения headroom.
            SpaceBattles::store($key, $state);
            $stable = $current();
            SpaceBattles::acknowledgeSaveSnapshot($deleting);
            $check($current() === $stable && SpaceBattles::state($key) === $state, 'Старый DELETE ACK не снимает восстановленный262144B checkpoint.');
            $ackUpdate();

            // Native sync-full должен откатить уже выполненное сжатие main-строки.
            $capacity = SpaceBattles::runtimeLayout()['syncBuckets']; $misses = 0;
            for ($i = 0; $i < $capacity * 16 && $misses < $capacity; ++$i) {
                $candidate = 'qa-cleanup-marker:' . $i;
                if ($sync->exists($candidate)) throw new LogicException('Конфликт filler key.');
                if (@$sync->set($candidate, ['need_update' => 0])) { $fillers[] = $candidate; $misses = 0; } else ++$misses;
            }
            for ($i = 1; ; ++$i) {
                $candidate = (string)($key + $i);
                if ($table->exists($candidate) || $sync->exists($candidate)) throw new LogicException('Конфликт extra key.');
                if (!@$sync->set($candidate, ['need_update' => 0])) { $extraKey = (int)$candidate; break; }
                $fillers[] = $candidate;
                if ($i > $capacity * 16) throw new RuntimeException('Не достигнут sync-full.');
            }
            // Свой временный already-ACK образ в main; sync для этого ключа полон.
            if (!$table->set((string)$extraKey, ['id' => $extraKey, 'phase' => 'applied', 'state' => $json($state)])) {
                throw new RuntimeException('Не размещён before-образ native-full.');
            }
            $stable = $current(); $failed = false;
            try { @SpaceBattles::requestCleanup($extraKey, $battleId); } catch (RuntimeException) { $failed = true; }
            $check($failed && $current() === $stable, 'Sync-full возвращает исходные262144B и все markers.');
            $table->del((string)$extraKey); $extraKey = null;
            foreach ($fillers as $filler) $sync->del($filler);
            $fillers = [];
            SpaceBattles::requestCleanup($key, $battleId);
            $newDelete = [$key => SpaceBattles::captureSaveSnapshot(true)[$key]];
            $stable = $current();
            SpaceBattles::acknowledgeSaveSnapshot($deleting);
            $check($current() === $stable, 'ACK прошлого компактного DELETE не подтверждает новый.');
            SpaceBattles::acknowledgeSaveSnapshot($newDelete);
            $check(SpaceBattles::finishCleanup($key, $battleId) && SpaceBattles::finishCleanup($key, $battleId), 'Освобождение после ACK идемпотентно.');

            SpaceBattles::store($key, ['id' => $battleId, 'phase' => 'running', 'fleets' => []]);
            $ackUpdate(); $reject($battleId);
            // Старый полный cleanupRequested по-прежнему читается и освобождается.
            $legacy = array_replace($state, ['padding' => '', 'cleanupRequested' => true]);
            $legacy['padding'] = str_repeat('x', SpaceBattles::MAX_STATE_BYTES - strlen($json($legacy)));
            SpaceBattles::store($key, $legacy); $ackUpdate();
            (new ReflectionMethod(SpaceBattles::class, 'markForDelete'))->invoke(null, $key);
            SpaceBattles::acknowledgeSaveSnapshot([$key => SpaceBattles::captureSaveSnapshot(true)[$key]]);
            $check(SpaceBattles::finishCleanup($key, $battleId), 'Прежний полный cleanup-marker совместим с ACK/finish.');
            $reject($battleId);
        }
    } finally {
        foreach ($fillers as $filler) if ($sync->exists($filler)) $sync->del($filler);
        if ($table->exists((string)$key)) $table->del((string)$key);
        if ($sync->exists((string)$key)) $sync->del((string)$key);
        if ($extraKey !== null && $table->exists((string)$extraKey)) $table->del((string)$extraKey);
        $check($current() === $before, 'Исходные строки/markers/PlayerQueue восстановлены.');
    }
    file_put_contents($path, json_encode(['ok' => true, 'protected' => $protected, 'checks' => $checks,
        'boundaryBytes' => SpaceBattles::MAX_STATE_BYTES, 'compactBytes' => $compactBytes,
        'newTables' => 0, 'sqlWrites' => 0, 'originalStateRestored' => true], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
