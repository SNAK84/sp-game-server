<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{PlanetBattles, SpaceBattles};
use SPGame\Game\Services\{FleetAttackService, FleetSpaceBattleService};

/** До сети и saver на основном dev, при отсутствии чужих applied. Только свои записи в RAM. */
return static function (string $path): void {
    if (file_exists($path)) return;
    $checks = 0; $results = [];
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException($message);
        ++$checks;
    };
    foreach ([PlanetBattles::class => FleetAttackService::class, SpaceBattles::class => FleetSpaceBattleService::class] as $repo => $service) {
        $table = (new ReflectionProperty($repo, 'table'))->getValue();
        $sync = (new ReflectionProperty($repo, 'syncTable'))->getValue();
        if ($repo::appliedKeys() !== []) throw new LogicException('Проверке нужны свободные завершённые бои.');
        $keys = range(1901700001, 1901700016);
        foreach ($keys as $key) {
            if ($table->exists((string)$key) || $sync->exists((string)$key)) throw new LogicException('Тестовый ключ занят.');
        }
        $cursor = new ReflectionProperty($repo, 'lastCleanupKey');
        $originalCursor = $cursor->getValue();
        try {
            foreach ($keys as $key) {
                $state = ['id' => str_pad((string)$key, 32, '0', STR_PAD_LEFT), 'phase' => 'applied', 'fleets' => [],
                    'mission' => ['participants' => []], 'resolution' => ['syncRows' => []], 'padding' => ''];
                $state['padding'] = str_repeat('x', $repo::MAX_STATE_BYTES - strlen(json_encode($state, JSON_THROW_ON_ERROR)));
                $repo::store($key, $state);
            }
            $service::cleanupApplied();
            $times = [];
            for ($sample = 0; $sample < 9; ++$sample) {
                $start = hrtime(true);
                $service::cleanupApplied();
                $times[] = (hrtime(true) - $start) / 1000000;
            }
            sort($times);
            $check(count($repo::appliedKeys()) === count($keys), 'До ACK сохранены все завершённые бои.');
            foreach ($keys as $key) {
                $check($sync->get((string)$key, 'need_update') === 1 && strlen($table->get((string)$key, 'state')) === $repo::MAX_STATE_BYTES,
                    'До подтверждения сохранены полная запись и поколение UPDATE.');
            }
            // Подтверждаем только свои записи, одну оставляем ждать UPDATE ACK.
            $updates = array_intersect_key($repo::captureSaveSnapshot(true), array_flip($keys));
            $late = $keys[7];
            $lateUpdate = [$late => $updates[$late]];
            unset($updates[$late]);
            $repo::acknowledgeSaveSnapshot($updates);
            $seen = [];
            foreach ($repo::cleanupCandidates() as $key) {
                $seen[] = $key;
                usleep(4000);
            }
            $check(count($seen) === 1 && $seen[0] !== $late, 'После долгой записи порция уступает управление.');
            $next = $repo::cleanupCandidates()->current();
            $check($next !== null && $next !== $seen[0] && $next !== $late, 'Курсор продолжает со следующей доступной записи.');

            $pending = 0; $requestCalls = 0;
            while ($pending < count($updates) && $requestCalls < 32) {
                ++$requestCalls;
                $service::cleanupApplied();
                $after = count(array_filter($keys, static fn(int $id): bool => $sync->get((string)$id, 'need_delete') === 1));
                $check($after >= $pending && $after - $pending <= 8, 'Порция ставит на удаление не более восьми подтверждённых боёв.');
                $pending = $after;
            }
            $check($pending === count($updates) && $sync->get((string)$late, 'need_update') === 1,
                'Одна неподтверждённая запись не задержала очистку остальных.');
            $service::cleanupApplied();
            $check(count($repo::appliedKeys()) === count($keys), 'До DELETE ACK слоты остаются заняты.');
            $deletes = array_intersect_key($repo::captureSaveSnapshot(true), $updates);
            $repo::acknowledgeSaveSnapshot($deletes);
            $finishCalls = 0;
            while (count($repo::appliedKeys()) > 1 && $finishCalls < 32) {
                ++$finishCalls;
                $service::cleanupApplied();
            }
            $check($repo::appliedKeys() === [$late], 'Все подтверждённые удаления освободили слоты.');
            $repo::acknowledgeSaveSnapshot($lateUpdate);
            $service::cleanupApplied();
            $check($sync->get((string)$late, 'need_delete') === 1, 'Курсор вернулся к поздно подтверждённому бою.');
            $repo::acknowledgeSaveSnapshot([$late => $repo::captureSaveSnapshot(true)[$late]]);
            $service::cleanupApplied();
            $check($repo::appliedKeys() === [] && !$sync->exists((string)$late), 'После последнего ACK освобождены все слоты.');
            $results[(new ReflectionClass($repo))->getShortName()] = ['dirtyMedianMs' => $times[4],
                'requestCalls' => $requestCalls, 'finishCalls' => $finishCalls];
        } finally {
            $cursor->setValue(null, $originalCursor);
            foreach ($keys as $key) {
                if ($table->exists((string)$key)) $table->del((string)$key);
                if ($sync->exists((string)$key)) $sync->del((string)$key);
            }
        }
    }
    file_put_contents($path, json_encode(['checks' => $checks, 'results' => $results], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
