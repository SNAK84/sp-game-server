<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;

/** Неизменяемый архив, а не runtime игрового мира: читается из MySQL по запросу. */
final class BattleReports
{
    public const RETENTION_SECONDS = 7 * 86400;
    public const MAX_BYTES = 1048576;
    public const PAGE_SIZE = 30;
    private static string $reportsTable = 'battle_reports';
    private static string $savesTable = 'battle_report_saves';
    private static ?Database $database = null;

    private static function db(): Database
    {
        return self::$database ?? Database::getInstance();
    }

    public static function init(): void
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
    }

    public static function validateId(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new \InvalidArgumentException('Боевой доклад недоступен.');
        }
        return $id;
    }

    /** Долговечная подготовка ДО gameplay commit; prepared-доклад ещё не виден игрокам. */
    public static function prepare(array $report, array $summary): void
    {
        $id = self::validateId($report['battleId'] ?? null);
        $json = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $short = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > self::MAX_BYTES || strlen($short) > 8192) {
            throw new \OverflowException('Превышен размер боевого доклада.');
        }
        $time = $report['finishedAt'] ?? null;
        if (($report['version'] ?? null) !== 1 || !is_numeric($time) || !is_finite((float)$time) || $time <= 0) {
            throw new \InvalidArgumentException('Некорректный снимок боевого доклада.');
        }
        $table = self::$reportsTable;
        // Повтор после runtime rollback может пересобрать ещё не опубликованный итог.
        // Опубликованный snapshot никогда не перезаписываем.
        self::db()->query("INSERT INTO `$table` (id,version,created_at,expires_at,summary,payload)
            VALUES (?,1,?,?,?,?) ON DUPLICATE KEY UPDATE
            version=IF(ready=0,VALUES(version),version),
            created_at=IF(ready=0,VALUES(created_at),created_at),
            expires_at=IF(ready=0,VALUES(expires_at),expires_at),
            summary=IF(ready=0,VALUES(summary),summary), payload=IF(ready=0,VALUES(payload),payload)",
            [$id, (float)$time, (int)ceil((float)$time + self::RETENTION_SECONDS), $short, $json]);
        $stored = self::db()->fetchOne("SELECT payload FROM `$table` WHERE id=?", [$id]);
        if (($stored['payload'] ?? null) !== $json) throw new \RuntimeException('Архив боя не подтверждён или уже содержит другой результат.');
    }

    /** Только после подтверждённого runtime commit. Повторная публикация безопасна. */
    public static function publish(string $id): void
    {
        self::validateId($id);
        $table = self::$reportsTable;
        self::db()->query("UPDATE `$table` SET ready=1 WHERE id=?", [$id]);
        $row = self::db()->fetchOne("SELECT ready FROM `$table` WHERE id=?", [$id]);
        if ((int)($row['ready'] ?? 0) !== 1) throw new \RuntimeException('Не удалось опубликовать боевой доклад.');
    }

    /** Любой авторизованный игрок с непредсказуемым ID-ссылкой может читать доклад. */
    public static function get(string $id, int $userId, ?float $now = null): ?array
    {
        self::validateId($id);
        self::requireUser($userId);
        $table = self::$reportsTable;
        $saves = self::$savesTable;
        $row = self::db()->fetchOne("SELECT r.payload,r.created_at,r.expires_at,r.saved_count,
            EXISTS(SELECT 1 FROM `$saves` s WHERE s.report_id=r.id AND s.user_id=?) AS saved_by_me
            FROM `$table` r WHERE r.id=? AND r.ready=1 AND (r.expires_at>? OR r.saved_count>0)",
            [$userId, $id, (int)floor($now ?? microtime(true))]);
        if ($row === null) return null;
        return ['report' => json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
            'savedByMe' => (bool)$row['saved_by_me'],
            'expiresAt' => (int)$row['saved_count'] > 0 ? null : (float)$row['expires_at']];
    }

    public static function setSaved(string $id, int $userId, bool $save, ?float $now = null): void
    {
        self::validateId($id);
        self::requireUser($userId);
        $now = (int)floor($now ?? microtime(true));
        $db = self::db();
        $reports = self::$reportsTable;
        $saves = self::$savesTable;
        // Счётчик и личная закладка атомарны. Тот же lock сериализует expiry DELETE.
        $db->beginTransaction();
        try {
            $report = $db->fetchOne("SELECT ready,expires_at,saved_count FROM `$reports` WHERE id=? FOR UPDATE", [$id]);
            if ($report === null || !(int)$report['ready'] || ((float)$report['expires_at'] <= $now && (int)$report['saved_count'] === 0)) {
                throw new \InvalidArgumentException('Боевой доклад недоступен или срок хранения истёк.');
            }
            $existing = $db->fetchOne("SELECT user_id FROM `$saves` WHERE report_id=? AND user_id=?", [$id, $userId]);
            if ($save && $existing === null) {
                $db->query("INSERT INTO `$saves` (report_id,user_id,saved_at) VALUES (?,?,?)", [$id, $userId, $now]);
                $db->query("UPDATE `$reports` SET saved_count=saved_count+1 WHERE id=?", [$id]);
            } elseif (!$save && $existing !== null) {
                $db->query("DELETE FROM `$saves` WHERE report_id=? AND user_id=?", [$id, $userId]);
                $db->query("UPDATE `$reports` SET saved_count=saved_count-1 WHERE id=? AND saved_count>0", [$id]);
            }
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    public static function savedFor(int $userId, int $page = 1): array
    {
        self::requireUser($userId);
        $page = max(1, min(100000, $page));
        $offset = ($page - 1) * self::PAGE_SIZE;
        $limit = self::PAGE_SIZE + 1;
        $reports = self::$reportsTable;
        $saves = self::$savesTable;
        $rows = self::db()->fetchAll("SELECT r.id,r.created_at,r.summary,s.saved_at
            FROM `$saves` s JOIN `$reports` r ON r.id=s.report_id
            WHERE s.user_id=? AND r.ready=1 ORDER BY s.saved_at DESC,r.id DESC LIMIT $limit OFFSET $offset", [$userId]);
        $more = count($rows) > self::PAGE_SIZE;
        return ['page' => $page, 'hasMore' => $more, 'reports' => array_map(static fn($row) => [
            'id' => $row['id'], 'createdAt' => (float)$row['created_at'],
            'summary' => json_decode($row['summary'], true, 512, JSON_THROW_ON_ERROR),
        ], array_slice($rows, 0, self::PAGE_SIZE))];
    }

    /** Маленькая пачка; чтение также проверяет срок, не ожидая cleanup. */
    public static function deleteExpired(?float $now = null): void
    {
        $table = self::$reportsTable;
        $now = (int)floor($now ?? microtime(true));
        $candidates = self::db()->fetchAll("SELECT id FROM `$table`
            WHERE ready=1 AND saved_count=0 AND expires_at<=? ORDER BY expires_at LIMIT 100", [$now]);
        foreach ($candidates as $row) {
            $db = self::db();
            $db->beginTransaction();
            try {
                // Явно берём PRIMARY lock ДО DELETE: optimizer иначе может выбрать
                // idx_expiry даже при WHERE id=? и изменить порядок блокировок.
                $current = $db->fetchOne("SELECT ready,saved_count,expires_at FROM `$table` WHERE id=? FOR UPDATE", [$row['id']]);
                if ($current !== null && (int)$current['ready'] === 1 && (int)$current['saved_count'] === 0
                    && (int)$current['expires_at'] <= $now) {
                    $db->query("DELETE FROM `$table` WHERE id=?", [$row['id']]);
                }
                $db->commit();
            } catch (\Throwable $error) {
                $db->rollBack();
                throw $error;
            }
        }
    }

    private static function requireUser(int $userId): void
    {
        if ($userId <= 0) throw new \InvalidArgumentException('Требуется авторизация игрока.');
    }
}
