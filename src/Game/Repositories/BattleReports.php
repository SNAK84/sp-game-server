<?php
declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Game\Services\RepositorySaver;

/** Доклады и закладки доступны из RAM; SQL получает их в общем снимке мира. */
final class BattleReports
{
    public const RETENTION_SECONDS = 7 * 86400;
    public const MAX_BYTES = 1048576;
    public const PAGE_SIZE = 30;
    private static string $reportsTable = 'battle_reports';
    private static string $savesTable = 'battle_report_saves';
    private static ?Database $database = null;
    private static RuntimeRecords $reports;
    private static RuntimeRecords $saves;
    /** Производный owner-индекс authority-worker (SWOOLE_BASE/worker_num=1).
     * Saver меняет только dirty/удалённые строки, не состав живых закладок.
     * @var array<int, array<string, float>>
     */
    private static array $savedByUser = [];

    private static function db(): Database { return self::$database ?? Database::getInstance(); }

    public static function init(?RepositorySaver $saver = null): void
    {
        $reports = self::$reportsTable;
        $saves = self::$savesTable;
        self::db()->query("CREATE TABLE IF NOT EXISTS `$reports` (
            id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            version SMALLINT UNSIGNED NOT NULL,
            created_at DOUBLE NOT NULL,
            expires_at BIGINT UNSIGNED NOT NULL,
            ready TINYINT UNSIGNED NOT NULL DEFAULT 0,
            saved_count INT UNSIGNED NOT NULL DEFAULT 0,
            summary TEXT NOT NULL,
            payload MEDIUMTEXT NOT NULL,
            INDEX idx_expiry (ready, saved_count, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::db()->query("CREATE TABLE IF NOT EXISTS `$saves` (
            report_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            saved_at DOUBLE NOT NULL,
            PRIMARY KEY (report_id, user_id),
            INDEX idx_saved_user (user_id, saved_at),
            FOREIGN KEY (report_id) REFERENCES `$reports` (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::$reports = new RuntimeRecords('BattleReports', self::MAX_BYTES * 6 + 32768);
        self::$saves = new RuntimeRecords('BattleReportSaves', 1024);
        self::$savedByUser = [];
        $cursor = '';
        do {
            $rows = self::db()->fetchAll("SELECT * FROM `$reports` WHERE (saved_count>0 OR expires_at>? OR ready=0) AND id>? ORDER BY id LIMIT 128", [microtime(true), $cursor]);
            foreach ($rows as $row) { self::$reports->put($row['id'], $row, false); $cursor = $row['id']; }
        } while (count($rows) === 128);
        $cursor = ['', 0];
        do {
            $rows = self::db()->fetchAll("SELECT s.* FROM `$saves` s JOIN `$reports` r ON r.id=s.report_id WHERE (r.saved_count>0 OR r.expires_at>? OR r.ready=0) AND (s.report_id,s.user_id)>(?,?) ORDER BY s.report_id,s.user_id LIMIT 512", [microtime(true), ...$cursor]);
            foreach ($rows as $row) {
                self::$saves->put($row['report_id'] . ':' . $row['user_id'], $row, false);
                self::$savedByUser[(int)$row['user_id']][$row['report_id']] = (float)$row['saved_at'];
                $cursor = [$row['report_id'], (int)$row['user_id']];
            }
        } while (count($rows) === 512);
        $saver?->register(self::class);
    }

    public static function validateId(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/\\A[a-f0-9]{32}\\z/D', $id)) throw new \InvalidArgumentException('Боевой доклад недоступен.');
        return $id;
    }

    public static function prepare(array $report, array $summary): void
    {
        $id = self::validateId($report['battleId'] ?? null);
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $short = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > self::MAX_BYTES || strlen($short) > 8192) throw new \OverflowException('Превышен размер боевого доклада.');
        $time = $report['finishedAt'] ?? null;
        if (($report['version'] ?? null) !== 1 || !is_numeric($time) || !is_finite((float)$time) || $time <= 0) throw new \InvalidArgumentException('Некорректный снимок боевого доклада.');
        $old = self::$reports->get($id);
        if ($old !== null && (int)$old['ready'] === 1) {
            if ($old['payload'] !== $json) throw new \RuntimeException('Опубликован другой результат боя.');
            return;
        }
        self::$reports->put($id, ['id' => $id, 'version' => 1, 'created_at' => (float)$time,
            'expires_at' => (int)ceil($time + self::RETENTION_SECONDS), 'ready' => 0,
            'saved_count' => (int)($old['saved_count'] ?? 0), 'summary' => $short, 'payload' => $json]);
    }

    public static function publish(string $id): void
    {
        self::validateId($id);
        $row = self::$reports->get($id) ?? throw new \RuntimeException('Не подготовлен боевой доклад.');
        $row['ready'] = 1;
        self::$reports->put($id, $row);
    }

    public static function get(string $id, int $userId, ?float $now = null): ?array
    {
        self::validateId($id); self::requireUser($userId);
        $row = self::$reports->get($id);
        if ($row === null || !(int)$row['ready'] || $row['expires_at'] <= floor($now ?? microtime(true)) && (int)$row['saved_count'] === 0) return null;
        return ['report' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
            'savedByMe' => self::$saves->get($id . ':' . $userId) !== null,
            'expiresAt' => (int)$row['saved_count'] > 0 ? null : (float)$row['expires_at']];
    }

    public static function setSaved(string $id, int $userId, bool $save, ?float $now = null): void
    {
        self::validateId($id); self::requireUser($userId);
        RepositoryGuard::run('BattleReports:runtime', static function () use ($id, $userId, $save, $now): void {
            $row = self::$reports->get($id);
            $now = floor($now ?? microtime(true));
            if ($row === null || !(int)$row['ready'] || $row['expires_at'] <= $now && (int)$row['saved_count'] === 0) throw new \InvalidArgumentException('Боевой доклад недоступен или срок хранения истёк.');
            $key = $id . ':' . $userId;
            $old = self::$saves->get($key);
            if ($save === ($old !== null)) return;
            if ($save) self::$saves->put($key, ['report_id' => $id, 'user_id' => $userId, 'saved_at' => (float)$now]);
            else self::$saves->delete($key);
            try {
                $row['saved_count'] += $save ? 1 : -1;
                if ($row['saved_count'] < 0) throw new \LogicException('Повреждён счётчик закладок.');
                self::$reports->put($id, $row);
            } catch (\Throwable $error) {
                if ($old === null) self::$saves->delete($key); else self::$saves->put($key, $old);
                throw $error;
            }
            // Публикуем индекс только после успешной записи закладки и счётчика.
            if ($save) self::$savedByUser[$userId][$id] = (float)$now;
            else {
                unset(self::$savedByUser[$userId][$id]);
                if (self::$savedByUser[$userId] === []) unset(self::$savedByUser[$userId]);
            }
        });
    }

    public static function savedFor(int $userId, int $page = 1): array
    {
        self::requireUser($userId);
        $page = max(1, min(100000, $page));
        $rows = [];
        foreach (self::$savedByUser[$userId] ?? [] as $id => $savedAt) {
            $rows[] = ['report_id' => $id, 'saved_at' => $savedAt];
        }
        usort($rows, static fn(array $a, array $b): int => $b['saved_at'] <=> $a['saved_at'] ?: strcmp($b['report_id'], $a['report_id']));
        $offset = ($page - 1) * self::PAGE_SIZE;
        return ['page' => $page, 'hasMore' => count($rows) > $offset + self::PAGE_SIZE,
            'reports' => array_map(static function (array $saved): array {
                $row = self::$reports->get($saved['report_id']) ?? throw new \RuntimeException('Потерян сохранённый доклад.');
                return ['id' => $row['id'], 'createdAt' => (float)$row['created_at'], 'summary' => json_decode($row['summary'], true, 512, JSON_THROW_ON_ERROR)];
            }, array_slice($rows, $offset, self::PAGE_SIZE))];
    }

    public static function deleteExpired(?float $now = null): void
    {
        $now = floor($now ?? microtime(true));
        self::$reports->sweep(static fn(array $row): bool => (int)$row['ready'] === 1 && (int)$row['saved_count'] === 0 && $row['expires_at'] <= $now,
            RuntimeRecords::sweepBudget('BattleReports', 128), $now);
    }

    public static function saveGuardKey(): string { return 'BattleReports:save'; }
    public static function purgeExpiredSql(float $now): void
    {
        RepositoryGuard::run(self::saveGuardKey(), static fn() => self::db()->executeAffected('DELETE FROM battle_reports WHERE ready=1 AND saved_count=0 AND expires_at<=? ORDER BY expires_at LIMIT 100', [(int)$now]));
    }
    public static function captureSaveSnapshot(): array { return ['reports' => self::$reports->snapshot(), 'saves' => self::$saves->snapshot()]; }
    public static function acknowledgeSaveSnapshot(array $snapshot): void { self::$reports->acknowledge($snapshot['reports']); self::$saves->acknowledge($snapshot['saves']); }
    public static function syncStats(float $now): array
    {
        $a = self::$reports->stats($now); $b = self::$saves->stats($now);
        return ['pending' => $a['pending'] + $b['pending'], 'oldestDirtySeconds' => max($a['oldestDirtySeconds'], $b['oldestDirtySeconds'])];
    }

    public static function writeSaveSnapshot(array $snapshot, Database $db): void
    {
        $reports = self::$reportsTable; $saves = self::$savesTable;
        foreach ($snapshot['reports'] as $entry) if (!$entry['deleted']) {
            $row = self::$reports->snapshotValue($entry);
            $fields = ['id','version','created_at','expires_at','ready','saved_count','summary','payload'];
            $db->query("INSERT INTO `$reports` (" . implode(',', $fields) . ') VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE '
                . implode(',', array_map(static fn(string $f): string => $f . '=VALUES(' . $f . ')', $fields)),
                array_map(static fn(string $f) => is_float($row[$f]) ? json_encode($row[$f], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : $row[$f], $fields));
        }
        foreach ($snapshot['saves'] as $entry) {
            $row = self::$saves->snapshotValue($entry);
            if ($entry['deleted']) $db->query("DELETE FROM `$saves` WHERE report_id=? AND user_id=?", [$row['report_id'], $row['user_id']]);
            else $db->query("INSERT INTO `$saves` (report_id,user_id,saved_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE saved_at=VALUES(saved_at)",
                [$row['report_id'], $row['user_id'], json_encode((float)$row['saved_at'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]);
        }
        foreach ($snapshot['reports'] as $entry) if ($entry['deleted']) $db->query("DELETE FROM `$reports` WHERE id=?", [self::$reports->snapshotValue($entry)['id']]);
    }

    private static function requireUser(int $userId): void
    {
        if ($userId <= 0) throw new \InvalidArgumentException('Требуется авторизация игрока.');
    }
}
