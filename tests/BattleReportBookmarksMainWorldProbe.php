<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{Accounts, BattleReports, RuntimeRecords, Users};
use Swoole\Table;

/** Read-through основной Table: никаких копий мира; отказ только одной записи. */
final class BookmarkReadTable extends Table
{
    public int $reads = 0;
    public int $visited = 0;
    public bool $failWrite = false;

    public function __construct(private readonly Table $source)
    {
        parent::__construct(64);
        $this->column('id', self::TYPE_INT, 8);
        if (!$this->create()) throw new RuntimeException('Не создан QA-счётчик чтений.');
    }

    public function get(string $key, ?string $field = null): array|string|int|float|false
    {
        ++$this->reads;
        return $field === null ? $this->source->get($key) : $this->source->get($key, $field);
    }
    public function set(string $key, array $value): bool
    {
        if ($this->failWrite) { $this->failWrite = false; return false; }
        return $this->source->set($key, $value);
    }
    public function del(string $key): bool { return $this->source->del($key); }
    public function exists(string $key): bool { return $this->source->exists($key); }
    public function count(): int { return $this->source->count(); }
    public function rewind(): void { $this->source->rewind(); }
    public function next(): void { $this->source->next(); }
    public function valid(): bool { return $this->source->valid(); }
    public function key(): mixed { return $this->source->key(); }
    public function current(): mixed { ++$this->visited; return $this->source->current(); }
    public function resetReads(): void { $this->reads = $this->visited = 0; }
}

/** Временный bootstrap только основного dev, после загрузки архива, до WS/saver.
 * Создаёт явные QA-доклады без боёв/потерь; cleanup удаляет только эти ID.
 */
return static function (string $path, bool $cleanup = false): void {
    foreach ([9 => 'qaown_260924', 10 => 'qafor_260924'] as $userId => $login) {
        $user = Users::findExistingById($userId);
        $account = $user === null ? null : Accounts::findExistingById((int)$user['account_id']);
        if (($account['login'] ?? '') !== $login) throw new LogicException('Не подтверждён QA-владелец.');
    }
    $reports = (new ReflectionProperty(BattleReports::class, 'reports'))->getValue();
    $saves = (new ReflectionProperty(BattleReports::class, 'saves'))->getValue();
    $index = new ReflectionProperty(BattleReports::class, 'savedByUser');
    $remove = static function (array $ids) use ($reports): void {
        foreach ($ids as $id) {
            $row = $reports->get($id);
            if ($row === null) continue;
            if ((json_decode($row['summary'], true, 512, JSON_THROW_ON_ERROR)['qa'] ?? '') !== 'B076') {
                throw new LogicException('Отказ удаления не-QA доклада.');
            }
            foreach ([9, 10] as $owner) BattleReports::setSaved($id, $owner, false);
            $reports->delete($id);
        }
    };
    if ($cleanup) {
        $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($fixture['marker'] ?? '') !== 'B076') throw new LogicException('Не тот QA-снимок.');
        $remove($fixture['ids']);
        foreach ($fixture['original'] as $owner => $expected) {
            if (BattleReports::savedFor((int)$owner) !== $expected) throw new RuntimeException('Не восстановлен исходный QA-архив.');
        }
        file_put_contents($path . '.cleaned', json_encode(['ok' => true, 'removed' => count($fixture['ids'])], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($path . '.cleaned', 0600);
        return;
    }
    if (file_exists($path)) return;

    $originalIndex = $index->getValue();
    $original = [9 => BattleReports::savedFor(9), 10 => BattleReports::savedFor(10)];
    $rowsProperty = new ReflectionProperty(RuntimeRecords::class, 'rows');
    $sourceReports = $rowsProperty->getValue($reports);
    $sourceSaves = $rowsProperty->getValue($saves);
    $reportCounter = new BookmarkReadTable($sourceReports);
    $saveCounter = new BookmarkReadTable($sourceSaves);
    $checks = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) throw new RuntimeException('B076: ' . $message);
        ++$checks;
    };
    // Зафиксированная прежняя реализация для сравнения порядка/страниц.
    $reference = static function (int $userId, int $page) use ($saves, $reports): array {
        $page = max(1, min(100000, $page));
        $rows = [];
        foreach ($saves->each() as $saved) if ((int)$saved['user_id'] === $userId) $rows[] = $saved;
        usort($rows, static fn(array $a, array $b): int => $b['saved_at'] <=> $a['saved_at'] ?: strcmp($b['report_id'], $a['report_id']));
        $offset = ($page - 1) * BattleReports::PAGE_SIZE;
        return ['page' => $page, 'hasMore' => count($rows) > $offset + BattleReports::PAGE_SIZE,
            'reports' => array_map(static function (array $saved) use ($reports): array {
                $row = $reports->get($saved['report_id']);
                return ['id' => $row['id'], 'createdAt' => (float)$row['created_at'],
                    'summary' => json_decode($row['summary'], true, 512, JSON_THROW_ON_ERROR)];
            }, array_slice($rows, $offset, BattleReports::PAGE_SIZE))];
    };
    $ids = [];
    $base = floor(microtime(true)) - 3600;
    $failure = null;
    $oldReads = $oldVisited = $newReportReads = 0;
    try {
        for ($i = 0; $i < 192; ++$i) {
            $id = bin2hex(random_bytes(16));
            $check($reports->get($id) === null, 'уникальный QA ID');
            $ids[] = $id;
            BattleReports::prepare(['version' => 1, 'battleId' => $id, 'finishedAt' => $base + $i / 1000,
                'participants' => [], 'groups' => [], 'rounds' => []], ['qa' => 'B076', 'index' => $i]);
            BattleReports::publish($id);
            BattleReports::setSaved($id, 10, true, $base + ($i * 37) % 7);
            if ($i < 70) BattleReports::setSaved($id, 9, true, $base + ($i * 17) % 7);
        }
        $rowsProperty->setValue($reports, $reportCounter);
        $rowsProperty->setValue($saves, $saveCounter);
        foreach ([9, 10, PHP_INT_MAX] as $owner) foreach ([0, 1, 2, 3, 4, 99999, 100001] as $page) {
            $expected = $reference($owner, $page);
            if ($owner === 9 && $page === 0) { $oldReads = $saveCounter->reads; $oldVisited = $saveCounter->visited; }
            $saveCounter->resetReads(); $reportCounter->resetReads();
            $before = $index->getValue();
            $actual = BattleReports::savedFor($owner, $page);
            $check($actual === $expected, 'страница равна прежнему oracle');
            $check($saveCounter->reads === 0 && $saveCounter->visited === 0, 'нет global scan закладок');
            $check($reportCounter->reads === count($actual['reports']) && $reportCounter->reads <= 30, 'чтение только докладов страницы');
            $check($index->getValue() === $before, 'чтение не меняет owner-индекс');
            if ($owner === 9 && $page === 0) $newReportReads = $reportCounter->reads;
        }
        try { BattleReports::savedFor(0); throw new LogicException('Принят user0.'); }
        catch (InvalidArgumentException) { ++$checks; }
        $before = $reference(9, 1);
        $beforeIndex = $index->getValue();
        BattleReports::setSaved($ids[0], 9, true, $base + 500);
        $check($index->getValue() === $beforeIndex, 'повтор save не меняет дату/порядок');
        foreach ([[true, $ids[150]], [false, $ids[0]]] as [$save, $id]) {
            $reportCounter->failWrite = true;
            try { BattleReports::setSaved($id, 9, $save, $base + 500); throw new LogicException('Не сработал отказ записи.'); }
            catch (OverflowException) { ++$checks; }
            $check($index->getValue() === $beforeIndex, 'отказ не меняет индекс');
            $check(BattleReports::savedFor(9) === $before && $reference(9, 1) === $before, 'rollback индекса и обеих RAM-записей');
            $check(BattleReports::get($id, 9)['savedByMe'] === !$save, 'rollback личной отметки');
        }
        BattleReports::setSaved($ids[150], 9, true, $base + 500);
        $check(BattleReports::savedFor(9) === $reference(9, 1), 'повтор после отказа сохраняет закладку');
        BattleReports::setSaved($ids[150], 9, false);
        $check(BattleReports::savedFor(9) === $before, 'unsave возвращает порядок');
        if (($originalIndex[9] ?? []) === []) {
            foreach (array_slice($ids, 0, 70) as $id) BattleReports::setSaved($id, 9, false);
            $check(!isset($index->getValue()[9]), 'пустая owner-группа освобождается');
            for ($i = 0; $i < 70; ++$i) BattleReports::setSaved($ids[$i], 9, true, $base + ($i * 17) % 7);
            $check(BattleReports::savedFor(9) === $before, 'повторное создание группы сохраняет порядок');
        }
        $check(BattleReports::savedFor(10) === $reference(10, 1), 'чужой архив не изменён');
        $pages = [];
        foreach ([9 => 3, 10 => 7] as $owner => $count) for ($page = 1; $page <= $count; ++$page) {
            $pages[$owner][$page] = BattleReports::savedFor($owner, $page);
        }
        $fixture = ['marker' => 'B076', 'checks' => $checks, 'ids' => $ids, 'pages' => $pages, 'original' => $original,
            'oldSaveReads' => $oldReads, 'oldSaveVisited' => $oldVisited, 'newSaveReads' => 0,
            'newReportReads' => $newReportReads];
        file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($path, 0600);
    } catch (Throwable $error) { $failure = $error; }
    finally {
        $rowsProperty->setValue($reports, $sourceReports);
        $rowsProperty->setValue($saves, $sourceSaves);
        if ($failure !== null) $remove($ids);
    }
    if ($failure !== null) throw $failure;
};
