<?php
declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\{Table, Atomic\Long};

/** Общие блоки только для переполнения inline-тела; владелец держит RepositoryGuard. */
final class SharedBlobStore
{
    private Table $blocks;
    private Long $sequence;
    public const BLOCK_BYTES = 1024;

    public function __construct(int $buckets)
    {
        $this->blocks = new Table($buckets, 1.0);
        $this->blocks->column('bytes', Table::TYPE_STRING, self::BLOCK_BYTES);
        if (!$this->blocks->create()) throw new \RuntimeException('Не создан пул тел сообщений.');
        $this->sequence = new Long(0);
    }

    public function put(string $value): int
    {
        $id = $this->sequence->add(1);
        $written = [];
        try {
            foreach (str_split($value, self::BLOCK_BYTES) as $part => $bytes) {
                $key = $id . ':' . $part;
                $written[] = $key;
                if (!$this->blocks->set($key, ['bytes' => $bytes]) || $this->blocks->get($key) !== ['bytes' => $bytes]) {
                    throw new \OverflowException('Недостаточно блоков для тела сообщения.');
                }
            }
            return $id;
        } catch (\Throwable $error) {
            foreach ($written as $key) if ($this->blocks->exists($key)) $this->blocks->del($key);
            throw $error;
        }
    }

    /** Публикация метаданных и замена тела под guard владельца.
     * Старые блоки переиспользуются; лишние освобождаются только после publish.
     * При ошибке publish/записи восстанавливаем старые байты без новых аллокаций.
     * capacity=0 означает переход в inline (value хранит сам владелец).
     */
    public function replace(string $value, int $capacity, int $oldId, int $oldCapacity, callable $publish): void
    {
        if ($capacity < 0 || $oldCapacity < 0 || ($capacity > 0 && $capacity < strlen($value))
            || (($oldId === 0) !== ($oldCapacity === 0))) throw new \InvalidArgumentException('Неверная ёмкость замены тела.');
        $oldParts = (int)ceil($oldCapacity / self::BLOCK_BYTES);
        $newParts = (int)ceil($capacity / self::BLOCK_BYTES);
        $id = $capacity === 0 ? 0 : ($oldId ?: $this->sequence->add(1));
        $before = [];
        if ($id === $oldId && $id !== 0) {
            for ($part = 0; $part < $oldParts; ++$part) {
                $before[$part] = $this->blocks->get($id . ':' . $part);
                if ($before[$part] === false) throw new \RuntimeException('Потерян резерв тела сообщения.');
            }
        }
        $created = [];
        try {
            for ($part = 0; $part < $newParts; ++$part) {
                $key = $id . ':' . $part;
                $row = ['bytes' => substr($value, $part * self::BLOCK_BYTES, self::BLOCK_BYTES)];
                if (!isset($before[$part])) $created[] = $key;
                if (!$this->blocks->set($key, $row) || $this->blocks->get($key) !== $row) {
                    throw new \OverflowException('Недостаточно блоков для тела сообщения.');
                }
            }
            $publish($id);
        } catch (\Throwable $error) {
            foreach ($before as $part => $row) {
                if (!$this->blocks->set($oldId . ':' . $part, $row)
                    || $this->blocks->get($oldId . ':' . $part) !== $row) {
                    throw new \RuntimeException('CRITICAL: не восстановлено тело сообщения.', 0, $error);
                }
            }
            foreach ($created as $key) if ($this->blocks->exists($key) && !$this->blocks->del($key)) {
                throw new \RuntimeException('CRITICAL: не освобождён новый блок тела.', 0, $error);
            }
            throw $error;
        }
        // Пока метаданные не подтверждены, старые блоки нельзя удалять:
        // иначе rollback при заполненном пуле потребовал бы новой памяти.
        for ($part = $newParts; $oldId !== 0 && $part < $oldParts; ++$part) {
            if (!$this->blocks->del($oldId . ':' . $part)) throw new \RuntimeException('Не освобождён резерв тела сообщения.');
        }
    }

    public function get(int $id, int $length): string
    {
        $value = '';
        for ($part = 0; $part < (int)ceil($length / self::BLOCK_BYTES); ++$part) {
            $row = $this->blocks->get($id . ':' . $part);
            if ($row === false) throw new \RuntimeException('Потерян блок тела сообщения.');
            $value .= $row['bytes'];
        }
        if (strlen($value) !== $length) throw new \RuntimeException('Повреждена длина тела сообщения.');
        return $value;
    }

    public function release(int $id, int $length): void
    {
        if ($id === 0) return;
        for ($part = 0; $part < (int)ceil($length / self::BLOCK_BYTES); ++$part) {
            if (!$this->blocks->del($id . ':' . $part)) throw new \RuntimeException('Не освобождён блок тела сообщения.');
        }
    }

    public function stats(): array { return ['blocks' => $this->blocks->count(), 'stats' => $this->blocks->stats()]; }
}
