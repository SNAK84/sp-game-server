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
