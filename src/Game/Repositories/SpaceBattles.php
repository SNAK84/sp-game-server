<?php

declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Logger;
use SPGame\Game\Services\RepositorySaver;
use Swoole\Table;

/** Checkpoint боя в пространстве с собственным ID, НЕ ID планеты/целевого Fleet. */
final class SpaceBattles extends BaseRepository
{
    public const MAX_STATE_BYTES = PlanetBattles::MAX_STATE_BYTES;
    protected static Table $table;
    protected static Table $syncTable;
    protected static array $indexTables = [];
    protected static array $indexes = [];
    protected static string $className = 'SpaceBattles';
    protected static string $tableName = 'space_battles';
    protected static int $tableSize = 128;
    protected static array $tableSchema = [
        'columns' => [
            'id' => ['swoole' => [Table::TYPE_INT, 8], 'sql' => 'BIGINT UNSIGNED NOT NULL', 'default' => 0],
            'phase' => ['swoole' => [Table::TYPE_STRING, 16], 'sql' => 'VARCHAR(16) NOT NULL', 'default' => 'running'],
            'state' => ['swoole' => [Table::TYPE_STRING, self::MAX_STATE_BYTES], 'sql' => 'MEDIUMTEXT NOT NULL', 'default' => ''],
        ],
        'indexes' => [['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']]],
    ];

    public static function findById(int $id): ?array
    {
        return isset(static::$table) ? (static::$table->get((string)$id) ?: null) : null;
    }

    public static function state(int $key): ?array
    {
        $row = self::findById($key);
        return $row === null ? null : json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function store(int $key, array $state): void
    {
        if ($key <= 0 || !in_array($state['phase'] ?? null, ['running', 'finished', 'settled', 'failed', 'applied'], true)) {
            throw new \InvalidArgumentException('Некорректное состояние боя в пространстве.');
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > self::MAX_STATE_BYTES) throw new \OverflowException('Состояние боя превышает вместимость runtime записи.');
        $before = static::findExistingById($key);
        static::writeRuntimeRow(['id' => $key, 'phase' => $state['phase'], 'state' => $encoded],
            $before, true, $before === null);
    }

    /** DELETE сохраняет runtime-строку до ACK: нужен точный образ для сверки. */
    public static function captureSaveSnapshot(bool $compact = false): array
    {
        return static::guarded(static function () use ($compact): array {
            $snapshot = parent::captureSaveSnapshot($compact);
            foreach ($snapshot as $key => $saved) {
                $entry = RepositorySaver::unpackSnapshotEntry($saved);
                if (empty($entry['marker']['need_delete'])) continue;
                $retained = static::findExistingById((int)$key);
                if ($retained === null) continue; // Обычный DELETE уже удалённой строки.
                $state = json_decode($retained['state'], true, 512, JSON_THROW_ON_ERROR);
                if ($retained['phase'] !== 'applied' || empty($state['cleanupRequested'])) {
                    throw new \LogicException('Нельзя сохранить DELETE незавершённого checkpoint.');
                }
                $entry['retainedRow'] = $retained;
                $snapshot[$key] = $compact ? RepositorySaver::packSnapshotEntry($entry) : $entry;
                RepositorySaver::assertSnapshotBudget();
            }
            return $snapshot;
        });
    }

    /** Только после COMMIT saver. SQL и gameplay здесь не исполняются. */
    public static function acknowledgeSaveSnapshot(array $snapshot): void
    {
        foreach ($snapshot as $key => $saved) {
            $entry = RepositorySaver::unpackSnapshotEntry($saved);
            if (!isset($entry['retainedRow'])) { parent::acknowledgeSaveSnapshot([$key => $entry]); continue; }
            static::guarded(static function () use ($key, $entry): void {
                if (empty($entry['marker']['need_delete']) || $entry['row'] !== null) {
                    throw new \UnexpectedValueException('Неверный retained DELETE снимка.');
                }
                if (static::$syncTable->get((string)$key) !== $entry['marker']
                    || static::findExistingById((int)$key) !== $entry['retainedRow']) return;
                if (!static::$syncTable->del((string)$key)) throw new \RuntimeException('Не подтверждён DELETE checkpoint боя.');
            });
        }
    }

    public static function active(): array
    {
        $result = [];
        if (isset(static::$table)) foreach (static::$table as $row) if ($row['phase'] !== 'applied') {
            $result[(int)$row['id']] = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $result;
    }

    public static function forFleet(int $fleetId): ?array
    {
        foreach (self::active() as $key => $state) if (in_array($fleetId, $state['fleets'], true)) return ['key' => $key, 'state' => $state];
        return null;
    }

    /** Слот остаётся занят до подтверждённого SQL DELETE; история хранится в BattleReports. */
    public static function requestCleanup(int $key, string $battleId): void
    {
        static::guarded(static function () use ($key, $battleId): void {
            $before = static::$table->get((string)$key);
            $state = self::state($key);
            $marker = static::$syncTable->get((string)$key);
            if ($state === null || $state['id'] !== $battleId || $state['phase'] !== 'applied'
                || !empty($marker['need_update']) || !empty($marker['need_delete'])) throw new \LogicException('Checkpoint ещё не сохранён или уже очищается.');
            $state['cleanupRequested'] = true;
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($encoded) > self::MAX_STATE_BYTES) throw new \OverflowException('Нет места для marker очистки боя.');
            try {
                if (!static::$table->set((string)$key, array_replace($before, ['state' => $encoded]))
                    || (static::$table->get((string)$key)['state'] ?? null) !== $encoded) throw new \RuntimeException('Не сохранён marker очистки.');
                static::markForDelete($key);
                if ((static::$syncTable->get((string)$key)['need_delete'] ?? 0) !== 1) throw new \RuntimeException('Не поставлен DELETE checkpoint.');
            } catch (\Throwable $error) {
                $rowOk = static::$table->set((string)$key, $before);
                $markerOk = $marker ? static::$syncTable->set((string)$key, $marker)
                    : (!static::$syncTable->exists((string)$key) || static::$syncTable->del((string)$key));
                if (!$rowOk || !$markerOk) Logger::getInstance()->critical('Rollback очистки checkpoint не выполнен', ['battleKey' => $key]);
                throw $error;
            }
        });
    }

    public static function finishCleanup(int $key, string $battleId): bool
    {
        return static::guarded(static function () use ($key, $battleId): bool {
            $state = self::state($key);
            if ($state === null) return true;
            if ($state['id'] !== $battleId || $state['phase'] !== 'applied' || empty($state['cleanupRequested'])) {
                throw new \LogicException('Недопустимая очистка checkpoint боя.');
            }
            $marker = static::$syncTable->get((string)$key);
            if (!empty($marker['need_delete']) || !empty($marker['need_update'])) return false;
            // RepositorySaver уже подтвердил SQL DELETE. Не создаём новый DELETE marker.
            if (static::$syncTable->exists((string)$key) && !static::$syncTable->del((string)$key)) {
                throw new \RuntimeException('Не освобождён sync marker завершённого боя.');
            }
            if (!static::$table->del((string)$key)) throw new \RuntimeException('Не освобождён слот завершённого боя.');
            return true;
        });
    }
}
