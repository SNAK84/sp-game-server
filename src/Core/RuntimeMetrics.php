<?php
declare(strict_types=1);

namespace SPGame\Core;

use Swoole\Table;

/** Ограниченная общая гистограмма; никаких payload/ID игроков в метриках. */
final class RuntimeMetrics
{
    private static ?Table $table = null;
    private const KEYS = ['world.tick', 'world.lag', 'ws.request', 'repository.flush', 'protocol.push', 'sensor.scan',
        'storage.receiptsCleanup', 'storage.reportsCleanup'];
    private const GAUGES = ['world.lockMisses', 'world.lastPassAt', 'world.authorityPid', 'ws.authorityPid', 'storage.pendingRows',
        'storage.oldestDirtySeconds', 'storage.snapshotRows', 'storage.lastSavedAt',
        'storage.snapshotBytes', 'storage.snapshotPeakBytes', 'storage.snapshotLimitBytes',
        'storage.snapshotBudgetHits', 'storage.snapshotCaptureMs',
        'storage.lastErrorAt', 'storage.workerPid', 'storage.workerHeartbeat', 'storage.saveIntervalSeconds', 'storage.runtimeSqlRejected',
        'storage.snapshotLockMisses', 'sensor.sourceCandidates', 'sensor.targetCandidates', 'sensor.scanSystems',
        'sensor.cacheViewers', 'sensor.cacheCapacity', 'sensor.cacheRejected', 'sensor.cacheOversized', 'sensor.cacheExpired',
        'sensor.scanLastSteps', 'sensor.scanPending', 'sensor.scanLastMs', 'sensor.scanMaxSteps',
        'sensor.scanBudgetMs', 'sensor.scanBudgetHits', 'sensor.scanOversized', 'sensor.scanPeakBytes', 'sensor.scanMaxBytes',
        'protocol.pushAttempts', 'protocol.pushSent', 'protocol.pushPending', 'protocol.pushLastBatch',
        'protocol.pushBudgetHits', 'protocol.pushOldestSeconds', 'protocol.pushMaxPerPass', 'protocol.pushBudgetMs',
        'storage.receiptsCleanupScanned', 'storage.receiptsCleanupDeleted', 'storage.receiptsCleanupBudgetSteps',
        'storage.receiptsCleanupPassSeconds', 'storage.receiptsCleanupBudgetHits',
        'storage.reportsCleanupScanned', 'storage.reportsCleanupDeleted', 'storage.reportsCleanupBudgetSteps',
        'storage.reportsCleanupPassSeconds', 'storage.reportsCleanupBudgetHits', 'storage.cleanupErrors'];

    public static function init(): void
    {
        self::$table = new Table(64, 1.0);
        self::$table->column('count', Table::TYPE_INT, 8);
        self::$table->column('total_ms', Table::TYPE_FLOAT, 8);
        self::$table->column('max_ms', Table::TYPE_FLOAT, 8);
        self::$table->column('last_ms', Table::TYPE_FLOAT, 8);
        self::$table->column('value', Table::TYPE_FLOAT, 8);
        for ($i = 0; $i < 16; ++$i) self::$table->column('b' . $i, Table::TYPE_INT, 8);
        if (!self::$table->create()) throw new \RuntimeException('Не создана таблица runtime-метрик.');
        foreach (array_merge(self::KEYS, self::GAUGES) as $key) if (!self::$table->set($key, ['count' => 0])) throw new \RuntimeException('Не создан счётчик runtime-метрик.');
    }

    public static function gauge(string $key, float $value): void
    {
        if (self::$table !== null && in_array($key, self::GAUGES, true)) self::$table->set($key, ['value' => $value]);
    }

    public static function increment(string $key): void
    {
        if (self::$table !== null && in_array($key, self::GAUGES, true)) self::$table->incr($key, 'value', 1.0);
    }

    public static function gauges(): array
    {
        $result = [];
        if (self::$table !== null) foreach (self::GAUGES as $key) $result[$key] = self::$table->get($key)['value'];
        return $result;
    }

    public static function observe(string $key, float $milliseconds): void
    {
        if (self::$table === null || !in_array($key, self::KEYS, true)) return;
        $value = max(0.0, $milliseconds);
        $bucket = min(15, (int)ceil(log(max(1.0, $value), 2)));
        self::$table->incr($key, 'count');
        self::$table->incr($key, 'total_ms', $value);
        self::$table->incr($key, 'b' . $bucket);
        $row = self::$table->get($key);
        self::$table->set($key, ['last_ms' => $value, 'max_ms' => max($row['max_ms'], $value)]);
    }

    public static function snapshot(): array
    {
        if (self::$table === null) return [];
        $result = [];
        foreach (self::KEYS as $key) {
            $row = self::$table->get($key);
            $values = ['count' => $row['count'], 'lastMs' => round($row['last_ms'], 3), 'maxMs' => round($row['max_ms'], 3)];
            foreach ([95, 99] as $percentile) {
                $sum = 0;
                for ($i = 0; $i < 16; ++$i) {
                    $sum += $row['b' . $i];
                    if ($sum >= max(1, (int)ceil($row['count'] * $percentile / 100))) break;
                }
                $values['p' . $percentile . 'UpperMs'] = $row['count'] === 0 ? null : ($i >= 15 ? null : 2 ** $i);
            }
            $result[$key] = $values;
        }
        return $result;
    }
}
