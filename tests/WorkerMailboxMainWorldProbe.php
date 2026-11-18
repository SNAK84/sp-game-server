<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{Accounts, Users, PlayerQueue, PlanetBattles, SpaceBattles};
use SPGame\Game\Services\{FleetBattleService as Battles, FleetExpeditionService as Expeditions, FleetCombatService as Combat};

/** Основной dev после initializeWorkers, ДО Server::start/saver.
 * Использует только уже созданные mailbox и QA-checkpoint. Не создаёт Table,
 * мир, SQL-записи или игровые потери. Дочерний процесс — чистый вычислитель.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Mailbox: ' . $message);
        ++$checks;
    };
    $get = static fn(object|string $object, string $name): mixed => (new ReflectionProperty($object, $name))->getValue(is_object($object) ? $object : null);
    $combat = $get(Battles::class, 'results');
    $expedition = $get(Expeditions::class, 'mailbox');
    $mailboxes = [[$combat, Battles::MAILBOX_CAPACITY], [$expedition, Expeditions::MAILBOX_CAPACITY]];
    $check($get($combat, 'table')->count() === 0 && $get($expedition, 'table')->count() === 0, 'Проверка до запуска workers.');
    $check(array_filter([...PlanetBattles::active(), ...SpaceBattles::active()],
        static fn(array $state): bool => $state['phase'] !== 'failed') === [], 'Нет чужих исполняемых checkpoint для tick.');
    $checkpoints = static function () use ($get): array {
        $result = [];
        foreach ([PlanetBattles::class, SpaceBattles::class] as $repository) foreach (['table', 'syncTable'] as $name) {
            $rows = iterator_to_array($get($repository, $name)); ksort($rows); $result[$repository][$name] = $rows;
        }
        return $result;
    };
    $checkpointsBefore = $checkpoints();
    $queueBefore = PlayerQueue::getAll();
    $account = Accounts::findByIndex('login', 'qaown_260924') ?? throw new LogicException('Нет QA.');
    $user = Users::findByIndex('account', (string)$account['id']) ?? throw new LogicException('Нет QA-игрока.');
    $key = (int)$user['current_planet'];
    $rows = $get(PlanetBattles::class, 'table');
    $markers = $get(PlanetBattles::class, 'syncTable');
    $rowBefore = $rows->get((string)$key);
    $markerBefore = $markers->get((string)$key);
    $dispatch = new ReflectionProperty(Battles::class, 'dispatch');
    $oldDispatch = $dispatch->getValue();
    $jobs = [];
    $group = ['type' => 204, 'kind' => 'fleet', 'count' => 10, 'attack' => 1.0,
        'hull' => 10000.0, 'shield' => 0.0, 'unitCost' => 4000.0, 'experience' => 0.0];
    try {
        foreach ($mailboxes as [$mailbox, $capacity]) {
            $table = $get($mailbox, 'table');
            $token = bin2hex(random_bytes(16));
            $check($mailbox->reserve($token) && $mailbox->read($token) === null, 'Пустой физический резерв не считается ответом.');
            try { $mailbox->reserve($token); throw new RuntimeException('Повтор token принят.'); }
            catch (LogicException) { ++$checks; }
            $payload = str_repeat('x', PlanetBattles::MAX_STATE_BYTES);
            $check($mailbox->publish($token, $payload) && $mailbox->read($token) === $payload, 'Предельный ответ262144B не усечён.');
            $check($mailbox->publish($token, $payload), 'Точный дубликат идемпотентен.');
            try { $mailbox->publish($token, 'different'); throw new RuntimeException('Результат перезаписан.'); }
            catch (LogicException) { ++$checks; }
            try { $mailbox->publish($token, $payload . 'x'); throw new RuntimeException('Oversize принят.'); }
            catch (OverflowException) { ++$checks; }
            $check($mailbox->read($token) === $payload, 'Отказ не изменяет принятый ответ.');
            $slots = [$token];
            for ($i = 0; count($slots) < $capacity && $i < $capacity * 100; ++$i) {
                $filler = bin2hex(random_bytes(16));
                if ($mailbox->reserve($filler)) $slots[] = $filler;
            }
            $check(count($slots) === $capacity, 'Настоящий mailbox заполнен до логического бюджета.');
            $extra = bin2hex(random_bytes(16));
            $check(!$mailbox->reserve($extra) && !$table->exists($extra), 'Отказ admission не оставляет строку.');
            $accepted = $slots[1];
            $check($mailbox->publish($accepted, $payload) && $mailbox->read($accepted) === $payload,
                'В полном mailbox есть место для принятого worst-case результата.');
            $mailbox->release($accepted);
            $check(!$mailbox->publish($accepted, 'late') && !$table->exists($accepted), 'Поздний ответ не создаёт строку.');
            $mailbox->retain([$token => true]);
            $check($table->count() === 1 && $mailbox->read($token) === $payload, 'Уборка удаляет все чуждые token, сохраняя текущий.');
            $mailbox->release($token); $mailbox->release($token);
            $check($table->count() === 0, 'Release идемпотентен, нет утечки резерва.');
        }

        // Реальные две функции TaskWorker в дочернем процессе. После fork он
        // видит те же Table/mutex, но не имеет права создавать строку ответа.
        $taskCombat = Combat::startBattle(['a' => $group], ['d' => $group]);
        $taskCombat = Combat::calculateRound($taskCombat, static fn(int $min, int $max): int => $min);
        $childTokens = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
        $late = bin2hex(random_bytes(16));
        $check($combat->reserve($childTokens[0]) && $expedition->reserve($childTokens[1]) && $combat->reserve($late), 'Резервы созданы родителем.');
        $combat->release($late);
        $process = new Swoole\Process(static function (Swoole\Process $worker) use ($taskCombat, $childTokens, $late): void {
            try {
                foreach ([['combat_round', $childTokens[0]], ['expedition_round', $childTokens[1]], ['combat_round', $late]] as [$kind, $token]) {
                    $service = $kind === 'combat_round' ? Battles::class : Expeditions::class;
                    $service::executeTask(Combat::roundTask($kind, $token, str_repeat('01', 32), $taskCombat));
                }
                $worker->exit(0);
            } catch (Throwable) { $worker->exit(1); }
        }, false, 0);
        $pid = $process->start();
        if ($pid === false) throw new RuntimeException('Не запущен вычислитель probe.');
        $deadline = microtime(true) + 5;
        while (($status = Swoole\Process::wait(false)) === false && microtime(true) < $deadline) usleep(10000);
        if ($status === false) {
            Swoole\Process::kill($pid, SIGKILL); Swoole\Process::wait(true);
            throw new RuntimeException('Вычислитель probe не завершился за5с.');
        }
        $check($status['pid'] === $pid && $status['code'] === 0, 'Обе функции worker отработали в отдельном процессе.');
        foreach ([$combat, $expedition] as $i => $mailbox) {
            $reply = json_decode($mailbox->read($childTokens[$i]) ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            $check(count($reply['combat']['rounds'] ?? []) === 1 && $reply['combat']['rounds'][0]['number'] === 2,
                'Дочерний процесс вернул второй раунд без предыдущего залпа.');
            $accepted = $taskCombat;
            Combat::applyTaskRound($accepted, $reply['combat'], 2);
            $random = new Random\Randomizer(new Random\Engine\Xoshiro256StarStar(str_repeat("\x01", 32)));
            $expected = Combat::calculateRound($taskCombat, $random->getInt(...));
            $check($accepted === $expected, 'После применения ответа история и урон совпадают в обоих типах боя.');
            try {
                Combat::applyTaskRound($accepted, $reply['combat'], 2);
                throw new RuntimeException('Дубликат раунда принят дважды.');
            } catch (LogicException) {
                $check($accepted === $expected, 'Повтор результата сохраняет принятую историю.');
            }
            try {
                Combat::applyTaskRound($accepted, $reply['combat'], 3);
                throw new RuntimeException('Ответ с чужим номером раунда принят.');
            } catch (LogicException) {
                $check($accepted === $expected, 'Отказ устаревшему номеру не изменяет бой.');
            }
            $mailbox->release($childTokens[$i]);
        }
        $check($combat->read($late) === null && !$get($combat, 'table')->exists($late), 'Поздний worker не восстановил удалённый резерв.');

        $dispatch->setValue(null, static function (array $task) use (&$jobs): bool { $jobs[] = $task; return true; });
        $battleId = Battles::begin($key, ['a' => $group], ['d' => $group], 100);
        $beforeFull = PlanetBattles::state($key);
        for ($i = 0; $get($combat, 'table')->count() < Battles::MAILBOX_CAPACITY && $i < Battles::MAILBOX_CAPACITY * 100; ++$i) {
            $combat->reserve(bin2hex(random_bytes(16)));
        }
        if ($get($combat, 'table')->count() !== Battles::MAILBOX_CAPACITY) throw new RuntimeException('Не заполнен mailbox для tick.');
        Battles::tick(101);
        $check($jobs === [] && PlanetBattles::state($key) === $beforeFull, 'Полный mailbox не меняет попытки/checkpoint и не dispatch-ит раунд.');
        Battles::tick(101.1);
        $first = $jobs[0];
        $check($get($combat, 'table')->exists($first['token']) && $combat->read($first['token']) === null,
            'Координатор резервирует строку до dispatch.');
        $check(Battles::reinforce($key, $battleId, 'qa-mailbox-late', 'defender', ['late' => $group], 101.15) === 2,
            'Подкрепление не попадает в уже запечатанный раунд.');
        Battles::executeTask($first); Battles::tick(101.2);
        $state = PlanetBattles::state($key);
        $check(count($state['combat']['rounds']) === 1 && $state['job'] === null && count($state['pending']) === 1,
            'Ответ применён один раз, ожидающее подкрепление сохранено.');
        Battles::executeTask($first);
        $check($get($combat, 'table')->count() === 0, 'Повтор старого задания не оставил ответ.');
        Battles::tick(102);
        $second = $jobs[1];
        $check(isset($second['combat']['d']['late']), 'Подкрепление вступило со следующего раунда.');
        Battles::tick(162);
        $check(PlanetBattles::state($key)['job'] === null && !$get($combat, 'table')->exists($second['token']), 'Timeout закрывает старый резерв.');
        Battles::executeTask($second);
        $check($get($combat, 'table')->count() === 0, 'Поздний результат после timeout отброшен.');
        Battles::tick(162.1);
        $retry = $jobs[2];
        $check($retry['seed'] === $second['seed'] && $retry['combat'] === $second['combat'] && $retry['token'] !== $second['token'],
            'Повтор раунда использует тот же seed/snapshot, но новый token.');
        Battles::executeTask($retry); Battles::tick(162.2);
        $check(count(PlanetBattles::state($key)['combat']['rounds']) === 2, 'Раунд после timeout не потерян и не удвоен.');
        $combat->retain([]);
        $state = PlanetBattles::state($key); $state['job'] = null; PlanetBattles::store($key, $state);
        $dispatch->setValue(null, static fn(array $task): bool => false);
        Battles::tick(163);
        $check(PlanetBattles::state($key)['phase'] === 'failed' && $get($combat, 'table')->count() === 0,
            'Отказ доставки остаётся fail-closed и освобождает резерв.');
    } finally {
        $dispatch->setValue(null, $oldDispatch);
        foreach ($mailboxes as [$mailbox]) $mailbox->retain([]);
        if ($rowBefore === false) $rows->del((string)$key); else $rows->set((string)$key, $rowBefore);
        if ($markerBefore === false) $markers->del((string)$key); else $markers->set((string)$key, $markerBefore);
    }
    $check($rows->get((string)$key) === $rowBefore && $markers->get((string)$key) === $markerBefore
        && $checkpoints() === $checkpointsBefore && PlayerQueue::getAll() === $queueBefore,
        'QA и исходные checkpoint/dirty/PlayerQueue восстановлены.');
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks, 'newTables' => 0,
        'childProcess' => true, 'qaRestored' => true, 'sqlWrites' => 0], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
