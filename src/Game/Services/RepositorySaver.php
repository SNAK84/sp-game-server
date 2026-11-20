<?php

namespace SPGame\Game\Services;

use SPGame\Core\{Logger, Database, Environment, RuntimeMetrics};
use SPGame\Game\Repositories\BaseRepository;
use SPGame\Game\Repositories\{MiningCheckpoints, RepositoryGuard, Messages, ProtocolCommands, BattleReports, PlayerQueue, RepositoryIdRanges};

use Swoole\Timer;

class RepositorySaver
{
    public const SNAPSHOT_ENTRY_MAX_BYTES = 64 * 1048576;
    /** @var list<class-string> */
    private array $repositories = [];

    private Logger $logger;
    private int $saveIntervalSeconds;
    private int $snapshotMaxBytes;
    private static ?int $snapshotStartMemory = null;
    private static int $snapshotLimit = 0;

    public static function assertSnapshotBudget(): void
    {
        if (self::$snapshotStartMemory !== null && memory_get_peak_usage() - self::$snapshotStartMemory > self::$snapshotLimit) {
            RuntimeMetrics::increment('storage.snapshotBudgetHits');
            throw new \OverflowException('Превышен бюджет памяти снимка; ACK не выдаётся.');
        }
    }

    /** Внутренний RAM-формат, не SQL/wire. Удерживаем строку, не дерево PHP-массивов. */
    public static function packSnapshotEntry(array $entry, bool $compress = true): string
    {
        $raw = serialize($entry);
        if (strlen($raw) > self::SNAPSHOT_ENTRY_MAX_BYTES) throw new \OverflowException('Слишком большая строка снимка.');
        self::assertSnapshotBudget();
        $packed = $compress ? gzdeflate($raw, 1) : false;
        self::assertSnapshotBudget();
        return is_string($packed) && strlen($packed) < strlen($raw) ? "\x01" . $packed : "\x00" . $raw;
    }

    /** Старые array-вызовы диагностик сохранены; внешние данные сюда не поступают. */
    public static function unpackSnapshotEntry(array|string $entry): array
    {
        if (is_array($entry)) return $entry;
        if ($entry === '' || !in_array($entry[0], ["\x00", "\x01"], true)
            || strlen($entry) > self::SNAPSHOT_ENTRY_MAX_BYTES + 1) throw new \UnexpectedValueException('Неверная строка снимка.');
        $raw = $entry[0] === "\x01" ? @gzinflate(substr($entry, 1), self::SNAPSHOT_ENTRY_MAX_BYTES) : substr($entry, 1);
        if (!is_string($raw)) throw new \UnexpectedValueException('Не распакована строка снимка.');
        self::assertSnapshotBudget();
        $result = @unserialize($raw, ['allowed_classes' => false, 'max_depth' => 64]);
        if (!is_array($result) || serialize($result) !== $raw) throw new \UnexpectedValueException('Неверное содержимое строки снимка.');
        self::assertSnapshotBudget();
        return $result;
    }
    private float $nextBackgroundSave = 0.0;
    private float $nextCleanup = 0.0;
    private float $nextIdReplenish = 0.0;

    /** Отдельный I/O-процесс использует те же Table, не поднимает второй игровой мир. */
    public function attach(\Swoole\Server $server): void
    {
        $process = new \Swoole\Process(function (): void {
            Database::forbidRuntimeSql(false);
            RuntimeMetrics::gauge('storage.workerPid', (float)getmypid());
            RuntimeMetrics::gauge('storage.saveIntervalSeconds', $this->saveIntervalSeconds);
            $this->nextBackgroundSave = microtime(true) + $this->saveIntervalSeconds;
            \Swoole\Process::signal(SIGTERM, static function (): void {
                Timer::clearAll();
                \Swoole\Event::exit();
            });
            // Повтор snapshot не должен каждую секунду попадать в ту же
            // занятую фазу секундного world tick (lock starvation).
            Timer::tick(200, function (): void {
                $now = microtime(true);
                RuntimeMetrics::gauge('storage.workerHeartbeat', $now);
                if ($now >= $this->nextIdReplenish) {
                    $this->nextIdReplenish = $now + 1;
                    try { RepositoryIdRanges::replenish(); }
                    catch (\Throwable $error) {
                        RuntimeMetrics::gauge('storage.lastErrorAt', microtime(true));
                        $this->logger->warning('Пополнение резерва ID отложено', ['exception' => $error::class]);
                    }
                }
                if ($now >= $this->nextBackgroundSave) {
                    try {
                        $saved = $this->saveInBackground();
                        // Интервал от начала попытки, а не ещё30с после долгого SQL.
                        $this->nextBackgroundSave = $saved
                            ? max(microtime(true) + 1, $now + $this->saveIntervalSeconds)
                            : microtime(true) + .05;
                    } catch (\Throwable $error) {
                        RuntimeMetrics::gauge('storage.lastErrorAt', microtime(true));
                        $this->nextBackgroundSave = microtime(true) + 5;
                        $this->logger->error('Фоновое сохранение отложено', ['exception' => $error::class]);
                    }
                }
                if ($now >= $this->nextCleanup) {
                    $this->nextCleanup = $now + 5;
                    $pending = 0; $age = 0.0;
                    foreach ($this->repositories as $repository) {
                        $stats = $repository::syncStats($now);
                        $pending += $stats['pending']; $age = max($age, $stats['oldestDirtySeconds']);
                    }
                    RuntimeMetrics::gauge('storage.pendingRows', $pending);
                    RuntimeMetrics::gauge('storage.oldestDirtySeconds', $age);
                    try {
                        Messages::purgeExpiredSql($now);
                        ProtocolCommands::purgeExpiredSql($now);
                        BattleReports::purgeExpiredSql($now);
                    } catch (\Throwable $error) {
                        $this->logger->warning('Фоновая SQL-очистка отложена', ['exception' => $error::class]);
                    }
                }
            });
            \Swoole\Event::wait();
        }, false, SOCK_DGRAM, false);
        if ($server->addProcess($process) === false) throw new \RuntimeException('Не зарегистрирован процесс сохранения.');
    }

    /**
     * Lock order: world EX → неблокирующий захват всех save → runtime snapshot.
     * После отпускания world больше его не захватываем до освобождения save.
     * Игровые checkpoint не ждут SQL. Все SQL-строки снимка коммитятся вместе,
     * ACK только после COMMIT; новая версия RAM остаётся dirty.
     */
    public function saveInBackground(): bool
    {
        $release = null; $started = hrtime(true); $transaction = false;
        $db = Database::independent();
        $snapshotBaseline = memory_get_usage();
        self::$snapshotStartMemory = $snapshotBaseline;
        self::$snapshotLimit = $this->snapshotMaxBytes;
        memory_reset_peak_usage();
        RuntimeMetrics::gauge('storage.snapshotLimitBytes', $this->snapshotMaxBytes);
        RuntimeMetrics::gauge('storage.snapshotBytes', 0);
        try {
            $snapshot = MiningCheckpoints::worldLocked(function () use (&$release): array {
                $release = RepositoryGuard::acquireMany(array_map(static fn(string $repository): string => $repository::saveGuardKey(), $this->repositories));
                // Защищённые строки в другом процессе не определяются его PHP-кэшем.
                // Незавершённый RAM-checkpoint восстанавливает только игровой цикл.
                if (MiningCheckpoints::pending() !== []) throw new \RuntimeException('Ожидается recovery checkpoint.', MiningCheckpoints::LOCK_BUSY);
                $captureStarted = hrtime(true);
                try {
                    $result = [];
                    foreach ($this->repositories as $repository) {
                        $rows = $repository::captureSaveSnapshot(true);
                        $count = $repository === BattleReports::class ? count($rows['reports']) + count($rows['saves']) : count($rows);
                        if ($count > 0) $result[$repository] = $rows;
                    }
                    $snapshot = ['repositories' => $result, 'events' => PlayerQueue::captureRestartSnapshot(true)];
                    self::assertSnapshotBudget();
                    return $snapshot;
                } finally {
                    RuntimeMetrics::gauge('storage.snapshotCaptureMs', (hrtime(true) - $captureStarted) / 1000000);
                    RuntimeMetrics::gauge('storage.snapshotBytes', max(0, memory_get_usage() - self::$snapshotStartMemory));
                }
            }, true);
            $rows = count($snapshot['events']);
            foreach ($snapshot['repositories'] as $repository => $entries) {
                $rows += $repository === BattleReports::class ? count($entries['reports']) + count($entries['saves']) : count($entries);
            }
            RuntimeMetrics::gauge('storage.snapshotRows', $rows);
            {
                $db->beginTransaction(); $transaction = true;
                foreach ($snapshot['repositories'] as $repository => $entries) $repository::writeSaveSnapshot($entries, $db);
                PlayerQueue::writeRestartSnapshot($snapshot['events'], $db);
                self::assertSnapshotBudget();
                $db->commit(); $transaction = false;
                // COMMIT уже не откатить. Не прерываем ACK бюджетным
                // исключением; фактический пик ACK всё равно измеряем.
                self::$snapshotStartMemory = null;
                foreach ($snapshot['repositories'] as $repository => $entries) $repository::acknowledgeSaveSnapshot($entries);
            }
            RuntimeMetrics::gauge('storage.lastSavedAt', microtime(true));
            $this->logger->info('Фоновый снимок сохранён', ['rows' => $rows, 'pid' => getmypid()]);
            return true;
        } catch (\RuntimeException $error) {
            if ($error->getCode() === MiningCheckpoints::LOCK_BUSY) {
                RuntimeMetrics::increment('storage.snapshotLockMisses');
                return false;
            }
            throw $error;
        } finally {
            if ($transaction) {
                try { $db->rollBack(); } catch (\Throwable) { /* Неподтверждённый ACK не выдаём. */ }
            }
            if ($release !== null) $release();
            RuntimeMetrics::gauge('storage.snapshotPeakBytes', max(0, memory_get_peak_usage() - $snapshotBaseline));
            self::$snapshotStartMemory = null;
            RuntimeMetrics::observe('repository.flush', (hrtime(true) - $started) / 1000000);
        }
    }

    public function __construct()
    {
        $this->logger = Logger::getInstance();
        $interval = Environment::get('GAME_SAVE_INTERVAL_SECONDS', '30');
        if (!is_string($interval) || !preg_match('/^[1-9][0-9]*$/D', $interval)
            || strlen($interval) > 6 || (int)$interval > 86400) {
            throw new \InvalidArgumentException('GAME_SAVE_INTERVAL_SECONDS должен быть целым числом от1 до86400.');
        }
        $this->saveIntervalSeconds = (int)$interval;
        $this->snapshotMaxBytes = self::configuredSnapshotMaxBytes();
    }

    /** Та же настройка для saver и read-only аудитора, без init игрового мира. */
    public static function configuredSnapshotMaxBytes(): int
    {
        $snapshotMiB = Environment::getInt('GAME_SAVE_SNAPSHOT_MAX_MIB', 128);
        if ($snapshotMiB < 8 || $snapshotMiB > 1024) throw new \InvalidArgumentException('GAME_SAVE_SNAPSHOT_MAX_MIB должен быть от8 до1024.');
        return $snapshotMiB * 1048576;
    }

    public function intervalSeconds(): int { return $this->saveIntervalSeconds; }

    /**
     * Регистрируем класс репозитория
     */
    public function register(string $repositoryClass): void
    {
        if (is_subclass_of($repositoryClass, BaseRepository::class)
            || in_array($repositoryClass, [MiningCheckpoints::class, ProtocolCommands::class, BattleReports::class], true)) {
            $this->repositories[] = $repositoryClass;
        } else {
            $this->logger->warning("Попытка зарегистрировать не-репозиторий: $repositoryClass");
        }
    }

    public function saveAll(bool $strict = false): void
    {
        $started = hrtime(true);
        try {
            FleetMiningCheckpointService::world(function () use ($strict): void {
                if ($strict) FleetsData::getInstance()->save();
            }, true);
            if (!Database::persistence(fn(): bool => $this->saveInBackground()) && $strict) throw new \RuntimeException('Не выполнен финальный снимок мира.');
        } finally { \SPGame\Core\RuntimeMetrics::observe('repository.flush', (hrtime(true) - $started) / 1000000); }
    }

}
