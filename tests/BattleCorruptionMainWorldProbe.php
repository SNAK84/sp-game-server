<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{PlanetBattles, PlayerQueue, SpaceBattles};
use SPGame\Game\Services\FleetBattleService as Battles;

/** Только bootstrap основного dev после initializeWorkers, до сети/saver.
 * Свои строки в существующих Table; без Fleet, игроков, SQL и нового мира.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Battle corruption: ' . $message);
        ++$checks;
    };
    $get = static fn(string|object $class, string $name): mixed => (new ReflectionProperty($class, $name))->getValue(is_object($class) ? $class : null);
    $copy = static function (Swoole\Table $table): array { $rows = iterator_to_array($table); ksort($rows); return $rows; };
    $repositories = [PlanetBattles::class, SpaceBattles::class];
    $tables = $markers = $before = $owned = [];
    foreach ($repositories as $r => $repository) {
        foreach ($repository::iterateActive() as $state) if ($state['phase'] !== 'failed') throw new LogicException('Есть исполняемый чужой бой.');
        $tables[$repository] = $get($repository, 'table'); $markers[$repository] = $get($repository, 'syncTable');
        $before[$repository] = [$copy($tables[$repository]), $copy($markers[$repository])];
        $owned[$repository] = range(1901800000 + $r * 100, 1901800003 + $r * 100);
        foreach ($owned[$repository] as $key) if ($tables[$repository]->exists((string)$key) || $markers[$repository]->exists((string)$key)) {
            throw new LogicException('Ключ probe занят.');
        }
    }
    $mailbox = $get(Battles::class, 'results'); $mailboxTable = $get($mailbox, 'table');
    if ($mailboxTable->count() !== 0) throw new LogicException('Mailbox занят до сети.');
    $queue = PlayerQueue::getAll();
    $dispatch = new ReflectionProperty(Battles::class, 'dispatch'); $oldDispatch = $dispatch->getValue();
    $limit = new ReflectionProperty(Battles::class, 'workerLimit'); $oldLimit = $limit->getValue();
    $jobs = [];
    $clear = static function () use ($owned, $tables, $markers, $mailbox, &$jobs): void {
        foreach ($owned as $repository => $keys) foreach ($keys as $key) {
            if ($tables[$repository]->exists((string)$key)) $tables[$repository]->del((string)$key);
            if ($markers[$repository]->exists((string)$key)) $markers[$repository]->del((string)$key);
        }
        $mailbox->retain([]); $jobs = [];
    };
    $group = ['type' => 204, 'kind' => 'fleet', 'count' => 10, 'attack' => 1.0,
        'hull' => 10000.0, 'shield' => 0.0, 'unitCost' => 4000.0, 'experience' => 0.0];
    $begin = static function (string $repository, int $key) use ($group): void {
        if ($repository === PlanetBattles::class) Battles::begin($key, ['a' => $group], ['d' => $group], 100.0);
        else Battles::beginSpace($key, ['a' => $group], ['d' => $group], 100.0);
    };
    try {
        $limit->setValue(null, 2);
        $dispatch->setValue(null, static function (array $task) use (&$jobs): bool { $jobs[] = $task; return true; });
        $unknown = str_repeat('e', 32); $other = str_repeat('f', 32);
        $mailbox->reserve($unknown); $mailbox->reserve($other);
        $mailbox->publish($unknown, str_repeat('x', PlanetBattles::MAX_STATE_BYTES));
        $memory = memory_get_usage(); memory_reset_peak_usage(); $tokens = $mailbox->tokens();
        $tokenPeak = memory_get_peak_usage() - $memory;
        $check(count($tokens) === 2 && isset($tokens[$unknown], $tokens[$other]), 'Снимок всех резервов, в том числе готовых ответов.');
        $check($tokenPeak < 65536, 'Снимок token не копирует payload262144B.');
        $clear();
        $cases = [['running', '{'], ['finished', '{'], ['settled', '{'], ['failed', '{'],
            ['running', '"invalid array"'], ['running', 'null'], ['running', '[]'],
            ['running', '{"phase":"running"}'], ['running', '{"phase":"running","job":[]}'],
            ['running', '{"phase":"failed","job":null}']];
        foreach ($repositories as $r => $repository) foreach ($cases as [$phase, $raw]) {
            $key = $owned[$repository][0]; $healthy = $repositories[1 - $r];
            $begin($repository, $key);
            $tables[$repository]->set((string)$key, ['phase' => $phase, 'state' => $raw]);
            $badRow = $tables[$repository]->get((string)$key); $badMarker = $markers[$repository]->get((string)$key);
            // Прежний общий generator всё ещё строгий: воспроизводим сам источник
            // JsonException/TypeError, не ослабляя lookup/защиту чужого боя.
            if ($raw === '{' || $raw === '"invalid array"') {
                $thrown = false;
                try { $repository::state($key); } catch (JsonException|TypeError) { $thrown = true; }
                $check($thrown, 'Повреждение действительно не читается штатным state.');
            } else {
                $check($repository::findExistingById($key)['state'] === $raw, 'Подставлен валидный JSON с недостоверным header.');
            }
            $begin($healthy, $owned[$healthy][1]); $begin($healthy, $owned[$healthy][2]);
            $mailbox->reserve($unknown);
            $affected = Battles::tick(101.0);
            $check($affected === [] && count($jobs) === 1 && $mailboxTable->count() === 2,
                'Повреждение изолировано; неизвестный резерв занимает одно из двух мест.');
            $check($tables[$repository]->get((string)$key) === $badRow && $markers[$repository]->get((string)$key) === $badMarker,
                'Исходные bytes/phase/dirty повреждённой строки не изменены.');
            Battles::tick(101.1);
            $check(count($jobs) === 1 && $mailboxTable->exists($unknown), 'Повтор не дублирует jobs и не удаляет неизвестный резерв.');
            Battles::executeTask($jobs[0]); Battles::tick(101.2);
            $check(count($jobs) === 2 && $mailboxTable->count() === 2 && $mailboxTable->exists($unknown),
                'Известный token считается один раз; освободившееся место передано следующему бою.');
            $rounds = 0;
            foreach ([1, 2] as $i) $rounds += count($healthy::state($owned[$healthy][$i])['combat']['rounds']);
            $check($rounds === 1, 'Соседний исправный бой применил ровно один раунд.');
            // После ручного восстановления checkpoint обычный retain снова
            // освобождает orphan, не трогая выполняющийся исправный job.
            $repository::store($key, ['id' => str_repeat('a', 32), 'phase' => 'failed', 'fleets' => [], 'job' => null]);
            Battles::tick(101.3);
            $check(!$mailboxTable->exists($unknown) && $mailboxTable->count() === 1
                && $mailboxTable->exists($jobs[1]['token']), 'После восстановления обычный GC возобновлён.');
            $clear();
        }
        // Оба неизвестных резерва могли принадлежать нечитаемому running.
        $key = $owned[PlanetBattles::class][0]; $begin(PlanetBattles::class, $key);
        $tables[PlanetBattles::class]->set((string)$key, ['state' => '{']);
        $begin(SpaceBattles::class, $owned[SpaceBattles::class][1]);
        $mailbox->reserve($unknown); $mailbox->reserve($other);
        Battles::tick(101.0);
        $check($jobs === [] && $mailboxTable->count() === 2, 'Заполненный неизвестными job pool не превышает workerLimit.');
        $clear();
        // Чтение было полным при отборе, но JSON повредился во время dispatch:
        // финальный проход тоже обязан изолировать его и не очищать mailbox.
        $key = $owned[PlanetBattles::class][0]; $begin(PlanetBattles::class, $key);
        $dispatch->setValue(null, static function (array $task) use (&$jobs, $tables, $key): bool {
            $jobs[] = $task;
            $tables[PlanetBattles::class]->set((string)$key, ['state' => '{']);
            return true;
        });
        Battles::tick(101.0);
        $check(count($jobs) === 1 && $mailboxTable->exists($jobs[0]['token']), 'Повреждение перед retain не теряет резерв job.');
        Battles::executeTask($jobs[0]);
        $check($mailbox->read($jobs[0]['token']) !== null, 'Поздний чистый вычислитель может опубликовать ответ в сохранённый резерв.');
    } finally {
        $dispatch->setValue(null, $oldDispatch); $limit->setValue(null, $oldLimit); $clear();
        foreach ($repositories as $repository) {
            foreach ($before[$repository][0] as $key => $row) $tables[$repository]->set((string)$key, $row);
            foreach ($before[$repository][1] as $key => $row) $markers[$repository]->set((string)$key, $row);
            $check([$copy($tables[$repository]), $copy($markers[$repository])] === $before[$repository], 'Исходные checkpoint/dirty возвращены.');
        }
        $check(PlayerQueue::getAll() === $queue && $mailboxTable->count() === 0, 'PlayerQueue прежняя, mailbox освобождён.');
    }
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks, 'tokenSnapshotPeakBytes' => $tokenPeak,
        'newTables' => 0, 'newWorld' => false, 'sqlWrites' => 0, 'originalStateRestored' => true], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
