<?php
declare(strict_types=1);

use SPGame\Core\{Database, RuntimeMetrics};
use SPGame\Game\Repositories\{Accounts, Users, Planets, PlayerQueue, ProtocolCommands, BattleReports};
use SPGame\Game\Services\RepositorySaver;

/** Основной dev после GameProtocolV2::initialize, до сети/saver.
 * Не создаёт мир/Table. Временные QA-изменения RAM восстанавливаются,
 * SQL writer проверяется только в откатываемой транзакции.
 */
return static function (RepositorySaver $saver, string $path): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Snapshot: ' . $message);
        ++$checks;
    };
    $get = static fn(object|string $object, string $name): mixed => (new ReflectionProperty($object, $name))->getValue(is_object($object) ? $object : null);
    $start = new ReflectionProperty(RepositorySaver::class, 'snapshotStartMemory');
    $limit = new ReflectionProperty(RepositorySaver::class, 'snapshotLimit');
    $maxBytes = new ReflectionProperty(RepositorySaver::class, 'snapshotMaxBytes');
    $oldMax = $maxBytes->getValue($saver);
    $oldLimit = $limit->getValue();
    $metrics = (new ReflectionProperty(RuntimeMetrics::class, 'table'))->getValue();
    $beforeMetrics = iterator_to_array($metrics);
    $account = Accounts::findByIndex('login', 'qaown_260924') ?? throw new LogicException('Нет QA-аккаунта.');
    $user = Users::findByIndex('account', (string)$account['id']) ?? throw new LogicException('Нет QA-игрока.');
    $planet = Planets::findExistingById((int)$user['current_planet']) ?? throw new LogicException('Нет QA-планеты.');
    $check((int)$planet['owner_id'] === (int)$user['id'], 'Только своя QA-планета.');
    $planetMarkers = $get(Planets::class, 'syncTable');
    $beforeMarker = $planetMarkers->get((string)$planet['id']);
    $eventsBefore = PlayerQueue::getAll();
    $queueId = null;
    $db = Database::independent();
    $transaction = false;
    $world = 'qa-snapshot';
    $requestId = 'ffffffff-ffff-4fff-8fff-' . bin2hex(random_bytes(6));
    $reportId = bin2hex(random_bytes(16));
    $records = (new ReflectionProperty(ProtocolCommands::class, 'records'))->getValue();
    $receiptKey = $world . ':' . $user['id'] . ':' . $requestId;
    $removeReceipt = static function () use ($records, $receiptKey): void {
        $records->delete($receiptKey);
        $records->acknowledge(array_filter($records->snapshot(), static fn(array $row): bool => $row['key'] === $receiptKey));
    };
    $measurements = [];
    try {
        $values = [[], ['row' => null, 'marker' => ['generation' => 9]],
            ['row' => $planet, 'marker' => ['generation' => 10]],
            ['bytes' => "\x00\xff\x01", 'float' => 1.2345678901234567, 'name' => 'Луна', 'zero' => -0.0],
            ['text' => bin2hex(random_bytes(2048))]];
        foreach ($values as $value) {
            $packed = RepositorySaver::packSnapshotEntry($value);
            $check(RepositorySaver::unpackSnapshotEntry($packed) === $value, 'Codec не меняет типы/байты/точность.');
        }
        foreach (['', 'bad', "\x01broken", "\x00b:0;", "\x00a:0:{}trailing"] as $bad) {
            try { RepositorySaver::unpackSnapshotEntry($bad); throw new LogicException('Принята повреждённая строка.'); }
            catch (UnexpectedValueException) { ++$checks; }
        }
        foreach ($get($saver, 'repositories') as $repository) {
            $plain = $repository::captureSaveSnapshot();
            $compact = $repository::captureSaveSnapshot(true);
            if ($repository === BattleReports::class) {
                foreach (['reports', 'saves'] as $part) $compact[$part] = array_map([RepositorySaver::class, 'unpackSnapshotEntry'], $compact[$part]);
            } else $compact = array_map([RepositorySaver::class, 'unpackSnapshotEntry'], $compact);
            $check($plain === $compact, 'Plain/packed совпадают: ' . $repository);
        }
        Planets::update(['id' => $planet['id'], 'name' => 'QA Snapshot A']);
        $first = Planets::captureSaveSnapshot(true);
        $check(is_string($first[$planet['id']]), 'Настоящий BaseRepository хранит compact-строку.');
        Planets::update(['id' => $planet['id'], 'name' => 'QA Snapshot B']);
        $secondMarker = $planetMarkers->get((string)$planet['id']);
        Planets::acknowledgeSaveSnapshot([$planet['id'] => $first[$planet['id']]]);
        $check($planetMarkers->get((string)$planet['id']) === $secondMarker
            && Planets::findExistingById((int)$planet['id'])['name'] === 'QA Snapshot B', 'Старый ACK не снимает новое поколение.');

        [$receipt] = ProtocolCommands::reserve($world, (int)$user['id'], $requestId, hash('sha256', $requestId), str_repeat('0', 32));
        $accepted = array_filter($records->snapshot(true), static fn(string $entry): bool => RepositorySaver::unpackSnapshotEntry($entry)['key'] === $receiptKey);
        ProtocolCommands::finish($receipt, 'applied', ['result' => (object)[]]);
        $finished = $records->snapshot(true);
        $records->acknowledge($accepted);
        $check($finished === $records->snapshot(true), 'Shared ACK сохраняет новое поколение.');
        $removeReceipt();

        $queueId = PlayerQueue::addQueue((int)$account['id'], (int)$user['id'], (int)$planet['id'],
            PlayerQueue::ActionRenamePlanet, ['Name' => 'QA Snapshot Queue', 'probe' => str_repeat('x', 700)], 1);
        $plainEvents = PlayerQueue::captureRestartSnapshot();
        $packedEvents = PlayerQueue::captureRestartSnapshot(true);
        $plainEvent = json_decode($plainEvents[$queueId], true, 64, JSON_THROW_ON_ERROR);
        $packedEvent = json_decode(RepositorySaver::unpackSnapshotEntry($packedEvents[$queueId])['json'], true, 64, JSON_THROW_ON_ERROR);
        $check($plainEvent['payload_ttl'] >= $packedEvent['payload_ttl'] && $plainEvent['payload_ttl'] - $packedEvent['payload_ttl'] < 2,
            'У большого события сохранён оставшийся TTL.');
        unset($plainEvent['payload_ttl'], $packedEvent['payload_ttl']);
        $check($plainEvent === $packedEvent, 'Событие PlayerQueue не меняет данные.');

        // Отрицательный настоящий save: искусственный лимит только этого
        // экземпляра probe, не .env. До SQL/ACK строки обязаны остаться dirty.
        $beforeFailure = Planets::captureSaveSnapshot();
        $sqlName = $db->fetchOne('SELECT name FROM planets WHERE id=?', [$planet['id']])['name'];
        $maxBytes->setValue($saver, 1);
        try { $saver->saveInBackground(); throw new LogicException('Не сработал бюджет снимка.'); }
        catch (OverflowException) { ++$checks; }
        $maxBytes->setValue($saver, $oldMax);
        $check($beforeFailure === Planets::captureSaveSnapshot(), 'Отказ снимка не снимает dirty.');
        $check($db->fetchOne('SELECT name FROM planets WHERE id=?', [$planet['id']])['name'] === $sqlName,
            'Отказ снимка не сохранил часть экономического состояния.');
        $check($start->getValue() === null, 'Контекст бюджета очищен после ошибки.');

        $db->beginTransaction(); $transaction = true;
        Planets::writeSaveSnapshot([$planet['id'] => $first[$planet['id']]], $db, 1);
        $check($db->fetchOne('SELECT name FROM planets WHERE id=?', [$planet['id']])['name'] === 'QA Snapshot A', 'SQL writer распаковывает Base-строку.');
        PlayerQueue::writeRestartSnapshot($packedEvents, $db);
        $savedJson = $db->fetchOne('SELECT event_data FROM player_queue_restart WHERE id=?', [$queueId])['event_data'];
        $check($savedJson === RepositorySaver::unpackSnapshotEntry($packedEvents[$queueId])['json'], 'SQL очереди остаётся обычным JSON.');
        $report = ['id' => $reportId, 'version' => 1, 'created_at' => microtime(true), 'expires_at' => time() + 3600,
            'ready' => 1, 'saved_count' => 1, 'summary' => '{}', 'payload' => '{}'];
        $entry = static fn(string $key, array $row): string => RepositorySaver::packSnapshotEntry(
            ['key' => $key, 'version' => 1, 'deleted' => false, 'packed' => "\x00" . json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
        BattleReports::writeSaveSnapshot(['reports' => [$entry($reportId, $report)],
            'saves' => [$entry($reportId . ':' . $user['id'], ['report_id' => $reportId, 'user_id' => (int)$user['id'], 'saved_at' => microtime(true)])]], $db);
        $check((int)$db->fetchOne('SELECT COUNT(*) n FROM battle_report_saves WHERE report_id=?', [$reportId])['n'] === 1,
            'SQL доклада и зависимой закладки принимает compact-строки.');
        $db->rollBack(); $transaction = false;
        $check($db->fetchOne('SELECT name FROM planets WHERE id=?', [$planet['id']])['name'] === $sqlName
            && (int)$db->fetchOne('SELECT COUNT(*) n FROM battle_reports WHERE id=?', [$reportId])['n'] === 0, 'Тестовая SQL-транзакция полностью откатилась.');

        // Синтетическая копия одной реальной схемы, НЕ игровые строки/Table.
        // Сравниваем ровно одинаковые данные при8MiB; не выдаём это за20K SLO.
        foreach ([false, true] as $compact) {
            $buffer = [];
            $baseline = memory_get_usage();
            $start->setValue(null, $baseline); $limit->setValue(null, 8 * 1048576); memory_reset_peak_usage();
            $hit = false;
            try {
                for ($i = 1; $i <= 8192; ++$i) {
                    $sample = ['marker' => ['generation' => $i, 'dirty_since' => 123.0], 'row' => array_replace($planet, ['id' => $i])];
                    $buffer[$i] = $compact ? RepositorySaver::packSnapshotEntry($sample) : $sample;
                    RepositorySaver::assertSnapshotBudget();
                }
            } catch (OverflowException) { $hit = true; }
            finally { $start->setValue(null, null); }
            $measurements[$compact ? 'packed' : 'wide'] = ['rows' => count($buffer), 'peakBytes' => memory_get_peak_usage() - $baseline, 'budgetHit' => $hit];
            unset($buffer, $sample);
        }
        $check($measurements['wide']['budgetHit'] && !$measurements['packed']['budgetHit']
            && $measurements['packed']['rows'] === 8192, 'Та же схема превышает8MiB в wide, но помещается compact.');
    } finally {
        $start->setValue(null, null); $limit->setValue(null, $oldLimit); $maxBytes->setValue($saver, $oldMax);
        if ($transaction) $db->rollBack();
        if ($queueId !== null && ($row = PlayerQueue::findExistingById($queueId)) !== null) PlayerQueue::delete($row);
        $removeReceipt();
        Planets::update($planet);
        if ($beforeMarker === false) $planetMarkers->del((string)$planet['id']);
        elseif (!$planetMarkers->set((string)$planet['id'], $beforeMarker)) throw new RuntimeException('Не восстановлен QA-marker.');
        foreach ($beforeMetrics as $key => $row) if (!$metrics->set((string)$key, $row)) throw new RuntimeException('Не восстановлены probe-метрики.');
    }
    $check(Planets::findExistingById((int)$planet['id']) === $planet
        && $planetMarkers->get((string)$planet['id']) === $beforeMarker && PlayerQueue::getAll() === $eventsBefore,
        'QA-планета/marker/очередь восстановлены.');
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks, 'syntheticMemory' => $measurements,
        'qaRestored' => true, 'newTables' => 0, 'sqlCommitted' => false], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
