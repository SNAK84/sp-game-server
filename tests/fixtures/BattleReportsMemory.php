<?php
declare(strict_types=1);

/** Только unit/native combat fixtures: реальный SQL архива проверяет BattleReportsMysqlTest. */
final class BattleReportsMemory
{
    public static array $rows = [];
    public static bool $failPrepare = false;
    public static bool $failPublish = false;

    public static function reset(): void
    {
        self::$rows = [];
        self::$failPrepare = self::$failPublish = false;
    }

    public static function prepare(array $report, array $summary): void
    {
        if (self::$failPrepare) throw new RuntimeException('Injected archive SQL failure');
        self::$rows[$report['battleId']] = ['report' => $report, 'summary' => $summary, 'ready' => false];
    }

    public static function publish(string $id): void
    {
        if (self::$failPublish) throw new RuntimeException('Injected archive publish failure');
        if (!isset(self::$rows[$id])) throw new RuntimeException('Missing prepared report');
        self::$rows[$id]['ready'] = true;
    }
}

class_alias(BattleReportsMemory::class, SPGame\Game\Repositories\BattleReports::class);
