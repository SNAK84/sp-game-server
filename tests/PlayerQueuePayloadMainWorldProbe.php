<?php
declare(strict_types=1);

use SPGame\Core\Environment;
use SPGame\Game\Repositories\{Accounts, Users, PlayerQueue, PlayerQueuePayloads as Payloads, RuntimeCapacity};
use SPGame\Game\Services\RepositorySaver;

/** Только основной dev после GameProtocolV2::initialize, до сети/saver.
 * Заполняет существующие Table собственными временными ключами; finally
 * удаляет только их. Не создаёт мир/Table, SQL-записи или игровые действия.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Payload: ' . $message);
        ++$checks;
    };
    $get = static fn(string $class, string $name): mixed => (new ReflectionProperty($class, $name))->getValue();
    $table = $get(Payloads::class, 'table');
    $counters = $get(Payloads::class, 'accounts');
    $queue = $get(PlayerQueue::class, 'table');
    $copy = static function (Swoole\Table $source): array { $rows = iterator_to_array($source); ksort($rows); return $rows; };
    $state = static function () use ($copy, $get, $table, $counters, $queue): array {
        $out = ['payload' => $copy($table), 'accounts' => $copy($counters), 'queue' => $copy($queue),
            'dirty' => $copy($get(PlayerQueue::class, 'syncTable'))];
        // Отказ может израсходовать ID: пропуски допустимы, откат allocator
        // запрещён. Сравниваем markers строк, не монотонный meta.last_id.
        unset($out['dirty']['meta']['last_id']);
        foreach ($get(PlayerQueue::class, 'indexTables') as $name => $index) $out['indexes'][$name] = $copy($index);
        $positions = (new ReflectionMethod(PlayerQueue::class, 'indexPositionTable'))->invoke(null);
        $out['positions'] = $positions === null ? null : $copy($positions);
        return $out;
    };
    $before = $state();
    $account = Accounts::findByIndex('login', 'qaown_260924') ?? throw new LogicException('Нет QA-аккаунта.');
    $user = Users::findByIndex('account', (string)$account['id']) ?? throw new LogicException('Нет QA-игрока.');
    $check(PlayerQueue::getByAccount((int)$account['id']) === null && !$counters->exists((string)$account['id']), 'QA не имеет ожидающих событий.');
    $queued = $payloadFixtures = $counterFillers = $queueFillers = [];
    $dataFor = static function (int $bytes): array {
        $data = ['Name' => 'QA Payload', 'padding' => ''];
        for ($i = 0; $i < 4; ++$i) $data['padding'] = str_repeat('x', max(0, strlen($data['padding']) + $bytes - strlen(serialize($data))));
        if (strlen(serialize($data)) !== $bytes) throw new RuntimeException('Не собран payload заданного размера.');
        return $data;
    };
    $add = static function (array $data) use ($account, $user, &$queued): int {
        $id = PlayerQueue::addQueue((int)$account['id'], (int)$user['id'], (int)$user['current_planet'], PlayerQueue::ActionRenamePlanet, $data);
        $queued[] = $id;
        return $id;
    };
    $clearQueued = static function () use (&$queued): void {
        foreach ($queued as $id) if (($row = PlayerQueue::findExistingById($id)) !== null) PlayerQueue::delete($row);
        $queued = [];
    };
    $fixture = static function (int $i): array {
        return ['id' => 2000000000 + $i, 'account_id' => 1900000000 + intdiv($i, Payloads::MAX_PER_ACCOUNT),
            'user_id' => 1900000000, 'planet_id' => 0, 'action' => 'qa_payload_probe'];
    };
    try {
        $capacity = Payloads::capacity();
        $check($capacity === Payloads::MAX_PENDING && RuntimeCapacity::profile() === 'development', 'Рабочий dev остался1024, target не выделен.');
        $environment = new ReflectionProperty(Environment::class, 'env'); $oldEnvironment = $environment->getValue();
        try {
            $environment->setValue(null, array_replace($oldEnvironment, ['SP_GAME_CAPACITY_PROFILE' => 'target']));
            $check(Payloads::capacity() === $capacity && RuntimeCapacity::budget('PlayerQueuePayloads', Payloads::MAX_PENDING) === 8192,
                'Ёмкость уже созданной Table не меняется от process-local профиля.');
        } finally { $environment->setValue(null, $oldEnvironment); }
        foreach ([512, 513, Payloads::MAX_BYTES] as $bytes) {
            $data = $dataFor($bytes); $id = $add($data);
            $row = PlayerQueue::findExistingById($id);
            $events = array_column(PlayerQueue::getByAccount((int)$account['id']), null, 'id');
            $check($row['ready'] === 1 && $events[$id]['data'] === $data, 'Публикация/чтение без усечения: ' . $bytes);
            $check($table->exists((string)$id) === ($bytes > 512), 'Порог отдельного payload: ' . $bytes);
            if ($bytes > 512) {
                $check(Payloads::read($row) === serialize($data), 'Большое тело полностью сохранено.');
                foreach (['account_id', 'user_id', 'planet_id', 'action'] as $field) {
                    $wrong = $row; $wrong[$field] = $field === 'action' ? 'wrong' : $row[$field] + 1;
                    try { Payloads::read($wrong); throw new LogicException('Принят чужой контекст.'); }
                    catch (UnexpectedValueException) { ++$checks; }
                    Payloads::release($wrong);
                    $check($table->exists((string)$id), 'Чужой контекст не освобождает тело.');
                }
            }
        }
        $full = $state();
        try { $add($dataFor(Payloads::MAX_BYTES + 1)); throw new LogicException('Oversize принят.'); }
        catch (InvalidArgumentException) { ++$checks; }
        $check($state() === $full, 'Oversize не оставил queue/payload/counter/index.');

        // Те же codec/publish, которыми startup восстанавливает большой payload;
        // SQL не используется и очередь не исполняется.
        $last = end($queued); $packed = PlayerQueue::captureRestartSnapshot(true)[$last];
        $json = RepositorySaver::unpackSnapshotEntry($packed)['json'];
        $saved = (new ReflectionMethod(PlayerQueue::class, 'decodeRestartEvent'))->invoke(null, $json, $last);
        $check($saved['data'] === $dataFor(Payloads::MAX_BYTES) && $saved['payload_ttl'] > 0 && $saved['payload_ttl'] <= Payloads::TTL_SECONDS,
            'Snapshot содержит полное тело и остаток TTL.');
        $clearQueued();
        $ttl = $saved['payload_ttl']; unset($saved['payload_ttl']); $saved['ready'] = 0; $saved['data'] = serialize($saved['data']);
        (new ReflectionMethod(PlayerQueue::class, 'publish'))->invoke(null, $saved, $ttl); $queued[] = $last;
        $check(Payloads::read(PlayerQueue::findExistingById($last)) === serialize($dataFor(Payloads::MAX_BYTES))
            && Payloads::snapshot(PlayerQueue::findExistingById($last))['expires_at'] <= microtime(true) + $ttl,
            'Startup publish восстанавливает тело и не продлевает TTL.');
        $clearQueued();

        for ($i = 0; $i < Payloads::MAX_PER_ACCOUNT; ++$i) $add($dataFor(700));
        $full = $state();
        try { $add($dataFor(700)); throw new RuntimeException('Превышение quota принято.'); }
        catch (LogicException) { ++$checks; }
        $check($state() === $full, 'Пятая команда не меняет четыре принятых или индексы.');
        PlayerQueue::delete(PlayerQueue::findExistingById(array_shift($queued)));
        $add($dataFor(700));
        $check($counters->get((string)$account['id'])['pending'] === Payloads::MAX_PER_ACCOUNT, 'Освобождённая quota доступна снова.');
        $clearQueued();

        $expired = $add($dataFor(700)); $fresh = $add($dataFor(700)); $inline = $add($dataFor(512));
        $table->set((string)$expired, ['expires_at' => 1.0]);
        PlayerQueue::deleteExpiredPayloads(1.0);
        $check(PlayerQueue::findExistingById($expired) === null && !$table->exists((string)$expired)
            && PlayerQueue::findExistingById($fresh) !== null && PlayerQueue::findExistingById($inline) !== null,
            'TTL удаляет только просроченную ссылку/тело, не соседние события.');
        $check($counters->get((string)$account['id'])['pending'] === 1, 'TTL освободил ровно одну quota.');
        $clearQueued();

        $payload = serialize($dataFor(Payloads::MAX_BYTES));
        for ($i = 1; Payloads::count() < $capacity; ++$i) {
            $event = $fixture($i);
            if ($table->exists((string)$event['id']) || $counters->exists((string)$event['account_id']) && $i % Payloads::MAX_PER_ACCOUNT === 0) {
                throw new RuntimeException('Конфликт временного ключа probe.');
            }
            Payloads::put($event, $payload); $payloadFixtures[] = $event;
        }
        $check(Payloads::count() === $capacity, 'Существующий буфер заполнен предельными телами.');
        $full = $state();
        try { $add($dataFor(700)); throw new RuntimeException('Общий лимит превышен.'); }
        catch (LogicException) { ++$checks; }
        $check($state() === $full, 'Глобальный отказ не оставил событие/индекс/счётчик.');
        $sample = $payloadFixtures[0];
        $check(Payloads::read($sample) === $payload, 'Принятый payload доступен при полном буфере.');
        Payloads::release(array_shift($payloadFixtures)); $replacement = $add($dataFor(700));
        $check($table->exists((string)$replacement) && Payloads::count() === $capacity, 'Освобождённое место повторно используется.');
        $clearQueued(); foreach ($payloadFixtures as $event) Payloads::release($event); $payloadFixtures = [];

        // Реальный отказ native counter Table после записи тела: put обязан
        // откатить тело, не публикуя событие. Заполняем только свободные ключи.
        $misses = 0;
        for ($i = 0; $i < $capacity * 16 && $misses < $capacity; ++$i) {
            $key = 'qa-counter:' . $i;
            if ($counters->exists($key)) throw new RuntimeException('Конфликт counter fixture.');
            if (@$counters->set($key, ['pending' => 1])) { $counterFillers[] = $key; $misses = 0; } else ++$misses;
        }
        for ($i = 100000; ; $i += Payloads::MAX_PER_ACCOUNT) {
            $event = $fixture($i); $key = (string)$event['account_id'];
            if (!@$counters->set($key, ['pending' => 1])) break;
            $counterFillers[] = $key;
            if ($i > 100000 + $capacity * 16) throw new RuntimeException('Не достигнут native counter предел.');
        }
        $full = $state();
        try { @Payloads::put($event, $payload); $payloadFixtures[] = $event; throw new RuntimeException('Native counter отказ не воспроизведён.'); }
        catch (LogicException) { ++$checks; }
        $check($state() === $full, 'Native counter отказ откатывает созданное тело.');
        foreach ($counterFillers as $key) $counters->del($key); $counterFillers = [];

        // Следующий этап публикации: реальная PlayerQueue уже заполнена,
        // а место во внешнем payload ещё есть. Не создаём индексы у filler.
        $queueCapacity = PlayerQueue::runtimeLayout()['mainBuckets'];
        for ($i = 0; $queue->count() < $queueCapacity; ++$i) {
            $id = 1700000000 + $i;
            if ($queue->exists((string)$id)) throw new RuntimeException('Конфликт queue fixture.');
            if (!$queue->set((string)$id, ['id' => $id, 'ready' => 0])) throw new RuntimeException('Не заполнена основная очередь.');
            $queueFillers[] = $id;
        }
        $full = $state();
        try { $add($dataFor(700)); throw new LogicException('Полная PlayerQueue приняла событие.'); }
        catch (OverflowException) { ++$checks; }
        $check($state() === $full, 'Отказ основной очереди освобождает внешний payload/quota и не меняет индексы.');
    } finally {
        $clearQueued();
        foreach ($payloadFixtures as $event) Payloads::release($event);
        foreach ($counterFillers as $key) $counters->del($key);
        foreach ($queueFillers as $id) $queue->del((string)$id);
    }
    $check($state() === $before, 'Все исходные тела/счётчики/очередь/dirty/индексы прежние.');
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks, 'capacity' => Payloads::capacity(),
        'nativeCounterRejected' => true, 'queueAdmissionRejected' => true, 'originalStateRestored' => true,
        'allocatorGapsPermitted' => true,
        'newTables' => 0, 'sqlWrites' => 0], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
