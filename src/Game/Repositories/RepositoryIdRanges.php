<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use Swoole\Atomic\Long;

/** Долговечный резерв ID; атомики создаются в master до fork воркеров. */
final class RepositoryIdRanges
{
    public const BLOCK_SIZE = 1024;
    private static bool $schemaReady = false;
    private Long $cursor;
    private Long $limit;

    public function __construct(private readonly string $repository, int $minimum)
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $repository) || $minimum < 0) {
            throw new \InvalidArgumentException('Некорректное пространство ID репозитория.');
        }
        // Не сохраняем PDO до fork и не используем соединение игрового checkpoint.
        $db = Database::independent();
        if (!self::$schemaReady) {
            $db->query('CREATE TABLE IF NOT EXISTS repository_id_ranges (
                repository VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                high_water BIGINT UNSIGNED NOT NULL
            ) ENGINE=InnoDB');
            self::$schemaReady = true;
        }
        $native = $db->fetchOne('SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$repository]);
        $minimum = max($minimum, (int)($native['next_id'] ?? 0) - 1);
        $db->query('INSERT INTO repository_id_ranges (repository, high_water) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE high_water = GREATEST(high_water, VALUES(high_water))', [$repository, $minimum]);
        $row = $db->fetchOne('SELECT high_water FROM repository_id_ranges WHERE repository = ?', [$repository]);
        if ($row === null || (int)$row['high_water'] < $minimum) {
            throw new \RuntimeException('Не удалось восстановить долговечный резерв ID.');
        }
        // Неиспользованный остаток прежнего процесса намеренно пропускается.
        $this->cursor = new Long((int)$row['high_water']);
        $this->limit = new Long((int)$row['high_water']);
    }

    public function current(): int
    {
        return $this->cursor->get();
    }

    public function next(): int
    {
        while (true) {
            $current = $this->cursor->get();
            if ($current < $this->limit->get()) {
                // Возвращаем именно присвоенный ID, а не более позднее чтение meta.
                if ($this->cursor->cmpset($current, $current + 1)) return $current + 1;
                continue;
            }
            $this->locked(function (): void {
                if ($this->cursor->get() < $this->limit->get()) return;
                $this->refill($this->cursor->get());
            });
        }
    }

    /** Совместимость setLastId: разрешено только поднять нижнюю границу. */
    public function advanceTo(int $minimum): void
    {
        if ($minimum <= $this->cursor->get()) return;
        $this->locked(function () use ($minimum): void {
            if ($minimum > $this->limit->get()) {
                // Закрываем старый диапазон до SQL. Конкурентный fast path
                // больше не может выдать ID из диапазона, который пропускаем.
                $this->raiseCursor($this->limit->get());
                $this->refill($minimum);
            } else {
                $this->raiseCursor($minimum);
            }
        });
    }

    private function raiseCursor(int $minimum): void
    {
        while (($current = $this->cursor->get()) < $minimum) {
            if ($this->cursor->cmpset($current, $minimum)) return;
        }
    }

    private function refill(int $minimum): void
    {
        $db = Database::independent();
        $db->beginTransaction();
        try {
            $row = $db->fetchOne('SELECT high_water FROM repository_id_ranges WHERE repository = ? FOR UPDATE', [$this->repository]);
            if ($row === null) throw new \RuntimeException('Потерян долговечный счётчик ID.');
            $start = max($minimum, (int)$row['high_water']);
            if ($start > PHP_INT_MAX - self::BLOCK_SIZE) throw new \OverflowException('Исчерпаны ID репозитория.');
            $end = $start + self::BLOCK_SIZE;
            $db->query('UPDATE repository_id_ranges SET high_water = GREATEST(high_water, ?) WHERE repository = ?', [$end, $this->repository]);
            $db->commit();
        } catch (\Throwable $error) {
            try { $db->rollBack(); } catch (\Throwable) { /* Сохраняем исходную ошибку. */ }
            throw $error; // Ни одного ID из неподтверждённого диапазона.
        }
        // Порядок публикации важен: сначала cursor, затем разрешающий limit.
        // При смерти worker между commit и публикацией останется только пропуск.
        $this->raiseCursor($start);
        $this->limit->set($end);
    }

    /** flock переоткрывается после fork; падение worker освобождает блокировку. */
    private function locked(callable $callback): void
    {
        $path = dirname(__DIR__, 3) . '/logs/.repository-id-' . $this->repository . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false) throw new \RuntimeException('Не открыть блокировку резерва ID.');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Не захвачена блокировка резерва ID.');
            $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
