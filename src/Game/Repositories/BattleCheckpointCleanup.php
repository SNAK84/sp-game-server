<?php
declare(strict_types=1);

namespace SPGame\Game\Repositories;

use SPGame\Core\Logger;
use SPGame\Game\Services\RepositorySaver;

/** Общий retained DELETE двух боевых checkpoint. Не история BattleReports. */
trait BattleCheckpointCleanup
{
    /** Список ключей без копирования больших JSON неоконченных боёв. */
    public static function appliedKeys(): array
    {
        $keys = [];
        if (!isset(static::$table)) return $keys;
        for (static::$table->rewind(); static::$table->valid(); static::$table->next()) {
            $key = (string)static::$table->key();
            if (static::$table->get($key, 'phase') === 'applied') $keys[] = (int)$key;
        }
        return $keys;
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
                if ($retained === null) continue;
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

    /** Только после COMMIT saver. Новое поколение/новый бой не подтверждаем. */
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

    /** После общего COMMIT applied и зависимостей; слот занят до DELETE ACK. */
    public static function requestCleanup(int $key, string $battleId): void
    {
        static::guarded(static function () use ($key, $battleId): void {
            $before = static::$table->get((string)$key);
            $state = static::state($key);
            $marker = static::$syncTable->get((string)$key);
            if ($state === null || $state['id'] !== $battleId || $state['phase'] !== 'applied'
                || !empty($marker['need_update']) || !empty($marker['need_delete'])) throw new \LogicException('Checkpoint ещё не сохранён или уже очищается.');
            // Полный JSON может занимать весь MAX_STATE_BYTES. В SQL он остаётся
            // полным до DELETE COMMIT; в RAM достаточно короткой identity.
            $encoded = json_encode(['id' => $state['id'], 'phase' => 'applied', 'cleanupRequested' => true],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($encoded) > static::MAX_STATE_BYTES) throw new \OverflowException('Нет места для marker очистки боя.');
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
            $state = static::state($key);
            if ($state === null) return true;
            if ($state['id'] !== $battleId || $state['phase'] !== 'applied' || empty($state['cleanupRequested'])) {
                throw new \LogicException('Недопустимая очистка checkpoint боя.');
            }
            $marker = static::$syncTable->get((string)$key);
            if (!empty($marker['need_delete']) || !empty($marker['need_update'])) return false;
            if (static::$syncTable->exists((string)$key) && !static::$syncTable->del((string)$key)) {
                throw new \RuntimeException('Не освобождён sync marker завершённого боя.');
            }
            if (!static::$table->del((string)$key)) throw new \RuntimeException('Не освобождён слот завершённого боя.');
            return true;
        });
    }
}
