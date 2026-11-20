<?php
declare(strict_types=1);

use SPGame\Core\{Database, RuntimeMetrics};
use SPGame\Game\Repositories\{Accounts, MiningCheckpoints, ProtocolCommands, Users};
use SPGame\Game\Services\RepositorySaver;

/** В основном dev до сети и фонового saver. Общий снимок записывает мир
 * и тестовые квитанции. Следующий снимок удаляет созданную тестом группу.
 */
return static function (RepositorySaver $saver, string $path): void {
    if (file_exists($path)) return;
    $account = Accounts::findByIndex('login', 'qaown_260924') ?? throw new LogicException('Нет QA-аккаунта.');
    $user = Users::findByIndex('account', (string)$account['id']) ?? throw new LogicException('Нет QA-игрока.');
    $player = (int)$user['id'];
    $records = (new ReflectionProperty(ProtocolCommands::class, 'records'))->getValue();
    $world = 'qa-capture-' . bin2hex(random_bytes(8));
    $db = Database::independent();
    $sqlCount = static fn(): int => (int)$db->fetchOne('SELECT COUNT(*) n FROM protocol_commands WHERE world_id=?', [$world])['n'];
    if ($sqlCount() !== 0) throw new LogicException('Тестовый world_id занят.');
    $dirtyBefore = ProtocolCommands::captureSaveSnapshot();
    $checks = 0;
    $check = static function (bool $value, string $label) use (&$checks): void {
        if (!$value) throw new RuntimeException($label);
        ++$checks;
    };
    $expected = []; $keys = []; $measurements = [];
    $random = new Random\Randomizer(new Random\Engine\Xoshiro256StarStar(str_repeat('Q', 32)));
    try {
        foreach ([32, 256] as $count) {
            for ($i = count($expected); $i < $count; ++$i) {
                $id = sprintf('ffffffff-ffff-4fff-8fff-%012x', $i);
                [$receipt, $created] = ProtocolCommands::reserve($world, $player, $id, hash('sha256', $id), str_repeat('1', 32));
                if (!$created) throw new LogicException('Тестовая квитанция уже есть в RAM.');
                $keys[] = "$world:$player:$id";
                $finished = ProtocolCommands::finish($receipt, 'applied', ['sample' => bin2hex($random->getBytes(7400))]);
                $check($finished['state'] === 'applied' && strlen($finished['outcome']) === 14813, 'Ожидался полный крупный результат.');
                $expected[$id] = hash('sha256', $finished['outcome']);
            }
            $times = [];
            $legacyBytes = 0;
            for ($sample = 0; $sample < 6; ++$sample) {
                $base = memory_get_usage(); memory_reset_peak_usage();
                $snapshot = MiningCheckpoints::worldLocked(static function () use (&$elapsed): array {
                    $start = hrtime(true);
                    $snapshot = ProtocolCommands::captureSaveSnapshot(true);
                    $elapsed = (hrtime(true) - $start) / 1000000;
                    return $snapshot;
                }, true);
                $retained = memory_get_usage() - $base;
                $peak = memory_get_peak_usage() - $base;
                if ($sample !== 0) $times[] = $elapsed;
                $check(count($snapshot) === $count + count($dirtyBefore), 'Снимок должен включать все изменённые записи.');
                $encoded = array_sum(array_map('strlen', $snapshot));
                if ($sample === 5) {
                    foreach (ProtocolCommands::captureSaveSnapshot() as $slot => $plain) {
                        $check(RepositorySaver::unpackSnapshotEntry($snapshot[$slot]) === $plain,
                            'Компактная запись изменила данные или поколение квитанции.');
                        $legacyBytes += strlen(RepositorySaver::packSnapshotEntry($plain));
                    }
                }
                unset($snapshot);
            }
            sort($times);
            $measurements[$count] = ['medianMs' => $times[2], 'encodedBytes' => $encoded, 'defaultCodecBytes' => $legacyBytes,
                'retainedBytes' => $retained, 'peakBytes' => $peak];
        }
        $limit = new ReflectionProperty($saver, 'snapshotMaxBytes');
        $originalLimit = $limit->getValue($saver);
        $beforeFailure = ProtocolCommands::captureSaveSnapshot(true);
        $failed = false;
        try {
            $limit->setValue($saver, 1048576);
            $saver->saveInBackground();
        } catch (OverflowException) {
            $failed = true;
        } finally {
            $limit->setValue($saver, $originalLimit);
        }
        $check($failed && $sqlCount() === 0 && ProtocolCommands::captureSaveSnapshot(true) === $beforeFailure,
            'Превышение бюджета должно оставить всю группу в RAM до следующей попытки.');
        unset($beforeFailure);
        $check($saver->saveInBackground(), 'Общий снимок не сохранился.');
        $metrics = RuntimeMetrics::gauges();
        $worldSnapshot = ['rows' => $metrics['storage.snapshotRows'], 'captureMs' => $metrics['storage.snapshotCaptureMs'],
            'retainedBytes' => $metrics['storage.snapshotBytes'], 'peakBytes' => $metrics['storage.snapshotPeakBytes']];
        $actual = $db->fetchAll('SELECT request_id,state,SHA2(outcome,256) digest FROM protocol_commands WHERE world_id=? AND player_id=?', [$world,$player]);
        $check(count($actual) === count($expected), 'В SQL сохранена неполная группа.');
        foreach ($actual as $row) {
            $check($row['state'] === 'applied' && $row['digest'] === $expected[$row['request_id']], 'Содержимое SQL не совпало с квитанцией.');
        }
        $check(ProtocolCommands::captureSaveSnapshot() === [], 'ACK не снял подтверждённые версии.');
    } finally {
        foreach ($keys as $key) $records->delete($key);
        if ($keys !== [] && !$saver->saveInBackground()) throw new RuntimeException('Не подтверждено удаление тестовых квитанций.');
    }
    $check($sqlCount() === 0, 'После удаления в SQL остались тестовые квитанции.');
    $index = (new ReflectionProperty($records, 'keys'))->getValue($records);
    foreach ($keys as $key) $check(!$index->exists(substr(hash('sha256', $key), 0, 32)), 'Ключ не освобождён после COMMIT.');
    file_put_contents($path, json_encode(['checks' => $checks, 'measurements' => $measurements, 'worldSnapshot' => $worldSnapshot,
        'otherDirtyReceipts' => count($dirtyBefore), 'remainingSql' => 0], JSON_THROW_ON_ERROR), LOCK_EX);
};
