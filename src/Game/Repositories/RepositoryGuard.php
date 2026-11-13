<?php
declare(strict_types=1);

namespace SPGame\Game\Repositories;

/** Короткие межпроцессные секции; дескрипторы не наследуются как активные locks. */
final class RepositoryGuard
{
    private static array $held = [];

    /** Короткий захват нескольких save-locks; освобождение в обратном порядке. */
    public static function acquireMany(array $keys): \Closure
    {
        sort($keys, SORT_STRING);
        $handles = [];
        $release = static function () use (&$handles): void {
            foreach (array_reverse($handles, true) as $slot => $handle) {
                unset(self::$held[$slot]);
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            $handles = [];
        };
        try {
            foreach (array_unique($keys) as $key) {
                $slot = getmypid() . ':' . $key;
                if (isset(self::$held[$slot])) throw new \LogicException('Повторный захват набора save-locks.');
                $handle = fopen(dirname(__DIR__, 3) . '/logs/.repository-' . hash('sha256', $key) . '.lock', 'c');
                if ($handle === false) throw new \RuntimeException('Не открыть блокировку сохранения.');
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    fclose($handle);
                    throw new \RuntimeException('Репозиторий сохраняется другим обработчиком.', MiningCheckpoints::LOCK_BUSY);
                }
                self::$held[$slot] = true;
                $handles[$slot] = $handle;
            }
        } catch (\Throwable $error) { $release(); throw $error; }
        return $release;
    }

    public static function run(string $key, callable $operation): mixed
    {
        $slot = getmypid() . ':' . $key;
        if (isset(self::$held[$slot])) return $operation();
        $handle = fopen(dirname(__DIR__, 3) . '/logs/.repository-' . hash('sha256', $key) . '.lock', 'c');
        if ($handle === false) throw new \RuntimeException('Не открыть блокировку репозитория.');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Не захвачена блокировка репозитория.');
            self::$held[$slot] = true;
            return $operation();
        } finally {
            unset(self::$held[$slot]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
