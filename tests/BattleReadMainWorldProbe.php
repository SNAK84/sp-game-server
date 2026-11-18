<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{PlanetBattles, PlayerQueue, SpaceBattles};
use SPGame\Game\Services\{FleetAttackService, ResourceBaseService};

/** Только bootstrap основного dev до сети/saver. Существующие Table, свои
 * ключи, без Fleet/игроков/SQL. finally возвращает строки и dirty; служебные
 * generation монотонны. Измеряется временный PHP heap, не native RAM/SLO.
 */
return static function (string $path, bool $protected = true, bool $batched = true): void {
    if (file_exists($path)) return;
    $checks = 0; $measurements = []; $siteMeasurements = []; $fleetMeasurements = [];
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Battle read: ' . $message);
        ++$checks;
    };
    $copy = static function (Swoole\Table $table): array { $rows = iterator_to_array($table); ksort($rows); return $rows; };
    $queue = PlayerQueue::getAll();
    $newLookup = static function () use ($batched): Closure {
        if (!$batched) return static fn(int $id): bool => ResourceBaseService::battleAt($id) !== null;
        $reader = (new ReflectionMethod(ResourceBaseService::class, 'battlePresenceReader'))->invoke(null);
        if (!$reader instanceof Closure) throw new LogicException('Нет scoped reader боёв ресурса.');
        return $reader;
    };
    foreach ($protected ? [SpaceBattles::class, PlanetBattles::class] : [SpaceBattles::class] as $repository) {
        $table = (new ReflectionProperty($repository, 'table'))->getValue();
        $sync = (new ReflectionProperty($repository, 'syncTable'))->getValue();
        $before = [$copy($table), $copy($sync)]; $owned = [];
        $name = (new ReflectionClass($repository))->getShortName();
        $base = $repository === SpaceBattles::class ? 1901400000 : 1901400100;
        $json = static fn(array $state): string => json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $peak = static function (callable $read): array {
            gc_collect_cycles(); $memory = memory_get_usage(); memory_reset_peak_usage();
            $result = $read();
            return [$result, memory_get_peak_usage() - $memory];
        };
        try {
            foreach ([4, 16] as $count) {
                for ($i = count($owned); $i < $count; ++$i) {
                    $key = $base + $i;
                    if ($table->exists((string)$key) || $sync->exists((string)$key)) throw new LogicException('Ключ probe занят.');
                    $state = ['id' => str_pad((string)$key, 32, '0', STR_PAD_LEFT), 'phase' => 'running',
                        'fleets' => [$key], 'job' => null, 'mission' => ['resourceSite' => ['id' => $key]], 'padding' => ''];
                    $state['padding'] = str_repeat('x', $repository::MAX_STATE_BYTES - strlen($json($state)));
                    $repository::store($key, $state); $owned[] = $key;
                }
                [$result, $bytes] = $peak(static fn(): ?array => $repository::forFleet(1901499999));
                $check($result === null, 'Несуществующий Fleet не найден.');
                $measurements[$name][$count] = $bytes;
                if ($protected && $repository === SpaceBattles::class) {
                    // Автозагрузка большого сервиса не является памятью reader.
                    // Прогреваем отдельный экземпляр, не будущий timed-курсор.
                    $warmup = $newLookup(); $warmup(1901499999); unset($warmup);
                    $durations = []; $maxPeak = 0; $retained = 0; $absent = true;
                    for ($sample = 0; $sample < 9; ++$sample) {
                        $lookup = $newLookup();
                        gc_collect_cycles(); $memory = memory_get_usage(); memory_reset_peak_usage(); $started = hrtime(true);
                        for ($query = 0; $query < 16; ++$query) $absent = !$lookup(1901499000 + $query) && $absent;
                        $elapsed = (hrtime(true) - $started) / 1000000;
                        $retained = max($retained, memory_get_usage() - $memory);
                        $maxPeak = max($maxPeak, memory_get_peak_usage() - $memory);
                        $durations[] = $elapsed; unset($lookup);
                    }
                    sort($durations);
                    $siteMeasurements[$count] = ['elapsedMs' => $durations[4], 'peakBytes' => $maxPeak,
                        'retainedBytes' => $retained, 'samples' => 9, 'queriesPerSample' => 16, 'sortedSamplesMs' => $durations];
                    $check($absent, 'Все16 отсутствующих siteId не заняты боем.');
                    $check($retained < 65536, 'Между lookup не удерживаются полные combat JSON.');
                    foreach (['single', 'scoped'] as $mode) {
                        $times = [];
                        for ($sample = 0; $sample < 5; ++$sample) {
                            $inBattle = $mode === 'scoped' ? SpaceBattles::fleetPresenceReader() : null;
                            $decisions = [];
                            $start = hrtime(true);
                            foreach (array_slice($owned, 0, $count) as $fleetId) {
                                $decisions[] = FleetAttackService::mustWait(['fleet_id' => $fleetId, 'command_type' => 0], $inBattle);
                                $decisions[] = FleetAttackService::mustWait(['fleet_id' => $fleetId + 5000, 'command_type' => 0], $inBattle);
                            }
                            $times[] = (hrtime(true) - $start) / 1e6;
                            $check($decisions === array_merge(...array_fill(0, $count, [true, false])),
                                'Планировщик задерживает только участников боя.');
                        }
                        sort($times);
                        $fleetMeasurements[$count][$mode] = $times[2];
                    }
                }
            }
            $growth = $measurements[$name][16] - $measurements[$name][4];
            $check($protected ? $growth < 1048576 : $growth > 2097152,
                $protected ? 'Пик поиска не растёт на сумму12 JSON.' : 'Воспроизведён рост PHP heap от12 лишних JSON.');
            if (!$protected) continue;
            $all = $repository::active();
            $keys = $repository::activeKeys();
            $check(array_keys($all) === $keys, 'Порядок и ключи active сохранены.');
            $check(iterator_to_array($repository::iterateActive()) === $all, 'Потоковое чтение совместимо с active.');
            $check($repository::runningKeys() === array_keys(array_filter($all,
                static fn(array $state): bool => $state['phase'] === 'running')), 'Running metadata сохраняет native-порядок.');
            $check($repository::nonRunningKeys() === array_keys(array_filter($all,
                static fn(array $state): bool => $state['phase'] !== 'running')), 'Остальные active отделены без JSON-фильтра.');
            foreach ($owned as $key) {
                $found = $repository::forFleet($key);
                $check($found === ['key' => $key, 'state' => $all[$key]], 'Точный key/state для Fleet.');
            }
            if ($repository === SpaceBattles::class) {
                $key = $owned[0];
                $check(ResourceBaseService::battleAt($key) === ['key' => $key, 'state' => $all[$key]], 'Точный бой ресурсной базы.');
                $check(ResourceBaseService::battleAt(1901499999) === null, 'Несуществующая ресурсная база не найдена.');
            }
            unset($all);
            // Вложенный lookup не должен перематывать внешний native iterator.
            $visited = [];
            foreach ($repository::iterateActive() as $key => $state) {
                $repository::activeKeys(); $repository::appliedKeys();
                $repository::forFleet(1901499999); $visited[] = $key;
            }
            $check($visited === $keys, 'Вложенные обходы не теряют записи и не зацикливаются.');
            $ownKeys = array_values(array_intersect($keys, $owned));
            $first = $ownKeys[0]; $last = $ownKeys[count($ownKeys) - 1];
            $row = $table->get((string)$last);
            $table->set((string)$last, ['state' => '{']);
            $check($repository::forFleet($first)['key'] === $first, 'После совпадения последующие JSON не читаются.');
            $bad = false;
            try { $repository::forFleet(1901499999); } catch (JsonException) { $bad = true; }
            $check($bad, 'Ошибка посещённого JSON не скрывается.');
            if ($repository === SpaceBattles::class) {
                $lookup = $newLookup();
                $inBattle = SpaceBattles::fleetPresenceReader();
                $check($inBattle($first), 'Раннее совпадение флота перед повреждённой записью.');
                foreach ([1901499998, 1901499999] as $missingFleet) {
                    $bad = false;
                    try { $inBattle($missingFleet); } catch (JsonException) { $bad = true; }
                    $check($bad && $inBattle($first), 'Повтор ошибки и доступ к ранее найденному участнику.');
                }
                $check($lookup($first), 'Scoped lookup останавливается до повреждённого позднего JSON.');
                $bad = false;
                try { $lookup(1901499999); } catch (JsonException) { $bad = true; }
                $check($bad, 'Scoped lookup не скрывает посещённую ошибку JSON.');
                $check($lookup($first), 'Ранее найденный site до ошибки остаётся доступен.');
                $bad = false;
                try { $lookup(1901499998); } catch (JsonException) { $bad = true; }
                $check($bad, 'После ошибки курсор не перескакивает через плохую строку.');
            }
            $table->set((string)$last, ['phase' => 'applied']);
            $check(!in_array($last, $repository::activeKeys(), true) && $repository::forFleet($last) === null,
                'Applied исключён по metadata до чтения JSON.');
            $check(in_array($last, $repository::appliedKeys(), true), 'Applied-селектор совместим.');
            $table->set((string)$last, $row);
            if ($repository === SpaceBattles::class) {
                $left = $newLookup(); $right = $newLookup();
                $check($left($first) && $right($last) && $left($last), 'Два scoped reader и native iterator независимы.');
                unset($left, $right, $lookup);
            }
            $row = $table->get((string)$first);
            $changed = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
            $changed['padding'] = substr($changed['padding'], 0, -128); // Место для более длинных phase.
            $changed['fleets'] = [$first + 2000];
            $repository::store($first, $changed);
            $check($repository::forFleet($first) === null && $repository::forFleet($first + 2000)['key'] === $first,
                'Новое подкрепление видно сразу, без устаревшего кэша.');
            foreach (['failed', 'finished', 'settled', 'applied'] as $phase) {
                $changed['phase'] = $phase; $repository::store($first, $changed);
                $found = $repository::forFleet($first + 2000);
                $check($phase === 'applied' ? $found === null : $found['state']['phase'] === $phase, 'Фаза ' . $phase . ' сохранена.');
                $check(!in_array($first, $repository::runningKeys(), true), 'Фаза ' . $phase . ' не рассчитывает раунд.');
                $check(in_array($first, $repository::nonRunningKeys(), true) === ($phase !== 'applied'),
                    'Фаза ' . $phase . ' в остальных active без applied.');
                if ($repository === SpaceBattles::class) {
                    $lookup = $newLookup();
                    $check($lookup($first) === ($phase !== 'applied'), 'Новый reader видит фазу ' . $phase . '.');
                    $inBattle = SpaceBattles::fleetPresenceReader();
                    $check(!$inBattle($first) && $inBattle($first + 2000) === ($phase !== 'applied'),
                        'Свежая проверка видит подкрепление и текущую фазу.');
                }
            }
            if ($repository === SpaceBattles::class) {
                $changed['phase'] = 'running'; $changed['mission']['resourceSite']['id'] = (string)($first + 3000);
                $repository::store($first, $changed); $lookup = $newLookup();
                $check(!$lookup($first) && $lookup($first + 3000), 'Новая операция видит изменённый siteId с прежним int-приведением.');
                unset($lookup);
                unset($changed['padding']);
                $changed['fleets'] = [(string)$first, $first + 2800, $first + 2800];
                $repository::store($first, $changed);
                $inBattle = SpaceBattles::fleetPresenceReader();
                $check(!$inBattle($first) && $inBattle($first + 2800), 'Строковый ID не совпадает с числом, повторы допустимы.');
                $changed['fleets'] = range($first + 10000, $first + 15000);
                $repository::store($first, $changed);
                $inBattle = SpaceBattles::fleetPresenceReader();
                $check($inBattle($first + 15000) && !$inBattle($first) && $inBattle($last),
                    'При превышении бюджета поиска учитываются весь состав и остальные бои.');
                $changed['fleets'] = 'invalid';
                $repository::store($first, $changed);
                $inBattle = SpaceBattles::fleetPresenceReader();
                foreach ([1, 2] as $attempt) {
                    $bad = false;
                    try { $inBattle($first); } catch (TypeError) { $bad = true; }
                    $check($bad, 'Повреждённый список участников останавливает каждый поиск.');
                }
            }
        } finally {
            foreach ($owned as $key) {
                if ($table->exists((string)$key)) $table->del((string)$key);
                if ($sync->exists((string)$key)) $sync->del((string)$key);
            }
            foreach ($before[0] as $key => $row) $table->set((string)$key, $row);
            foreach ($before[1] as $key => $row) $sync->set((string)$key, $row);
            $check([$copy($table), $copy($sync)] === $before, 'Исходные строки/markers восстановлены.');
        }
    }
    $check(PlayerQueue::getAll() === $queue, 'PlayerQueue прежняя.');
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks, 'protected' => $protected,
        'peakBytes' => $measurements, 'siteLookups' => $siteMeasurements, 'fleetLookupMedianMs' => $fleetMeasurements, 'batched' => $batched,
        'newWorld' => false, 'newTables' => 0, 'sqlWrites' => 0,
        'originalStateRestored' => true], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
