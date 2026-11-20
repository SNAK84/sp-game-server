<?php
declare(strict_types=1);

use SPGame\Core\Database;
use SPGame\Game\Repositories\ProtocolCommands;
use SPGame\Game\Services\RepositorySaver;

/** После initialize основного dev. Временные SQL-строки видны только внутри ROLLBACK. */
return static function (string $path, bool $batched = true): void {
    if (file_exists($path)) return;
    $db = Database::independent();
    $world = 'qa-receipt-' . bin2hex(random_bytes(8));
    $entry = static function (string $world, int $player, int $i, bool $delete) {
        $id = sprintf('ffffffff-ffff-4fff-8fff-%012x', $i);
        $row = ['world_id' => $world, 'player_id' => $player, 'request_id' => $id,
            'fingerprint' => str_repeat('f', 64), 'epoch' => str_repeat('1', 32), 'claim' => str_repeat('2', 32),
            'operation' => 'planet.rename', 'state' => 'applied', 'outcome' => '{}', 'created_at' => 1.0, 'expires_at' => 86401.0];
        return RepositorySaver::packSnapshotEntry(['key' => "$world:$player:$id", 'version' => $i, 'deleted' => $delete,
            'packed' => "\x00" . json_encode($row, JSON_THROW_ON_ERROR)]);
    };
    $count = static fn(): int => (int)$db->fetchOne('SELECT COUNT(*) n FROM protocol_commands WHERE world_id IN (?,?)', [$world, $world . '-b'])['n'];
    $stat = static fn(): int => (int)$db->fetchOne("SHOW SESSION STATUS LIKE 'Com_delete'")['Value'];
    if ($count() !== 0) throw new LogicException('Занят ключ проверки квитанций.');
    $toInsert = $toDelete = [];
    for ($i = 0; $i < 257; ++$i) {
        $w = $i % 2 ? $world : $world . '-b';
        $player = 1 + $i % 3;
        $toInsert[] = $entry($w, $player, $i, false);
        $toDelete[] = $entry($w, $player, $i, true);
    }
    // Тот же requestId, но другой мир или игрок, должен остаться.
    $toInsert[] = $entry($world, 1, 0, false);
    $toInsert[] = $entry($world . '-b', 2, 0, false);
    $db->beginTransaction();
    try {
        ProtocolCommands::writeSaveSnapshot($toInsert, $db);
        if ($count() !== 259) throw new RuntimeException('Подготовлены не все составные ключи.');
        $before = $stat();
        $started = hrtime(true);
        ProtocolCommands::writeSaveSnapshot($toDelete, $db);
        $ms = (hrtime(true) - $started) / 1000000;
        $statements = $stat() - $before;
        if ($statements !== ($batched ? 3 : 257)) throw new RuntimeException('Неверное количество SQL DELETE.');
        $remaining = $db->fetchAll('SELECT world_id,player_id,request_id FROM protocol_commands WHERE world_id IN (?,?) ORDER BY world_id', [$world, $world . '-b']);
        if (count($remaining) !== 2 || $remaining[0]['world_id'] !== $world || (int)$remaining[0]['player_id'] !== 1
            || $remaining[1]['world_id'] !== $world . '-b' || (int)$remaining[1]['player_id'] !== 2
            || $remaining[0]['request_id'] !== 'ffffffff-ffff-4fff-8fff-000000000000'
            || $remaining[1]['request_id'] !== $remaining[0]['request_id']) throw new RuntimeException('Нарушена точность DELETE.');
        // Чередование DELETE, повторного INSERT и хвоста DELETE.
        ProtocolCommands::writeSaveSnapshot([$toInsert[0], $toDelete[0], $toInsert[0], $toDelete[1]], $db);
        if ($count() !== 3) throw new RuntimeException('Нарушен порядок смешанного снимка.');
    } finally {
        $db->rollBack();
    }
    if ($count() !== 0) throw new RuntimeException('Остались служебные строки.');
    if ($batched) {
        $records = (new ReflectionProperty(ProtocolCommands::class, 'records'))->getValue();
        $cursor = new ReflectionProperty($records, 'sweep');
        $start = $cursor->getValue($records);
        $row = $records->snapshotValue($toInsert[0]);
        $key = $row['world_id'] . ':' . $row['player_id'] . ':' . $row['request_id'];
        $hash = substr(hash('sha256', $key), 0, 32);
        $index = (new ReflectionProperty($records, 'keys'))->getValue($records);
        if ($index->exists($hash)) throw new LogicException('Тестовая квитанция уже есть в RAM.');
        $records->put($key, $row);
        $slot = $index->get($hash, 'slot');
        try {
            $cursor->setValue($records, $slot);
            ProtocolCommands::cleanup(86400.5);
            if ($records->get($key) !== $row) throw new RuntimeException('Удаление раньше TTL.');
            $cursor->setValue($records, $slot);
            ProtocolCommands::cleanup(86401.0);
            if ($records->get($key) !== null || !$index->exists($hash)) throw new RuntimeException('Неверная граница TTL или раннее освобождение.');
            $oldDelete = [$slot => $records->snapshot(true)[$slot]];
            [$new, $created] = ProtocolCommands::reserve($row['world_id'], $row['player_id'], $row['request_id'], $row['fingerprint'], $row['epoch']);
            $records->acknowledge($oldDelete);
            if (!$created || $records->get($key) !== $new) throw new RuntimeException('Старый ACK затронул новую команду.');
        } finally {
            $records->delete($key);
            $records->acknowledge([$slot => $records->snapshot(true)[$slot]]);
            $cursor->setValue($records, $start);
        }
        // При загрузке из SQL регистр request_id сохраняется, а RAM-ключ нормализован.
        // Длинное экранированное имя мира также проверяет обычный путь с полным телом.
        foreach ([$world . ':A', $world . str_repeat("\x01", 36)] as $testWorld) {
            $requestId = strtoupper($row['request_id']);
            $legacy = array_replace($row, ['world_id' => $testWorld, 'request_id' => $requestId,
                'outcome' => json_encode(['sample' => bin2hex(random_bytes(6000))], JSON_THROW_ON_ERROR)]);
            $identity = ['world_id' => $testWorld, 'player_id' => $row['player_id'], 'request_id' => $requestId];
            $key = $testWorld . ':' . $row['player_id'] . ':' . strtolower($requestId);
            $hash = substr(hash('sha256', $key), 0, 32);
            if ($index->exists($hash)) throw new LogicException('Ключ QA уже занят.');
            $selectIds = static fn(): array => array_column($db->fetchAll(
                'SELECT request_id FROM protocol_commands WHERE world_id=? AND player_id=? ORDER BY request_id',
                [$testWorld, $row['player_id']]), 'request_id');
            $records->put($key, $legacy, false);
            $slot = $index->get($hash, 'slot');
            try {
                $cursor->setValue($records, $slot);
                ProtocolCommands::cleanup(86401.0);
                $delete = [$slot => $records->snapshot(true)[$slot]];
                $projected = $records->snapshotValue($delete[$slot]);
                $expected = $testWorld === $world . ':A' ? $identity : $legacy;
                if ($projected !== $expected) throw new RuntimeException('Повреждён ключ удаления или потерян резервный формат.');
                $db->beginTransaction();
                try {
                    if ($selectIds() !== []) throw new LogicException('SQL-ключи QA уже заняты.');
                    foreach ([$requestId, strtolower($requestId)] as $id) {
                        $seed = array_replace($legacy, ['request_id' => $id]);
                        ProtocolCommands::writeSaveSnapshot([['deleted' => false, 'packed' => "\x00" . json_encode($seed, JSON_THROW_ON_ERROR)]], $db);
                    }
                    ProtocolCommands::writeSaveSnapshot($delete, $db);
                    if ($selectIds() !== [strtolower($requestId)]) throw new RuntimeException('SQL удалил чужой регистр request_id.');
                } finally {
                    $db->rollBack();
                }
                if ($records->snapshot(true)[$slot] !== $delete[$slot]) throw new RuntimeException('Откат снял dirty.');
                [$new, $created] = ProtocolCommands::reserve($testWorld, $row['player_id'], $requestId, $row['fingerprint'], $row['epoch']);
                $records->acknowledge($delete);
                if (!$created || $records->get($key) !== $new || !isset($records->snapshot()[$slot])) {
                    throw new RuntimeException('Поздний ACK удаления затронул новую команду.');
                }
            } finally {
                $records->delete($key);
                $records->acknowledge([$slot => $records->snapshot(true)[$slot]]);
                $cursor->setValue($records, $start);
            }
        }
    }
    file_put_contents($path, json_encode(['deletedRows' => 257, 'statements' => $statements, 'ms' => $ms, 'rollback' => true], JSON_THROW_ON_ERROR), LOCK_EX);
};
