<?php
declare(strict_types=1);

use SPGame\Core\Environment;
use SPGame\Game\Repositories\{Accounts, Builds, PhalanxScans, Planets, PlanetResources, PlayerQueue};
use SPGame\Game\Services\{AccountData, PhalanxService};

/** Основной dev после GameProtocolV2::initialize, до сети/saver. Существующие
 * Table, свои временные ключи; оплата скана snak1 только в RAM с точным
 * восстановлением ресурсов/markers до старта. SQL/newTable/newWorld0.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('Phalanx capacity: ' . $message);
        ++$checks;
    };
    $property = static fn(string $class, string $name): mixed => (new ReflectionProperty($class, $name))->getValue();
    $copy = static function (Swoole\Table $table): array { $rows = iterator_to_array($table); ksort($rows); return $rows; };
    $table = $property(PhalanxScans::class, 'table');
    $resources = $property(PlanetResources::class, 'table');
    $markers = $property(PlanetResources::class, 'syncTable');
    $cacheBefore = $copy($table); $resourcesBefore = $copy($resources); $markersBefore = $copy($markers);
    $queueBefore = PlayerQueue::getAll(); $owned = [];
    $capacity = PhalanxScans::capacity();
    $account = Accounts::findByLogin('snak1') ?? throw new LogicException('Нет тестового snak1.');
    $owner = new AccountData((int)$account['id']); $userId = (int)$owner['User']['id'];
    $source = $target = null;
    foreach (Planets::getAll() ?? [] as $planet) if ((int)$planet['owner_id'] === $userId
        && (int)$planet['planet_type'] === 3 && (int)(Builds::findExistingById((int)$planet['id'])['phalanx'] ?? 0) > 0) {
        $source = $planet; break;
    }
    if ($source === null) throw new LogicException('Нет подготовленной луны snak1.');
    foreach (Planets::getAll() ?? [] as $planet) if ((int)$planet['planet_type'] === 1 && (int)$planet['owner_id'] > 0
        && $planet['galaxy'] === $source['galaxy'] && $planet['system'] === $source['system']) { $target = $planet; break; }
    if ($target === null) throw new LogicException('Нет цели сканирования в системе QA-луны.');
    $owner['WorkPlanet'] = (int)$source['id'];
    $oldCount = (float)$owner['Resources'][903]['count'];
    if ($oldCount < PhalanxService::COST) throw new LogicException('Нет топлива для RAM-проверки snak1.');
    $expectFailure = static function (callable $call) use ($check): void {
        $failed = false;
        try { $call(); } catch (LogicException|RuntimeException) { $failed = true; }
        $check($failed, 'Неподходящая операция отклонена.');
    };
    $clear = static function () use ($table, &$owned): void {
        foreach ($owned as $key) if ($table->exists((string)$key)) $table->del((string)$key);
        $owned = [];
    };
    try {
        $check($capacity === 1024, 'Development-бюджет не увеличен.');
        $env = new ReflectionProperty(Environment::class, 'env'); $originalEnv = $env->getValue();
        try {
            $env->setValue(null, array_replace($originalEnv, ['SP_GAME_CAPACITY_PROFILE' => 'target']));
            $check(PhalanxScans::capacity() === $capacity, 'После init ёмкость не меняется вместе с env.');
        } finally { $env->setValue(null, $originalEnv); }
        $now = microtime(true);
        $scan = ['eventId' => 1900800001, 'scannedAt' => $now, 'fleets' => [],
            '_observations' => [7 => ['observedAt' => $now, 'position' => ['system' => 8]]]];
        $row = PhalanxScans::pack($scan, $now);
        $check($row['expires_at'] === $now + 300.0, 'TTL300с прежний.');
        for ($i = 1; $table->count() < $capacity; ++$i) {
            $id = 1900800000 + $i;
            if ($table->exists((string)$id)) throw new LogicException('Конфликт ключа fixture.');
            $owned[] = $id;
            if (!$table->set((string)$id, $row)) throw new RuntimeException('Не заполнена настоящая Table.');
        }
        $candidate = 1900900001; $owned[] = $candidate;
        $full = $copy($table);
        $expectFailure(static fn() => PhalanxScans::put($candidate, $row));
        $check($copy($table) === $full, '1025-й снимок не вытесняет/не меняет действующие.');
        $expectFailure(static fn() => PhalanxService::scan($owner, (int)$target['id'], 1900900002, 'qa-full', $now));
        $check($copy($table) === $full && $copy($resources) === $resourcesBefore && $copy($markers) === $markersBefore
            && (float)$owner['Resources'][903]['count'] === $oldCount, 'Global-full отказ до оплаты/dirty, старые снимки прежние.');
        $first = $owned[0];
        PhalanxScans::put($first, PhalanxScans::pack(array_replace($scan, ['eventId' => 1900900003]), $now));
        $check(PhalanxScans::get($first)['eventId'] === 1900900003 && $table->count() === $capacity, 'Обновление своего снимка разрешено при полном буфере.');
        $check(!isset(PhalanxScans::get($first)['_observations']), 'Приватная траектория скрыта в DTO.');
        $check(PhalanxScans::observation($first, 7, $now)['freshUntil'] === $now + 300.0, 'Приватная траектория хранится только в снимке.');
        $check(PhalanxScans::get($first, $now + 300) === null && PhalanxScans::observation($first, 7, $now + 300) === null, 'На границе TTL доступ закрыт.');
        $expiredId = $owned[1];
        $table->set((string)$expiredId, ['expires_at' => $now - 1, 'payload' => str_repeat('x', PhalanxScans::MAX_BYTES)]);
        PhalanxScans::put($first, $row);
        $check($table->exists((string)$expiredId), 'Обновление своего снимка не запускает общий TTL-pass.');
        PhalanxScans::put($candidate, $row);
        $check(!$table->exists((string)$expiredId) && $table->count() === $capacity && PhalanxScans::row($first) !== false,
            'TTL освобождает место без распаковки истёкшего payload и вытеснения живого.');
        $stable = $copy($table);
        $expectFailure(static fn() => PhalanxScans::put($first, ['expires_at' => $now + 300, 'payload' => str_repeat('x', PhalanxScans::MAX_BYTES + 1)]));
        $expectFailure(static fn() => PhalanxScans::put($first, ['expires_at' => INF, 'payload' => 'x']));
        $expectFailure(static fn() => PhalanxScans::put(0, $row));
        $check($copy($table) === $stable, 'Oversize/невалидная строка/ID не меняют прежний снимок.');
        $maxRow = ['expires_at' => $now + 300, 'payload' => str_repeat('x', PhalanxScans::MAX_BYTES)];
        PhalanxScans::put($first, $maxRow);
        $check(PhalanxScans::row($first) === $maxRow, 'Ровно16384B сохраняются без усечения.');
        $expectFailure(static fn() => PhalanxScans::get($first));
        PhalanxScans::restore($first, $row);
        $check(PhalanxScans::row($first) === $row, 'Полный before-образ восстанавливается.');
        $json = ['padding' => ''];
        $json['padding'] = str_repeat('x', PhalanxScans::MAX_JSON_BYTES - strlen(json_encode($json, JSON_THROW_ON_ERROR)));
        $maxPacked = PhalanxScans::pack($json, $now);
        PhalanxScans::put($first, $maxPacked);
        $check(PhalanxScans::get($first) === $json, 'JSON ровно1MiB распаковывается целиком.');
        $expectFailure(static fn() => PhalanxScans::pack(['padding' => $json['padding'] . 'x'], $now));
        $expectFailure(static fn() => PhalanxScans::pack(['noise' => bin2hex(random_bytes(30000))], $now));
        $expectFailure(static fn() => PhalanxScans::pack([], NAN));
        $clear();
        $owned[] = $userId;
        $result = PhalanxService::scan($owner, (int)$target['id'], 1900900004, 'qa-paid', $now);
        $paid = $resources->get((string)$source['id']);
        $check($paid['deuterium'] === $oldCount - PhalanxService::COST
            && (float)$owner['Resources'][903]['count'] === $oldCount - PhalanxService::COST, 'Успешный снимок списывает ровно5000 дейтерия.');
        $check(PhalanxScans::get($userId, $now) === $result && !isset($result['_observations']), 'Публичный результат/replay без приватного движения.');
        $check(PhalanxService::scan($owner, (int)$target['id'], 1900900004, 'qa-paid', $now + 1) === $result
            && $resources->get((string)$source['id']) === $paid, 'Replay не списывает топливо повторно.');
    } finally {
        $clear();
        foreach ($cacheBefore as $key => $row) if (!$table->set((string)$key, $row)) throw new RuntimeException('Не восстановлен исходный cache.');
        $resources->set((string)$source['id'], $resourcesBefore[$source['id']]);
        if (isset($markersBefore[$source['id']])) $markers->set((string)$source['id'], $markersBefore[$source['id']]);
        elseif ($markers->exists((string)$source['id'])) $markers->del((string)$source['id']);
        $check($copy($table) === $cacheBefore && $copy($resources) === $resourcesBefore && $copy($markers) === $markersBefore
            && PlayerQueue::getAll() === $queueBefore, 'Исходные снимки/ресурсы/dirty/очередь восстановлены.');
    }
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks, 'capacity' => $capacity,
        'newTables' => 0, 'sqlWrites' => 0, 'originalStateRestored' => true], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
