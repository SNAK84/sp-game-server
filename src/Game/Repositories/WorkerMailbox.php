<?php
declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\{Lock, Table};

/** Общий для координатора и TaskWorkers буфер. Создаётся до Server::start(). */
final class WorkerMailbox
{
    public const CONFLICT = 0.2;
    private Table $table;
    private Lock $lock;

    public function __construct(private int $capacity, private int $maxBytes)
    {
        if ($capacity < 1 || $maxBytes < 1) throw new \InvalidArgumentException('Некорректная ёмкость mailbox.');
        $this->table = new Table($capacity, self::CONFLICT);
        $this->table->column('payload', Table::TYPE_STRING, $maxBytes);
        if (!$this->table->create()) throw new \RuntimeException('Не удалось создать mailbox процессов.');
        $this->lock = new Lock(SWOOLE_MUTEX);
    }

    /** Место для ответа резервирует только координатор, ДО публикации задания. */
    public function reserve(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) throw new \InvalidArgumentException('Некорректный token задания.');
        $this->acquire();
        try {
            if ($this->table->exists($token)) throw new \LogicException('Token задания уже занят.');
            if ($this->table->count() >= $this->capacity) return false;
            if (!@$this->table->set($token, ['payload' => ''])) return false;
            if ($this->table->get($token)['payload'] !== '') {
                $this->remove($token);
                throw new \RuntimeException('Не подтверждён резерв mailbox.');
            }
            return true;
        } finally { $this->lock->unlock(); }
    }

    /** Worker не создаёт строк: поздний результат закрытого задания игнорируется. */
    public function publish(string $token, string $payload): bool
    {
        if ($payload === '' || strlen($payload) > $this->maxBytes) {
            throw new \OverflowException('Результат не помещается в mailbox.');
        }
        $this->acquire();
        try {
            $before = $this->table->get($token);
            if ($before === false) return false;
            if ($before['payload'] !== '') {
                if ($before['payload'] !== $payload) throw new \LogicException('Результат задания уже опубликован.');
                return true;
            }
            if (!$this->table->set($token, ['payload' => $payload]) || $this->table->get($token)['payload'] !== $payload) {
                if (!$this->table->set($token, $before)) throw new \RuntimeException('Не восстановлен резерв mailbox.');
                throw new \RuntimeException('Не подтверждена запись результата mailbox.');
            }
            return true;
        } finally { $this->lock->unlock(); }
    }

    /** Отсутствующий ответ и ещё пустой резерв одинаково означают ожидание. */
    public function read(string $token): ?string
    {
        $this->acquire();
        try {
            $row = $this->table->get($token);
            return $row === false || $row['payload'] === '' ? null : $row['payload'];
        } finally { $this->lock->unlock(); }
    }

    public function release(string $token): void
    {
        $this->acquire();
        try { $this->remove($token); }
        finally { $this->lock->unlock(); }
    }

    /** Только ключи резервов, без копирования больших payload ответов. */
    public function tokens(): array
    {
        $this->acquire();
        try {
            $tokens = [];
            for ($this->table->rewind(); $this->table->valid(); $this->table->next()) {
                $tokens[(string)$this->table->key()] = true;
            }
            return $tokens;
        } finally { $this->lock->unlock(); }
    }

    /** Набор token => true. Удаление не совмещаем с обходом collision-цепочек. */
    public function retain(array $tokens): void
    {
        $this->acquire();
        try {
            $stale = [];
            foreach ($this->table as $token => $_) if (!isset($tokens[$token])) $stale[] = (string)$token;
            foreach ($stale as $token) $this->remove($token);
        } finally { $this->lock->unlock(); }
    }

    private function remove(string $token): void
    {
        if ($this->table->exists($token) && !$this->table->del($token)) {
            throw new \RuntimeException('Не удалось освободить mailbox задания.');
        }
    }

    private function acquire(): void
    {
        if (!$this->lock->lockwait(1.0)) throw new \RuntimeException('Не удалось заблокировать mailbox процессов.');
    }
}
