<?php
declare(strict_types=1);

use SPGame\Core\{Environment, RuntimeMetrics};
use SPGame\Game\Repositories\{ProtocolCommands, RuntimeRecords, PlayerQueue};

/** После GameProtocolV2::initialize, до сети/saver основного dev: callbacks не меняют строки.
 * Только существующий экземпляр RuntimeRecords; нового мира/Table/fixture нет.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new RuntimeException('TTL: ' . $label);
        ++$checks;
    };
    $env = new ReflectionProperty(Environment::class, 'env');
    $beforeEnv = $env->getValue();
    try {
        foreach (['development', 'target'] as $profile) {
            $env->setValue(null, array_replace($beforeEnv, ['SP_GAME_CAPACITY_PROFILE' => $profile]));
            foreach (['ProtocolCommands' => 512, 'BattleReports' => 128] as $name => $minimum) {
                $rows = RuntimeRecords::layout($name)['rows'];
                $budget = RuntimeRecords::sweepBudget($name, $minimum);
                $check($budget === min($rows, max($minimum, (int)ceil($rows / 300))), 'Порция соответствует ёмкости.');
                $check((int)ceil($rows / $budget) <= 300, 'Идеальный обход не более300 вызовов.');
                if ($profile === 'development') $check($budget === $minimum, 'Порция development прежняя.');
            }
        }
    } finally { $env->setValue(null, $beforeEnv); }
    $metricKeys = array_merge((new ReflectionClass(RuntimeMetrics::class))->getConstant('KEYS'),
        (new ReflectionClass(RuntimeMetrics::class))->getConstant('GAUGES'));
    $check(count($metricKeys) <= 64 && count(array_unique($metricKeys)) === count($metricKeys), 'Метрики помещаются в прежние64 слота.');

    $records = (new ReflectionProperty(ProtocolCommands::class, 'records'))->getValue();
    $get = static fn(object $object, string $name): mixed => (new ReflectionProperty($object, $name))->getValue($object);
    $tableState = static function (object $object, string $name) use ($get): array {
        $rows = iterator_to_array($get($object, $name));
        ksort($rows);
        return $rows;
    };
    // Полные credentials/payload остаются только в памяти, результат содержит флаги.
    $snapshot = static fn(): array => [$tableState($records, 'rows'), $tableState($records, 'keys'),
        $tableState($records, 'dirty'), $tableState($get($records, 'bodies'), 'blocks')];
    $before = $snapshot();
    $sample = null;
    foreach ($before[0] as $slot => $row) {
        if (!$row['deleted'] && $row['expires_at'] > microtime(true)) { $sample = [(int)$slot, $row]; break; }
    }
    if ($sample === null) throw new LogicException('Для callback-проверки нужна хотя бы одна существующая квитанция.');
    $position = new ReflectionProperty($records, 'sweep');
    $started = new ReflectionProperty($records, 'sweepPassStartedNs');
    $lastPass = new ReflectionProperty($records, 'sweepLastPassSeconds');
    $beforeCursor = [$position->getValue($records), $started->getValue($records), $lastPass->getValue($records)];
    $metricsTable = (new ReflectionProperty(RuntimeMetrics::class, 'table'))->getValue();
    $beforeMetrics = iterator_to_array($metricsTable);
    $now = max(microtime(true), (float)$sample[1]['expires_at']) + 1.0;
    try {
        // Моделируем исчерпание логического row-бюджета существующего экземпляра,
        // не заполняем Table мусором. После отказа публикации PlayerQueue нет.
        $capacityProperty = new ReflectionProperty($records, 'capacity');
        $normalCapacity = $capacityProperty->getValue($records);
        $queueBefore = PlayerQueue::getAll();
        try {
            $capacityProperty->setValue($records, count($before[0]));
            $existing = $records->get($sample[1]['key']);
            [$old, $created] = ProtocolCommands::reserve($existing['world_id'], (int)$existing['player_id'],
                $existing['request_id'], $existing['fingerprint'], $existing['epoch'], $existing['operation']);
            $check(!$created && $old === $existing, 'Дубликат остаётся доступным при заполнении.');
            try {
                ProtocolCommands::reserve($existing['world_id'], (int)$existing['player_id'],
                    'ffffffff-ffff-4fff-8fff-' . bin2hex(random_bytes(6)), str_repeat('0', 64), $existing['epoch']);
                throw new LogicException('Принята новая квитанция при исчерпании row-бюджета.');
            } catch (OverflowException) { ++$checks; }
            $check($snapshot() === $before && PlayerQueue::getAll() === $queueBefore,
                'Отказ admission не изменил строки/ключи/блоки/очередь.');
        } finally { $capacityProperty->setValue($records, $normalCapacity); }

        $position->setValue($records, $sample[0]);
        $callbacks = 0;
        $result = $records->sweep(static function (array $row) use (&$callbacks): bool {
            ++$callbacks;
            usleep(3000); // Начатый callback завершается, следующий слот ждёт новую порцию.
            return false;
        }, 8, $now, 2.0);
        $check($result['scanned'] === 1 && $callbacks === 1, 'Временной бюджет остановил порцию после callback.');
        $check($result['timeBudgetHit'] && $result['elapsedMs'] >= 2.0 && $result['deleted'] === 0, 'Time hit наблюдаем, удаления нет.');
        $check($position->getValue($records) === $sample[0] + 1, 'Непроверенный следующий слот не пропущен.');
        $result = $records->sweep(static fn(array $row): bool => false, 2, $now, 1000.0);
        $check($result['scanned'] === 2 && !$result['timeBudgetHit'], 'Ограничение числа шагов работает независимо.');
        $check($position->getValue($records) === $sample[0] + 3, 'Следующая порция продолжила обход.');

        $position->setValue($records, $sample[0]);
        try {
            $records->sweep(static function (array $row): bool { throw new RuntimeException('expected-cleanup-callback'); }, 8, $now);
            throw new LogicException('Не передано исключение callback.');
        } catch (RuntimeException $error) {
            $check($error->getMessage() === 'expected-cleanup-callback', 'Не скрыто исключение callback.');
        }
        $check($position->getValue($records) === $sample[0] + 1, 'Проблемная запись не заклинивает курсор.');
        $result = $records->sweep(static fn(array $row): bool => false, 1, $now);
        $check($result['scanned'] === 1 && $position->getValue($records) === $sample[0] + 2, 'После ошибки возможен следующий шаг.');

        $capacity = $get($records, 'capacity');
        $position->setValue($records, $capacity - 1);
        $started->setValue($records, hrtime(true) - 1000000000);
        $result = $records->sweep(static fn(array $row): bool => false, 2, $now, 1000.0);
        $check($result['passes'] === 1 && $result['lastPassSeconds'] >= 1.0, 'Наблюдается действительное время полного круга.');
        $check($position->getValue($records) === $capacity + 1, 'Кольцевой переход сохранил следующий слот.');
        foreach ([[0, 2.0], [1, 0.0], [1, NAN]] as [$budget, $ms]) {
            $cursor = $position->getValue($records);
            try {
                $records->sweep(static fn(array $row): bool => false, $budget, $now, $ms);
                throw new LogicException('Принят неверный бюджет.');
            } catch (InvalidArgumentException) { ++$checks; }
            $check($position->getValue($records) === $cursor, 'Неверный бюджет не меняет курсор.');
        }
        $check($snapshot() === $before, 'Все строки, dirty-маркеры, ключи и блоки прежние.');
    } finally {
        $position->setValue($records, $beforeCursor[0]);
        $started->setValue($records, $beforeCursor[1]);
        $lastPass->setValue($records, $beforeCursor[2]);
        foreach ($beforeMetrics as $key => $row) {
            if (!$metricsTable->set((string)$key, $row)) throw new RuntimeException('Не восстановлены метрики диагностической проверки.');
        }
    }
    $check(iterator_to_array($metricsTable) === $beforeMetrics, 'Метрики не содержат искусственных задержек/ошибок probe.');
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks,
        'rowsAndDirtyMarkersUnchanged' => true, 'cursorRestored' => true, 'newTables' => 0], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
