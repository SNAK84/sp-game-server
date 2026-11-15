<?php
declare(strict_types=1);

namespace SPGame\Game\Repositories;

use Swoole\{Table, Atomic\Long};
use SPGame\Core\RuntimeMetrics;

/** Ограниченные shared-записи для журналов с составными/строковыми SQL-ключами. */
final class RuntimeRecords
{
    /** Одна порция в секунду; это цель полного обхода, не гарантия при time hits/lag. */
    public const SWEEP_TARGET_CALLS = 300;
    public const SWEEP_BUDGET_MS = 2.0;
    public const SPECS = [
        // development rows/blocks, target rows/blocks. Рост только target:
        // 2,88млн суточных квитанций,272000 блоков докладов и свободный резерв.
        'ProtocolCommands' => [16384, 16384, 4194304, 4194304],
        'MiningCheckpoints' => [1024, 32768, 8192, 131072],
        'BattleReports' => [4096, 32768, 65536, 524288],
        'BattleReportSaves' => [4096, 1024, 262144, 32768],
    ];
    private Table $rows;
    private Table $keys;
    private Table $dirty;
    private SharedBlobStore $bodies;
    private Long $sequence;
    private Long $allocation;
    private int $sweep = 0;
    private int $sweepPassStartedNs = 0;
    private float $sweepLastPassSeconds = 0.0;
    private int $capacity;

    public static function layout(string $name): array
    {
        $spec = self::SPECS[$name] ?? throw new \LogicException('Неизвестное shared-хранилище.');
        $target = RuntimeCapacity::profile() === 'target';
        return ['rows' => $spec[$target ? 2 : 0], 'blocks' => $spec[$target ? 3 : 1]];
    }

    public static function columns(): array
    {
        return ['key' => [Table::TYPE_STRING, 192], 'body_id' => [Table::TYPE_INT, 8],
            'body_capacity' => [Table::TYPE_INT, 8],
            'body_size' => [Table::TYPE_INT, 8], 'version' => [Table::TYPE_INT, 8],
            'deleted' => [Table::TYPE_INT, 8], 'dirty_since' => [Table::TYPE_FLOAT, 8],
            'inline' => [Table::TYPE_STRING, 256], 'expires_at' => [Table::TYPE_FLOAT, 8]];
    }

    public static function sweepBudget(string $name, int $minimum): int
    {
        if ($minimum <= 0) throw new \InvalidArgumentException('Некорректная порция очистки.');
        $capacity = self::layout($name)['rows'];
        return min($capacity, max($minimum, (int)ceil($capacity / self::SWEEP_TARGET_CALLS)));
    }

    public function __construct(private readonly string $name, private readonly int $maxBytes)
    {
        $layout = self::layout($name);
        $this->capacity = $layout['rows'];
        $this->rows = new Table($this->capacity, 1.0);
        foreach (self::columns() as $column => $definition) $this->rows->column($column, $definition[0], $definition[1]);
        $this->keys = new Table($this->capacity, 1.0);
        $this->keys->column('slot', Table::TYPE_INT, 8);
        $this->dirty = new Table($this->capacity, 1.0);
        $this->dirty->column('version', Table::TYPE_INT, 8);
        if (!$this->rows->create() || !$this->keys->create() || !$this->dirty->create()) throw new \RuntimeException('Не создано хранилище ' . $name);
        $this->bodies = new SharedBlobStore($layout['blocks']);
        $this->sequence = new Long(0);
        $this->allocation = new Long(0);
    }

    private function guard(callable $operation): mixed { return RepositoryGuard::run($this->name . ':runtime', $operation); }
    private function hash(string $key): string { return substr(hash('sha256', $key), 0, 32); }

    private function locate(string $key): ?array
    {
        $index = $this->keys->get($this->hash($key));
        if ($index === false) return null;
        $row = $this->rows->get((string)$index['slot']);
        if ($row === false || $row['key'] !== $key) throw new \RuntimeException('Повреждён ключ shared-хранилища ' . $this->name);
        return [$index['slot'], $row];
    }

    private function decode(array $row): array
    {
        return $this->decodePacked($this->packed($row));
    }

    private function packed(array $row): string
    {
        $body = $row['body_id'] === 0 ? $row['inline'] : $this->bodies->get($row['body_id'], $row['body_size']);
        if ($body === '' || strlen($body) !== $row['body_size']) throw new \RuntimeException('Повреждена shared-запись ' . $this->name);
        return $body;
    }

    private function decodePacked(string $body): array
    {
        $json = $body[0] === "\x01" ? gzinflate(substr($body, 1), $this->maxBytes) : substr($body, 1);
        if (!is_string($json)) throw new \RuntimeException('Не распакована shared-запись ' . $this->name);
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new \RuntimeException('Неверная shared-запись ' . $this->name);
        return $value;
    }

    /** Распаковка ровно одной SQL-записи уже вне world lock, не всей истории. */
    public function snapshotValue(array|string $entry): array
    {
        $entry = \SPGame\Game\Services\RepositorySaver::unpackSnapshotEntry($entry);
        $value = $this->decodePacked($entry['packed']);
        \SPGame\Game\Services\RepositorySaver::assertSnapshotBudget();
        return $value;
    }

    public function get(string $key): ?array
    {
        return $this->guard(function () use ($key): ?array {
            $found = $this->locate($key);
            return $found === null || $found[1]['deleted'] ? null : $this->decode($found[1]);
        });
    }

    public function put(string $key, array $value, bool $dirty = true): void
    {
        if ($key === '' || strlen($key) > 192) throw new \InvalidArgumentException('Слишком длинный shared-ключ.');
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > $this->maxBytes) throw new \OverflowException('Слишком большая запись ' . $this->name);
        $compressed = gzdeflate($json, 1);
        $payload = is_string($compressed) && strlen($compressed) < strlen($json) ? "\x01" . $compressed : "\x00" . $json;
        $this->guard(function () use ($key, $value, $payload, $dirty): void {
            $found = $this->locate($key);
            if ($found !== null && !$found[1]['deleted'] && $this->decode($found[1]) === $value) return;
            if ($found === null && $this->rows->count() >= $this->capacity) throw new \OverflowException('Исчерпан бюджет ' . $this->name);
            $slot = $found[0] ?? null;
            if ($slot === null) {
                for ($attempt = 0; $attempt < $this->capacity; ++$attempt) {
                    $candidate = ($this->allocation->add(1) - 1) % $this->capacity;
                    if (!$this->rows->exists((string)$candidate)) { $slot = $candidate; break; }
                }
            }
            if ($slot === null) throw new \OverflowException('Нет свободной shared-ячейки.');
            // Резервируем худший размер terminal-квитанции до PlayerQueue.
            // Завершение переиспользует уже занятые блоки, даже если пул заполнен.
            $reserve = $this->name === 'ProtocolCommands' && $value['state'] === 'accepted' ? $this->maxBytes + 1 : 0;
            $inline = $reserve === 0 && strlen($payload) <= 256;
            $capacity = $inline ? 0 : max(strlen($payload), $reserve);
            $row = ['key' => $key, 'body_id' => 0, 'body_capacity' => $capacity,
                'body_size' => strlen($payload), 'version' => $this->sequence->add(1), 'deleted' => 0,
                'dirty_since' => $dirty ? (($found[1]['dirty_since'] ?? 0) ?: microtime(true)) : 0.0,
                'inline' => $inline ? $payload : '',
                // Внутренняя дата sweep не меняет публичный TTL квитанции:
                // accepted нуждается в сверке с очередью, а не удалении.
                'expires_at' => $this->name === 'ProtocolCommands' ? ($value['state'] === 'accepted'
                    ? (float)$value['created_at'] + ProtocolCommands::MAX_AGE : (float)$value['expires_at'])
                    : ($this->name === 'BattleReports' && (int)$value['ready'] === 1 && (int)$value['saved_count'] === 0 ? (float)$value['expires_at'] : 0.0)];
            $oldMarker = $this->dirty->get((string)$slot);
            $this->bodies->replace($payload, $capacity, $found[1]['body_id'] ?? 0, $found[1]['body_capacity'] ?? 0,
                function (int $bodyId) use ($row, $slot, $key, $dirty, $found, $oldMarker): void {
                    $row['body_id'] = $bodyId;
                    try {
                        if (!$this->rows->set((string)$slot, $row) || $this->rows->get((string)$slot) !== $row) throw new \OverflowException('Не записана shared-ячейка.');
                        if (!$this->keys->set($this->hash($key), ['slot' => $slot]) || $this->keys->get($this->hash($key)) !== ['slot' => $slot]) throw new \OverflowException('Не записан shared-индекс.');
                        if ($dirty && (!$this->dirty->set((string)$slot, ['version' => $row['version']]) || $this->dirty->get((string)$slot) !== ['version' => $row['version']])) throw new \OverflowException('Не записан shared dirty-marker.');
                    } catch (\Throwable $error) {
                        if ($found === null) { $this->rows->del((string)$slot); $this->keys->del($this->hash($key)); }
                        elseif (!$this->rows->set((string)$slot, $found[1])) throw new \RuntimeException('CRITICAL: не восстановлена shared-запись.', 0, $error);
                        if ($oldMarker === false) $this->dirty->del((string)$slot); else $this->dirty->set((string)$slot, $oldMarker);
                        throw $error;
                    }
                });
        });
    }

    public function delete(string $key): void
    {
        $this->guard(function () use ($key): void {
            $found = $this->locate($key);
            if ($found === null || $found[1]['deleted']) return;
            $slot = (string)$found[0]; $before = $found[1]; $marker = $this->dirty->get($slot);
            $version = $this->sequence->add(1);
            try {
                if (!$this->rows->set($slot, ['deleted' => 1, 'version' => $version, 'dirty_since' => $before['dirty_since'] ?: microtime(true)])
                    || !$this->dirty->set($slot, ['version' => $version]) || $this->dirty->get($slot) !== ['version' => $version]) throw new \RuntimeException('Не помечено shared-удаление.');
            } catch (\Throwable $error) {
                if (!$this->rows->set($slot, $before)) throw new \RuntimeException('CRITICAL: не восстановлено shared-удаление.', 0, $error);
                if ($marker === false) $this->dirty->del($slot); else $this->dirty->set($slot, $marker);
                throw $error;
            }
        });
    }

    public function each(): \Generator
    {
        foreach ($this->rows as $raw) {
            $value = $this->get($raw['key']);
            if ($value !== null) yield $raw['key'] => $value;
        }
    }

    /** Ограничены число слотов и время между записями; начатый callback не прерываем. */
    public function sweep(callable $expired, int $budget = 512, ?float $now = null, float $budgetMs = self::SWEEP_BUDGET_MS): array
    {
        if ($budget <= 0 || !is_finite($budgetMs) || $budgetMs <= 0) throw new \InvalidArgumentException('Некорректный бюджет очистки.');
        $now ??= microtime(true);
        return $this->guard(function () use ($expired, $budget, $now, $budgetMs): array {
            $started = hrtime(true);
            if ($this->sweepPassStartedNs === 0) $this->sweepPassStartedNs = $started;
            $result = ['scanned' => 0, 'deleted' => 0, 'timeBudgetHit' => false, 'passes' => 0];
            try {
                for ($i = 0; $i < min($budget, $this->capacity); ++$i) {
                    if ($i > 0 && (hrtime(true) - $started) / 1000000 >= $budgetMs) {
                        $result['timeBudgetHit'] = true;
                        break;
                    }
                    // Продвигаемся и при исключении: один повреждённый слот не
                    // удерживает все последующие порции на той же записи.
                    $slot = $this->sweep++ % $this->capacity;
                    ++$result['scanned'];
                    try {
                        $row = $this->rows->get((string)$slot);
                        if ($row !== false && !$row['deleted'] && $row['expires_at'] > 0
                            && $row['expires_at'] <= $now && $expired($this->decode($row))) {
                            $this->delete($row['key']);
                            ++$result['deleted'];
                        }
                    } finally {
                        if ($this->sweep % $this->capacity === 0) {
                            $passFinished = hrtime(true);
                            $this->sweepLastPassSeconds = ($passFinished - $this->sweepPassStartedNs) / 1000000000;
                            $this->sweepPassStartedNs = $passFinished;
                            ++$result['passes'];
                        }
                    }
                }
            } finally {
                $result['elapsedMs'] = (hrtime(true) - $started) / 1000000;
                $result['lastPassSeconds'] = $this->sweepLastPassSeconds;
                $metric = match ($this->name) {
                    'ProtocolCommands' => 'storage.receiptsCleanup',
                    'BattleReports' => 'storage.reportsCleanup',
                    default => null,
                };
                if ($metric !== null) {
                    RuntimeMetrics::observe($metric, $result['elapsedMs']);
                    RuntimeMetrics::gauge($metric . 'Scanned', (float)$result['scanned']);
                    RuntimeMetrics::gauge($metric . 'Deleted', (float)$result['deleted']);
                    RuntimeMetrics::gauge($metric . 'BudgetSteps', (float)$budget);
                    RuntimeMetrics::gauge($metric . 'PassSeconds', $this->sweepLastPassSeconds);
                    if ($result['timeBudgetHit']) RuntimeMetrics::increment($metric . 'BudgetHits');
                }
            }
            return $result;
        });
    }

    public function snapshot(bool $compact = false): array
    {
        return $this->guard(function () use ($compact): array {
            $snapshot = [];
            foreach ($this->dirty as $slot => $marker) {
                $row = $this->rows->get((string)$slot);
                if ($row === false || $row['version'] !== $marker['version'] || $row['dirty_since'] <= 0) throw new \RuntimeException('Повреждён shared dirty-индекс.');
                $entry = ['key' => $row['key'], 'version' => $row['version'],
                    'deleted' => (bool)$row['deleted'], 'packed' => $this->packed($row)];
                $snapshot[$slot] = $compact ? \SPGame\Game\Services\RepositorySaver::packSnapshotEntry($entry) : $entry;
                \SPGame\Game\Services\RepositorySaver::assertSnapshotBudget();
            }
            return $snapshot;
        });
    }

    public function acknowledge(array $snapshot): void
    {
        $this->guard(function () use ($snapshot): void {
            foreach ($snapshot as $slot => $entry) {
                $saved = \SPGame\Game\Services\RepositorySaver::unpackSnapshotEntry($entry);
                $row = $this->rows->get((string)$slot);
                if ($row === false || $row['version'] !== $saved['version'] || $row['key'] !== $saved['key']) continue;
                if ($row['deleted']) {
                    if (!$this->keys->del($this->hash($row['key'])) || !$this->rows->del((string)$slot)) throw new \RuntimeException('Не подтверждено shared-удаление.');
                    $this->bodies->release($row['body_id'], $row['body_capacity']);
                } elseif (!$this->rows->set((string)$slot, ['dirty_since' => 0.0])) throw new \RuntimeException('Не подтверждена shared-запись.');
                if (!$this->dirty->del((string)$slot)) throw new \RuntimeException('Не подтверждён shared dirty-marker.');
            }
        });
    }

    public function stats(float $now): array
    {
        $pending = 0; $age = 0.0;
        foreach ($this->dirty as $slot => $marker) {
            $row = $this->rows->get((string)$slot);
            if ($row !== false) { ++$pending; $age = max($age, $now - $row['dirty_since']); }
        }
        return ['pending' => $pending, 'oldestDirtySeconds' => $age];
    }
}
