<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{PlanetBattles, PlayerQueue, SpaceBattles};
use SPGame\Game\Services\{FleetBattleService as Battles, FleetCombatService as Combat};

/** После initializeWorkers, до сети/saver основного dev. Свои checkpoint в
 * существующих Table, без Fleet/игроков/SQL. ACK не моделируется. Возвращает
 * строки/markers/mailbox/настройки; generation не откатываются.
 */
return static function (string $path, bool $protected = true): void {
    if (file_exists($path)) return;
    $checks = 0; $measurements = [];
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Battle coordinator: ' . $message);
        ++$checks;
    };
    $get = static fn(string|object $class, string $name): mixed => (new ReflectionProperty($class, $name))->getValue(is_object($class) ? $class : null);
    $copy = static function (Swoole\Table $table): array { $rows = iterator_to_array($table); ksort($rows); return $rows; };
    $repositories = [PlanetBattles::class, SpaceBattles::class];
    $tables = $markers = $before = $owned = [];
    foreach ($repositories as $repository) {
        foreach ($repository::iterateActive() as $state) if ($state['phase'] !== 'failed') throw new LogicException('Есть исполняемый чужой бой.');
        $tables[$repository] = $get($repository, 'table'); $markers[$repository] = $get($repository, 'syncTable');
        $before[$repository] = [$copy($tables[$repository]), $copy($markers[$repository])]; $owned[$repository] = [];
    }
    $mailbox = $get(Battles::class, 'results'); $mailboxTable = $get($mailbox, 'table');
    if ($mailboxTable->count() !== 0) throw new LogicException('Mailbox занят до сети.');
    $queue = PlayerQueue::getAll();
    $dispatch = new ReflectionProperty(Battles::class, 'dispatch'); $oldDispatch = $dispatch->getValue();
    $limit = new ReflectionProperty(Battles::class, 'workerLimit'); $oldLimit = $limit->getValue();
    $jobs = [];
    $clearOwn = static function () use ($tables, $markers, &$owned): void {
        foreach ($owned as $repository => $keys) foreach ($keys as $key) {
            if ($tables[$repository]->exists((string)$key)) $tables[$repository]->del((string)$key);
            if ($markers[$repository]->exists((string)$key)) $markers[$repository]->del((string)$key);
        }
    };
    $json = static fn(array $state): string => json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    try {
        $limit->setValue(null, 2);
        $dispatch->setValue(null, static function (array $task) use (&$jobs): bool { $jobs[] = $task; return true; });
        foreach ([4, 16] as $count) {
            foreach ($repositories as $r => $repository) for ($i = count($owned[$repository]); $i < $count; ++$i) {
                $key = 1901600000 + $r * 100 + $i;
                if ($tables[$repository]->exists((string)$key) || $markers[$repository]->exists((string)$key)) throw new LogicException('Ключ probe занят.');
                $state = ['id' => str_pad((string)$key, 32, '0', STR_PAD_LEFT), 'phase' => 'running', 'job' => null,
                    'fleets' => [], 'startedAt' => 1000000.0, 'combat' => ['rounds' => []], 'padding' => ''];
                $state['padding'] = str_repeat('x', $repository::MAX_STATE_BYTES - strlen($json($state)));
                $repository::store($key, $state); $owned[$repository][] = $key;
            }
            gc_collect_cycles(); $memory = memory_get_usage(); memory_reset_peak_usage(); $started = hrtime(true);
            $affected = Battles::tick(100.0);
            $measurements[$count] = ['peakBytes' => memory_get_peak_usage() - $memory, 'elapsedMs' => (hrtime(true) - $started) / 1000000];
            $check($affected === [] && $jobs === [] && $mailboxTable->count() === 0, 'Будущие раунды не отправляются worker.');
        }
        $growth = $measurements[16]['peakBytes'] - $measurements[4]['peakBytes'];
        $check($protected ? $growth < 1048576 : $growth > 4194304,
            $protected ? 'Пик tick не растёт на сумму24 больших JSON.' : 'Воспроизведена коллекция полных боёв в tick.');
        $clearOwn();
        if ($protected) {
            $group = ['type' => 204, 'kind' => 'fleet', 'count' => 10, 'attack' => 1.0,
                'hull' => 10000.0, 'shield' => 0.0, 'unitCost' => 4000.0, 'experience' => 0.0];
            foreach ([0, 1] as $i) Battles::begin($owned[PlanetBattles::class][$i], ['a' => $group], ['d' => $group], 100.0);
            Battles::beginSpace($owned[SpaceBattles::class][0], ['a' => $group], ['d' => $group], 100.0);
            $order = [];
            foreach ($repositories as $repository) foreach ($repository::activeKeys() as $key) {
                if (in_array($key, $owned[$repository], true)) $order[] = [$repository, $key];
            }
            $check(count($order) === 3 && $order[0][0] === PlanetBattles::class && $order[2][0] === SpaceBattles::class,
                'Порядок Planet→Space и native-ключей сохранён.');
            Battles::tick(101.0);
            $check(count($jobs) === 2 && $mailboxTable->count() === 2, 'Общий лимит двух jobs для обоих репозиториев.');
            foreach (array_slice($order, 0, 2) as $i => [$repository, $key]) {
                $check($repository::state($key)['job']['token'] === $jobs[$i]['token'], 'Dispatch в исходном порядке.');
            }
            [$thirdRepository, $thirdKey] = $order[2];
            $check($thirdRepository::state($thirdKey)['job'] === null, 'Третий бой ждёт свободный worker.');
            Battles::tick(101.1);
            $check(count($jobs) === 2 && $mailboxTable->count() === 2, 'Занятые jobs не отправляются повторно.');
            Battles::executeTask($jobs[0]); Battles::tick(101.2);
            [$firstRepository, $firstKey] = $order[0];
            $first = $firstRepository::state($firstKey);
            $check(count($first['combat']['rounds']) === 1 && $first['job'] === null, 'Готовый ответ применён один раз, следующий раунд ещё не наступил.');
            $check(count($jobs) === 3 && $thirdRepository::state($thirdKey)['job']['token'] === $jobs[2]['token']
                && $mailboxTable->count() === 2, 'Освободившийся worker получает ожидающий Space-бой в том же tick.');
            $mailbox->publish($jobs[1]['token'], $json(['error' => 'qa coordinator expected failure']));
            Battles::tick(101.3);
            [$secondRepository, $secondKey] = $order[1];
            $check($secondRepository::state($secondKey)['phase'] === 'failed' && $mailboxTable->count() === 1
                && $mailboxTable->exists($jobs[2]['token']), 'Ошибка одного job не теряет резерв другого боя.');
            // Плохой заголовок должен попасть в прежний per-battle catch,
            // а не уронить общий проход предварительного отбора.
            $invalidKey = $owned[SpaceBattles::class][1];
            SpaceBattles::store($invalidKey, ['id' => str_repeat('a', 32), 'phase' => 'running', 'fleets' => [],
                'startedAt' => 100.0, 'job' => null, 'combat' => ['rounds' => null]]);
            Battles::tick(101.4);
            $check(SpaceBattles::state($invalidKey)['phase'] === 'failed' && $mailboxTable->exists($jobs[2]['token']),
                'Повреждённый running обрабатывается отдельно, соседний job сохранён.');
            $third = $jobs[2]; Battles::tick(162.0);
            $check($thirdRepository::state($thirdKey)['job'] === null && !$mailboxTable->exists($third['token']), 'Timeout освобождает только старый резерв.');
            Battles::executeTask($third);
            $check(!$mailboxTable->exists($third['token']), 'Поздний worker не создаёт строку заново.');
            Battles::tick(162.1);
            $retry = $jobs[count($jobs) - 1];
            $check($thirdRepository::state($thirdKey)['job']['token'] === $retry['token']
                && $retry['token'] !== $third['token'] && $retry['combat'] === $third['combat'] && $retry['seed'] === $third['seed'],
                'Повтор того же раунда: прежний snapshot/seed, новый token.');
        }
    } finally {
        $dispatch->setValue(null, $oldDispatch); $limit->setValue(null, $oldLimit); $mailbox->retain([]); $clearOwn();
        foreach ($repositories as $repository) {
            foreach ($before[$repository][0] as $key => $row) $tables[$repository]->set((string)$key, $row);
            foreach ($before[$repository][1] as $key => $row) $markers[$repository]->set((string)$key, $row);
            $check([$copy($tables[$repository]), $copy($markers[$repository])] === $before[$repository], 'Исходные checkpoint/dirty возвращены.');
        }
        $check(PlayerQueue::getAll() === $queue && $mailboxTable->count() === 0, 'PlayerQueue прежняя, mailbox освобождён.');
    }
    file_put_contents($path, $json(['ok' => true, 'checks' => $checks, 'protected' => $protected, 'samplesPerRepository' => $measurements,
        'newTables' => 0, 'newWorld' => false, 'sqlWrites' => 0, 'originalStateRestored' => true]), LOCK_EX);
    chmod($path, 0600);
};
