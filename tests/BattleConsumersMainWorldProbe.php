<?php
declare(strict_types=1);

use SPGame\Core\Message;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{Accounts, BattleReports, PlanetBattles, PlayerQueue, SpaceBattles, Users};
use SPGame\Game\Services\{AccountData, FleetAttackService, FleetSpaceBattleService, RepositorySaver};

/** Только bootstrap main до сети/saver. Существующие Table, свои checkpoint
 * и нейтральные доклады без участников/потерь. ACK только своих новых докладов
 * моделируется при удалении. SQL/newTable/newWorld0, исходные записи возвращаются.
 */
return static function (string $path, bool $protected = true): void {
    if (file_exists($path)) return;
    $checks = 0; $measurements = [];
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Battle consumers: ' . $message);
        ++$checks;
    };
    $get = static fn(string|object $class, string $name): mixed => (new ReflectionProperty($class, $name))->getValue(is_object($class) ? $class : null);
    $copy = static function (Swoole\Table $table): array { $rows = iterator_to_array($table); ksort($rows); return $rows; };
    $repositories = [PlanetBattles::class, SpaceBattles::class];
    $services = [FleetAttackService::class, FleetSpaceBattleService::class];
    $tables = $sync = $before = $owned = $cleanupBefore = [];
    foreach ($repositories as $r => $repository) {
        foreach ($repository::iterateActive() as $state) if ($state['phase'] !== 'failed') throw new LogicException('Есть чужой исполняемый бой.');
        $tables[$repository] = $get($repository, 'table'); $sync[$repository] = $get($repository, 'syncTable');
        $before[$repository] = [$copy($tables[$repository]), $copy($sync[$repository])]; $owned[$repository] = [];
        $cleanupBefore[$services[$r]] = $get($services[$r], 'nextCleanupAt');
    }
    $queue = PlayerQueue::getAll(); $reportIds = [];
    $reports = $get(BattleReports::class, 'reports'); $reportBefore = $reports->snapshot(true);
    $reportCount = $get($reports, 'rows')->count();
    $qa = Accounts::findByIndex('login', 'qaown_260924') ?? throw new LogicException('Нет QA.');
    $user = Users::findByIndex('account', (string)$qa['id']) ?? throw new LogicException('Нет QA-игрока.');
    if ((int)$user['current_planet'] <= 0) throw new LogicException('Нет базы QA.');
    $account = new AccountData((int)$qa['id']); $page = new FleetsPage(new Message());
    $build = new ReflectionMethod(FleetsPage::class, 'buildFleetList');
    $normalize = static function (array $rows): array {
        foreach ($rows as &$row) unset($row['standing']); // Только непрерывно меняющийся прогноз времени стоянки.
        return $rows;
    };
    $basePage = $normalize($build->invokeArgs($page, [&$account]));
    $json = static fn(array $state): string => json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $clearOwn = static function () use ($tables, $sync, &$owned): void {
        foreach ($owned as $repository => $keys) foreach ($keys as $key) {
            if ($tables[$repository]->exists((string)$key)) $tables[$repository]->del((string)$key);
            if ($sync[$repository]->exists((string)$key)) $sync[$repository]->del((string)$key);
        }
    };
    $measure = static function (callable $read): array {
        gc_collect_cycles(); $memory = memory_get_usage(); memory_reset_peak_usage();
        $result = $read();
        return [$result, memory_get_peak_usage() - $memory];
    };
    try {
        foreach ([4, 16] as $count) {
            foreach ($repositories as $r => $repository) for ($i = count($owned[$repository]); $i < $count; ++$i) {
                $key = 1901700000 + $r * 100 + $i;
                if ($tables[$repository]->exists((string)$key) || $sync[$repository]->exists((string)$key)) throw new LogicException('Ключ probe занят.');
                $state = ['id' => str_pad((string)$key, 32, '0', STR_PAD_LEFT), 'phase' => 'running', 'job' => null,
                    'fleets' => [$key], 'combat' => ['rounds' => []], 'padding' => ''];
                $state['padding'] = str_repeat('x', $repository::MAX_STATE_BYTES - strlen($json($state)));
                $repository::store($key, $state); $owned[$repository][] = $key;
            }
            foreach ($services as $service) {
                [$result, $bytes] = $measure(static fn(): array => $service::process(100.0));
                $check($result === [], 'Незавершённые бои не применяются.'); $measurements[$service][$count] = $bytes;
            }
            [$result, $bytes] = $measure(static fn(): array => $build->invokeArgs($page, [&$account]));
            $check($normalize($result) === $basePage, 'Посторонние checkpoint не меняют DTO флотов QA.');
            $measurements['FleetsPage'][$count] = $bytes;
        }
        foreach ($measurements as $samples) {
            $growth = $samples[16] - $samples[4];
            $check($protected ? $growth < 1048576 : $growth > 2097152,
                $protected ? 'Нет коллекции12 лишних JSON.' : 'Воспроизведён рост памяти от полной коллекции.');
        }
        $clearOwn();
        if ($protected) {
            $projection = new ReflectionMethod(FleetsPage::class, 'buildFleetBattleStates');
            $planetKey = $owned[PlanetBattles::class][0]; $spaceKey = $owned[SpaceBattles::class][0];
            $ids = [1901800001, 1901800002, 1901800003];
            PlanetBattles::store($planetKey, ['id' => str_repeat('1', 32), 'phase' => 'failed', 'fleets' => [$ids[0], $ids[1]],
                'combat' => ['rounds' => [[], []], 'roundLimit' => 10], 'job' => null]);
            SpaceBattles::store($spaceKey, ['id' => str_repeat('2', 32), 'phase' => 'running', 'fleets' => [$ids[0]],
                'combat' => ['rounds' => [[]], 'roundLimit' => 8], 'job' => ['token' => 'qa']]);
            $expected = [$ids[0] => ['phase' => 'running', 'round' => 2, 'rounds_max' => 8],
                $ids[1] => ['phase' => 'failed', 'round' => 2, 'rounds_max' => 10]];
            $check($projection->invoke($page, $ids) === $expected, 'Три публичных поля, Space-приоритет и только запрошенные Fleet.');
            $check($projection->invoke($page, []) === [], 'Пустая страница не читает checkpoint.');
            $state = SpaceBattles::state($spaceKey); $state['phase'] = 'applied'; SpaceBattles::store($spaceKey, $state);
            $view = $projection->invoke($page, [$ids[0]]);
            $check($view === [$ids[0] => $expected[$ids[1]]], 'Applied не перекрывает планетарный бой.');
            $state = PlanetBattles::state($planetKey); $state['fleets'] = [(string)$ids[1]]; PlanetBattles::store($planetKey, $state);
            $check($projection->invoke($page, [$ids[1]]) === [], 'Строгое сравнение типа Fleet ID сохранено.');
            $clearOwn();
            foreach ($repositories as $r => $repository) {
                $service = $services[$r]; $keys = $owned[$repository];
                $tables[$repository]->set((string)$keys[0], ['id' => $keys[0], 'phase' => 'finished', 'state' => '{']);
                foreach (['finished', 'settled'] as $i => $phase) {
                    $id = bin2hex(random_bytes(16)); $reportIds[] = $id;
                    $check($reports->get($id) === null, 'Доклад probe не перезаписывает существующий.');
                    BattleReports::prepare(['version' => 1, 'battleId' => $id, 'finishedAt' => microtime(true), 'participants' => []], ['qa' => 'B047-consumers']);
                    $resolution = ['dead' => [], 'engaged' => [], 'retargetedFleets' => [], 'finishedAt' => 101.0, 'syncRows' => [], 'debris' => []];
                    $state = ['id' => $id, 'phase' => $phase, 'fleets' => [], 'job' => null,
                        'mission' => ['attackerId' => 0, 'defenderId' => 0, 'participants' => [], 'waiting' => [], 'moonRules' => []],
                        'resolution' => $resolution];
                    if ($phase === 'finished') $state['application'] = ['changes' => [], 'resolution' => $resolution];
                    $repository::store($keys[$i + 1], $state);
                }
                $check($repository::settlementKeys() === array_values(array_filter($repository::activeKeys(),
                    static fn(int $key): bool => in_array($key, array_slice($keys, 0, 3), true))), 'Metadata-выбор finished/settled в native-порядке.');
                $check($service::process(101.0) === [], 'Ошибка JSON одного боя не прерывает применение соседних.');
                $check($tables[$repository]->get((string)$keys[0], 'state') === '{', 'Повреждённый checkpoint не стирается.');
                foreach (array_slice($keys, 1, 2) as $key) {
                    $state = $repository::state($key);
                    $check($state['phase'] === 'applied' && !isset($state['application'])
                        && $reports->get($state['id'])['ready'] === 1, 'Finished/settled опубликован и освобождён без игровых потерь.');
                }
                $tables[$repository]->del((string)$keys[0]);
                $check($service::process(101.1) === [] && $repository::settlementKeys() === [], 'Повтор не применяет результат снова.');
                $clearOwn();
            }
        }
    } finally {
        $clearOwn();
        foreach ($repositories as $r => $repository) {
            foreach ($before[$repository][0] as $key => $row) $tables[$repository]->set((string)$key, $row);
            foreach ($before[$repository][1] as $key => $row) $sync[$repository]->set((string)$key, $row);
            (new ReflectionProperty($services[$r], 'nextCleanupAt'))->setValue(null, $cleanupBefore[$services[$r]]);
            $check([$copy($tables[$repository]), $copy($sync[$repository])] === $before[$repository], 'Исходные checkpoint/dirty возвращены.');
        }
        foreach ($reportIds as $id) $reports->delete($id);
        $own = array_fill_keys($reportIds, true); $deletes = [];
        foreach ($reports->snapshot(true) as $slot => $entry) if (isset($own[RepositorySaver::unpackSnapshotEntry($entry)['key']])) $deletes[$slot] = $entry;
        $reports->acknowledge($deletes); // Только новые, никогда не сохранённые fixture.
        $check($reports->snapshot(true) === $reportBefore && $get($reports, 'rows')->count() === $reportCount, 'Доклады/dirty/блоки освобождены без SQL.');
        $check(PlayerQueue::getAll() === $queue, 'PlayerQueue прежняя.');
    }
    file_put_contents($path, $json(['ok' => true, 'checks' => $checks, 'protected' => $protected, 'peakBytes' => $measurements,
        'newWorld' => false, 'newTables' => 0, 'sqlWrites' => 0, 'originalStateRestored' => true]), LOCK_EX);
    chmod($path, 0600);
};
