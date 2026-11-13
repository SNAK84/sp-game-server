<?php

namespace SPGame\Game\Repositories;

use Swoole\Table;
use Swoole\Atomic\Long;

/** Версия полной ресурсной проекции в пределах epoch; обновляется под world EX. */
final class ProtocolResources
{
    private const MAX_ENTRIES = 16384;
    private const EVICTION_SAMPLE = 64;
    private static Table $table;
    private static Long $sequence;

    public static function init(): void
    {
        // Полный collision pool позволяет сохранить MAX_ENTRIES даже при
        // неравномерном хеше ключей. Это кэш, не реестр всех аккаунтов мира.
        self::$table = new Table(self::MAX_ENTRIES, 1.0);
        self::$sequence = new Long(0);
        self::$table->column('digest', Table::TYPE_STRING, 64);
        self::$table->column('revision', Table::TYPE_INT, 8);
        self::$table->column('touched', Table::TYPE_FLOAT);
        if (!self::$table->create()) throw new \RuntimeException('Не создан реестр версий ресурсов v2.');
    }

    public static function revision(string $key, mixed $value): string
    {
        $key = substr($key, 0, 32); // Swoole Table допускает ключ максимум 63 байта.
        $digest = hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $old = self::$table->get($key);
        if ($old !== false && hash_equals($old['digest'], $digest)) {
            if (!self::$table->set($key, ['touched' => microtime(true)])) {
                throw new \RuntimeException('Не обновлена версия ресурса v2.');
            }
            return (string)$old['revision'];
        }
        if ($old === false && self::$table->count() >= self::MAX_ENTRIES) {
            $oldest = null; $time = INF;
            // При заполненном кэше не сканируем все 16K строк на каждый query.
            // Приближённый LRU по ограниченной выборке; новые revisions всегда
            // берутся из общей sequence, поэтому eviction не откатывает версию.
            $sampled = 0;
            foreach (self::$table as $candidate => $row) {
                if ($row['touched'] < $time) { $time = $row['touched']; $oldest = $candidate; }
                if (++$sampled >= self::EVICTION_SAMPLE) break;
            }
            if ($oldest !== null) self::$table->del($oldest);
        }
        $revision = self::$sequence->add();
        if (!self::$table->set($key, ['digest' => $digest, 'revision' => $revision, 'touched' => microtime(true)])) throw new \RuntimeException('Не сохранена версия ресурса v2.');
        return (string)$revision;
    }
}
