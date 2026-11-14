<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{Accounts, Users, ProtocolCommands, RuntimeRecords, PlayerQueue};

/** Только основной dev после GameProtocolV2::initialize, до сети/saver.
 * Заполняет существующий blob-пул временными ключами; finally удаляет только
 * свои ключи/квитанции. Ни нового мира, ни Table, ни SQL-write здесь нет.
 */
return static function (string $path, bool $expectProtected = true): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Completion: ' . $label);
        ++$checks;
    };
    $get = static fn(object $object, string $name): mixed => (new ReflectionProperty($object, $name))->getValue($object);
    $records = (new ReflectionProperty(ProtocolCommands::class, 'records'))->getValue();
    $bodies = $get($records, 'bodies');
    $blocks = $get($bodies, 'blocks');
    $state = static function () use ($get, $records, $blocks): array {
        $out = [];
        foreach (['rows', 'keys', 'dirty'] as $name) {
            $out[$name] = iterator_to_array($get($records, $name));
            ksort($out[$name]);
        }
        $out['blocks'] = iterator_to_array($blocks);
        ksort($out['blocks']);
        return $out;
    };
    $before = $state();
    $queue = PlayerQueue::getAll();
    $account = Accounts::findByIndex('login', 'qaown_260924') ?? throw new LogicException('Нет QA-аккаунта.');
    $user = Users::findByIndex('account', (string)$account['id']) ?? throw new LogicException('Нет QA-игрока.');
    $world = 'qa-completion';
    $id = 'ffffffff-ffff-4fff-8fff-' . bin2hex(random_bytes(6));
    $key = $world . ':' . $user['id'] . ':' . $id;
    $fillers = [];
    $prefix = 'qa-completion:' . bin2hex(random_bytes(8)) . ':';
    $completionRejected = false;
    $done = null;
    $newId = 'ffffffff-ffff-4fff-8fff-' . bin2hex(random_bytes(6));
    $newKey = $world . ':' . $user['id'] . ':' . $newId;
    $variants = [];
    $remove = static function (string $key) use ($records): void {
        $records->delete($key);
        $snapshot = array_filter($records->snapshot(), static fn(array $entry): bool => $entry['key'] === $key);
        $records->acknowledge($snapshot);
    };
    $raw = static function (string $key) use ($get, $records): array {
        foreach ($get($records, 'rows') as $row) if ($row['key'] === $key) return $row;
        throw new RuntimeException('Не найдена тестовая raw-запись.');
    };
    try {
        [$receipt, $created] = ProtocolCommands::reserve($world, (int)$user['id'], $id,
            hash('sha256', $key), str_repeat('0', 32));
        $check($created, 'Принята только тестовая квитанция без PlayerQueue.');
        if ($expectProtected) {
            $reserved = $raw($key);
            $check($reserved['body_capacity'] === ProtocolCommands::MAX_RECORD_BYTES + 1,
                'До публикации зарезервирован worst-case размер с префиксом.');
            $check($blocks->count() - count($before['blocks']) === 17, 'Pending занимает17 native-блоков.');
            $snapshot = array_filter($records->snapshot(), static fn(array $entry): bool => $entry['key'] === $key);
            $entry = array_values($snapshot)[0];
            $check(strlen($entry['packed']) === $reserved['body_size'] && strlen($entry['packed']) < 1024,
                'В снимке только полезные байты, не пустой резерв.');
            $records->acknowledge($snapshot);
            $check($records->get($key) === $receipt && $raw($key)['body_capacity'] === $reserved['body_capacity'],
                'ACK pending не освобождает резерв завершения.');
            foreach (['rejected', 'unknown', 'oversized', 'invalid-json', 'inline'] as $variant) {
                $vid = 'ffffffff-ffff-4fff-8fff-' . bin2hex(random_bytes(6));
                $vkey = $world . ':' . $user['id'] . ':' . $vid;
                $variants[$variant] = ['key' => $vkey];
                [$test, $added] = ProtocolCommands::reserve($world, (int)$user['id'], $vid, hash('sha256', $vkey), str_repeat('0', 32));
                $variants[$variant]['receipt'] = $test;
                $check($added, 'Заранее принят вариант результата: ' . $variant);
            }
        }
        // Не подменяем лимит: заполняем настоящие существующие native-блоки.
        // Полный проход по новым ключам после исчерпания collision-pool
        // заполняет и оставшиеся пустые hash-buckets.
        $limit = RuntimeRecords::layout('ProtocolCommands')['blocks'];
        $misses = 0;
        for ($i = 0; $i < $limit * 12 && $misses < $limit; ++$i) {
            $filler = $prefix . $i;
            if (@$blocks->set($filler, ['bytes' => 'x'])) { $fillers[] = $filler; $misses = 0; }
            else ++$misses;
        }
        $allocationRejected = false;
        try {
            $probeSize = 17 * 1024;
            $probeId = @$bodies->put(str_repeat('x', $probeSize));
            $bodies->release($probeId, $probeSize);
        } catch (OverflowException) { $allocationRejected = true; }
        $check($allocationRejected, 'Native-пул отклоняет новую17-блочную аллокацию.');
        if ($expectProtected) {
            $full = $state();
            try {
                ProtocolCommands::reserve($world, (int)$user['id'], $newId, hash('sha256', $newKey), str_repeat('0', 32));
                throw new LogicException('Принята новая команда без резерва.');
            } catch (OverflowException) { ++$checks; }
            $check($state() === $full && PlayerQueue::getAll() === $queue, 'Отказ admission не оставил строки/блоки/очередь.');
            [$duplicate, $added] = ProtocolCommands::reserve($world, (int)$user['id'], $id,
                $receipt['fingerprint'], $receipt['epoch']);
            $check(!$added && $duplicate === $receipt && $state() === $full, 'Дубликат читается в полном пуле.');
            // Ошибка метаданных после изменения байтов: старый резерв не
            // освобождён, поэтому rollback обходится без новых блоков.
            foreach ([1025, ProtocolCommands::MAX_RECORD_BYTES + 1] as $bytes) {
                try {
                    $bodies->replace(random_bytes($bytes), $bytes, $reserved['body_id'], $reserved['body_capacity'],
                        static function (int $bodyId): void { throw new RuntimeException('expected-publish-failure'); });
                    throw new LogicException('Не передано исключение publish.');
                } catch (RuntimeException $error) {
                    $check($error->getMessage() === 'expected-publish-failure', 'Ошибка publish не скрыта.');
                }
                $check($state() === $full && $records->get($key) === $receipt, 'Байты и метаданные восстановлены при полном пуле.');
            }
        }
        $outcome = ['result' => ['probe' => bin2hex(random_bytes(2048))]];
        try {
            $done = ProtocolCommands::finish($receipt, 'applied', $outcome);
            $check($expectProtected && $done['state'] === 'applied'
                && json_decode($done['outcome'], true, 16, JSON_THROW_ON_ERROR) === $outcome,
                'Результат целиком записан в полном пуле.');
        } catch (OverflowException) {
            $completionRejected = true;
            $check(!$expectProtected, 'Завершение не должно выделять новые блоки.');
        }
        $found = ProtocolCommands::find($world, (int)$user['id'], $id);
        $check($found !== null && $found['state'] === ($expectProtected ? 'applied' : 'accepted'),
            'Состояние квитанции соответствует результату записи.');
        if ($expectProtected) {
            $completed = $raw($key);
            $check($completed['body_id'] === $reserved['body_id'] && $completed['body_capacity'] === $completed['body_size'],
                'Terminal переиспользовал старый ID и отпустил лишний резерв.');
            $check($blocks->count() === count($before['blocks']) + count($fillers)
                + count($variants) * 17 + (int)ceil($completed['body_size'] / 1024), 'После завершения нет лишних блоков.');
            $check(ProtocolCommands::finish($receipt, 'unknown', ['reason' => 'duplicate']) === $done,
                'Повтор finish не перезаписывает terminal-результат.');
            $remove($key);
            $remaining = count($variants);
            foreach ($variants as $variant => $fixture) {
                $test = $fixture['receipt'];
                if ($variant === 'inline') {
                    $value = ['state' => 'applied', 'expires_at' => microtime(true) + 60, 'probe' => 'inline'];
                    $records->put($fixture['key'], $value);
                    $r = $raw($fixture['key']);
                    $check($records->get($fixture['key']) === $value && $r['body_id'] === 0 && $r['body_capacity'] === 0,
                        'Переход в inline освобождает весь резерв.');
                } else {
                    $payload = match ($variant) {
                        'oversized' => ['result' => str_repeat('a', ProtocolCommands::MAX_RECORD_BYTES)],
                        'invalid-json' => ['result' => "\xff"],
                        'rejected' => ['error' => ['code' => 'command.unavailable', 'message' => base64_encode(random_bytes(8192))]],
                        default => ['reason' => 'execution_unconfirmed'],
                    };
                    $done = ProtocolCommands::finish($test, $variant === 'rejected' ? 'rejected' : ($variant === 'unknown' ? 'unknown' : 'applied'), $payload);
                    $fallback = in_array($variant, ['oversized', 'invalid-json'], true);
                    $check($done['state'] === ($fallback ? 'unknown' : $variant), 'Terminal-состояние: ' . $variant);
                    $check(json_decode($done['outcome'], true, 16, JSON_THROW_ON_ERROR)
                        === ($fallback ? ['reason' => 'outcome_unavailable'] : $payload), 'Полный результат либо явный безопасный fallback.');
                }
                $remove($fixture['key']);
                --$remaining;
                $check($blocks->count() === count($before['blocks']) + count($fillers) + $remaining * 17,
                    'Весь резерв варианта освобождён.');
            }
        }
        $check(PlayerQueue::getAll() === $queue, 'Игровых действий не опубликовано.');
    } finally {
        foreach ($fillers as $filler) if (!$blocks->del($filler)) throw new RuntimeException('Не удалён собственный filler.');
        $remove($key);
        $remove($newKey);
        foreach ($variants as $fixture) $remove($fixture['key']);
    }
    $check($state() === $before && PlayerQueue::getAll() === $queue, 'Все исходные строки/блоки/dirty/очередь прежние.');
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks,
        'protected' => $expectProtected, 'completionRejected' => $completionRejected,
        'originalStateUnchanged' => true, 'newTables' => 0], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
