<?php
declare(strict_types=1);

use SPGame\Core\Database;
use SPGame\Game\Repositories\{PlanetBattles, SpaceBattles, FleetExpeditions, PlayerQueue};
use SPGame\Game\Services\RepositorySaver;

/** Основной dev после GameProtocolV2::initialize, до сети/saver. Не новый мир.
 * Только свои временные ключи уже созданных Table; finally восстанавливает
 * исходные строки/markers. SQL writer — только DELETE своего несуществующего
 * SQL-ключа в откатываемой транзакции. ACK моделируется на этих же ключах.
 */
return static function (string $path, bool $protected = true): void {
    if (file_exists($path)) return;
    $checks = 0; $evidence = [];
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Battle checkpoint: ' . $message);
        ++$checks;
    };
    $get = static fn(string $class, string $name): mixed => (new ReflectionProperty($class, $name))->getValue();
    $copy = static function (Swoole\Table $table): array { $rows = iterator_to_array($table); ksort($rows); return $rows; };
    $queueBefore = PlayerQueue::getAll();
    $classes = $protected ? [PlanetBattles::class, SpaceBattles::class, FleetExpeditions::class] : [PlanetBattles::class, SpaceBattles::class];
    foreach ($classes as $classIndex => $class) {
        $table = $get($class, 'table'); $sync = $get($class, 'syncTable');
        $state = static fn(): array => [$copy($table), $copy($sync)];
        $before = $state(); $owned = $syncFillers = [];
        $capacity = $class::runtimeLayout()['mainBuckets'];
        $base = 1800000000 + $classIndex * 100000;
        $battle = ['id' => bin2hex(random_bytes(16)), 'phase' => 'applied', 'fleets' => [], 'qaProbe' => 1];
        try {
            for ($i = 1; $table->count() < $capacity; ++$i) {
                $id = $base + $i;
                if ($table->exists((string)$id) || $sync->exists((string)$id)) throw new LogicException('Конфликт временного ID.');
                $row = ['id' => $id, 'state' => json_encode(array_replace($battle, ['qaProbe' => 0]), JSON_THROW_ON_ERROR)];
                if ($class !== FleetExpeditions::class) $row['phase'] = 'applied';
                if (!$table->set((string)$id, $row)) throw new RuntimeException('Не заполнена native Table.');
                $owned[] = $id;
            }
            $candidate = $base + 20000;
            if ($table->exists((string)$candidate) || $sync->exists((string)$candidate)) throw new LogicException('Конфликт candidate ID.');
            $owned[] = $candidate; $full = $state(); $rejected = false;
            try { $class::store($candidate, $battle); } catch (OverflowException) { $rejected = true; }
            $check($rejected === $protected, 'Admission соответствует режиму проверки: ' . $class);
            $check($protected ? $state() === $full : $table->count() === $capacity + 1,
                'Нет новой строки/marker сверх бюджета либо воспроизведено превышение.');
            $evidence[$class]['admittedOverBudget'] = !$rejected;
            if (!$protected) { $table->del((string)$candidate); $sync->del((string)$candidate); }
            $id = $owned[0];
            $class::store($id, $battle);
            $first = $sync->get((string)$id);
            $saved = [$id => $class::captureSaveSnapshot(true)[$id]];
            $class::store($id, array_replace($battle, ['qaProbe' => 2]));
            $class::store($id, $battle); // ABA: байты снова прежние, поколение обязано быть новым.
            $latest = $sync->get((string)$id);
            $class::acknowledgeSaveSnapshot($saved);
            if (!$protected) {
                if ($class === SpaceBattles::class) {
                    $check($first['last_id'] === $latest['last_id'], 'Воспроизведён неизменный generation пространственного боя.');
                    $check($sync->get((string)$id) === false, 'Старый ACK снял новую ABA-запись.');
                    SpaceBattles::requestCleanup($id, $battle['id']);
                    $deleting = [$id => SpaceBattles::captureSaveSnapshot(true)[$id]];
                    SpaceBattles::acknowledgeSaveSnapshot($deleting);
                    $check(!SpaceBattles::finishCleanup($id, $battle['id']), 'Воспроизведено ожидание cleanup после ACK DELETE.');
                    $check($sync->get((string)$id)['need_delete'] === 1, 'DELETE marker не подтверждён общим ACK.');
                    $check($table->exists((string)$id), 'Завершённый checkpoint продолжает занимать слот.');
                }
                continue;
            }
            $check($first['last_id'] > 0 && $latest['last_id'] > $first['last_id'], 'Каждая запись получает новый generation.');
            $check($sync->get((string)$id) === $latest && $class::state($id) === $battle, 'Старый ABA ACK не снимает новое поколение.');
            if ($class !== FleetExpeditions::class) {
                (new ReflectionMethod($class, 'markForDelete'))->invoke(null, $id);
                $deleted = $sync->get((string)$id);
                $class::store($id, $battle);
                $marker = $sync->get((string)$id);
                $check($marker['need_update'] === 1 && $marker['need_delete'] === 0 && $marker['last_id'] > $deleted['last_id'],
                    'Восстановление checkpoint снимает tombstone новым поколением.');
            }
            $full = $state();
            try { $class::store($id, $battle + ['overflow' => str_repeat('x', PlanetBattles::MAX_STATE_BYTES)]); throw new LogicException('Oversize принят.'); }
            catch (OverflowException) { ++$checks; }
            $check($state() === $full, 'Oversize не меняет принятую строку/dirty.');

            if ($class === SpaceBattles::class) {
                $class::acknowledgeSaveSnapshot([$id => $class::captureSaveSnapshot(true)[$id]]);
                SpaceBattles::requestCleanup($id, $battle['id']);
                $deleting = [$id => SpaceBattles::captureSaveSnapshot(true)[$id]];
                $entry = RepositorySaver::unpackSnapshotEntry($deleting[$id]);
                $check($entry['row'] === null && $entry['retainedRow'] === $table->get((string)$id), 'DELETE имеет точный retained before-образ.');
                $check(SpaceBattles::captureSaveSnapshot()[$id] === $entry, 'Plain/packed retained DELETE совпадают.');
                $check(!SpaceBattles::finishCleanup($id, $battle['id']), 'До ACK слот не освобождается.');
                $db = Database::independent();
                $check((int)$db->fetchOne('SELECT COUNT(*) n FROM space_battles WHERE id=?', [$id])['n'] === 0, 'SQL writer затрагивает только свой отсутствующий ключ.');
                $db->beginTransaction();
                try { SpaceBattles::writeSaveSnapshot($deleting, $db); }
                finally { $db->rollBack(); }
                $check(!SpaceBattles::finishCleanup($id, $battle['id']), 'Rollback SQL не подтверждает DELETE.');
                SpaceBattles::store($id, $battle);
                $newMarker = $sync->get((string)$id);
                SpaceBattles::acknowledgeSaveSnapshot($deleting);
                $check($sync->get((string)$id) === $newMarker && SpaceBattles::state($id) === $battle, 'Старый DELETE ACK не снимает восстановленный checkpoint.');
                $class::acknowledgeSaveSnapshot([$id => $class::captureSaveSnapshot(true)[$id]]);
                SpaceBattles::requestCleanup($id, $battle['id']);
                $currentDelete = [$id => $class::captureSaveSnapshot(true)[$id]];
                SpaceBattles::acknowledgeSaveSnapshot($deleting);
                $check($sync->get((string)$id)['need_delete'] === 1, 'ACK прошлого DELETE не подтверждает новый DELETE.');
                $class::acknowledgeSaveSnapshot($currentDelete);
                $check($sync->get((string)$id) === false && $table->exists((string)$id), 'ACK подтверждает DELETE, оставляя строку до finishCleanup.');
                $check(SpaceBattles::finishCleanup($id, $battle['id']) && !$table->exists((string)$id), 'После ACK физический слот освобождается.');
                $check(SpaceBattles::finishCleanup($id, $battle['id']), 'Повтор cleanup идемпотентен.');
            }

            // Отказ native sync после успешной записи main: rollback без новых Table.
            $free = $owned[1]; $table->del((string)$free); $sync->del((string)$free);
            $syncCapacity = $class::runtimeLayout()['syncBuckets']; $misses = 0;
            for ($i = 0; $i < $syncCapacity * 16 && $misses < $syncCapacity; ++$i) {
                $key = 'qa-battle-marker:' . $i;
                if ($sync->exists($key)) throw new LogicException('Конфликт sync fixture.');
                if (@$sync->set($key, ['need_update' => 0])) { $syncFillers[] = $key; $misses = 0; } else ++$misses;
            }
            for ($i = 30000; ; ++$i) {
                $failId = $base + $i; $key = (string)$failId;
                if ($table->exists($key) || $sync->exists($key)) throw new LogicException('Конфликт sync candidate.');
                if (!@$sync->set($key, ['need_update' => 0])) break;
                $syncFillers[] = $key;
                if ($i > 30000 + $syncCapacity * 16) throw new RuntimeException('Не достигнут native sync предел.');
            }
            $owned[] = $failId; $full = $state(); $failed = false;
            try { @$class::store($failId, $battle); } catch (RuntimeException) { $failed = true; }
            $check($failed, 'Native sync отказ достигнут после записи main.');
            $check($state() === $full, 'Native sync отказ не оставил main/dirty и не изменил чужие строки.');
            $evidence[$class]['nativeSyncRollback'] = true;
        } finally {
            foreach ($owned as $id) { if ($table->exists((string)$id)) $table->del((string)$id); if ($sync->exists((string)$id)) $sync->del((string)$id); }
            foreach ($syncFillers as $key) if ($sync->exists($key)) $sync->del($key);
            $check($state() === $before, 'Исходные строки/markers восстановлены: ' . $class);
        }
    }
    $check(PlayerQueue::getAll() === $queueBefore, 'PlayerQueue прежняя.');
    file_put_contents($path, json_encode(['ok' => true, 'protected' => $protected, 'checks' => $checks,
        'evidence' => $evidence, 'newTables' => 0, 'originalStateRestored' => true, 'sqlCommitted' => false], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
