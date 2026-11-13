<?php
declare(strict_types=1);

use Swoole\Table;
use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Repositories\{Accounts, Users, Fleets, FleetCommands, BaseRepository};

/** Только временный bootstrap-вызов основного мира, до сети/saver. Не отдельный мир.
 * Созданные QA-команды всегда убираются; остальные native-строки не меняются.
 */
final class IndexMainFaultTable extends Table
{
    public ?array $failure = null;
    public int $reads = 0;
    public int $writes = 0;
    public function get(string $key, ?string $field = null): array|string|int|float|false
    {
        ++$this->reads;
        return $field === null ? parent::get($key) : parent::get($key, $field);
    }
    public function set(string $key, array $value): bool
    {
        ++$this->writes;
        $result = parent::set($key, $value);
        if ($this->failure === ['set', $key]) { $this->failure = null; return false; }
        return $result;
    }
    public function del(string $key): bool
    {
        ++$this->writes;
        $result = parent::del($key);
        if ($this->failure === ['del', $key]) { $this->failure = null; return false; }
        return $result;
    }
}

return static function (int $fleetId, string $output): void {
    if (file_exists($output)) return;
    $fleet = Fleets::findExistingById($fleetId);
    $owner = $fleet === null ? null : Users::findExistingById((int)$fleet['owner_id']);
    $account = $owner === null ? null : Accounts::findExistingById((int)$owner['account_id']);
    if (($account['login'] ?? '') !== 'qaown_260924' || (int)$fleet['status'] !== 0
        || count(FleetCommands::view($fleetId)) !== 0) throw new LogicException('Нужен свободный QA-флот.');
    $checks = 0; $samples = []; $ids = [];
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new RuntimeException('B071: ' . $label);
        ++$checks;
    };
    $property = static fn(string $name): ReflectionProperty => new ReflectionProperty(FleetCommands::class, $name);
    $indexesProperty = $property('indexTables');
    $syncProperty = $property('syncTable');
    $positionsProperty = new ReflectionProperty(BaseRepository::class, 'indexPositions');
    $sequencesProperty = new ReflectionProperty(BaseRepository::class, 'syncSequences');
    $main = $property('table')->getValue();
    $initial = FleetCommands::getAll();
    $initialIndexes = $indexesProperty->getValue();
    $initialPositions = $positionsProperty->getValue()[FleetCommands::class]['table'];
    $initialSync = $syncProperty->getValue();

    $digest = static function (array $tables): array {
        $result = [];
        foreach ($tables as $name => $table) {
            $rows = [];
            foreach ($table as $key => $row) if ($key !== 'meta') $rows[(string)$key] = $row;
            ksort($rows, SORT_STRING);
            $result[$name] = hash('sha256', serialize($rows));
        }
        return $result;
    };
    $initialDigest = $digest($initialIndexes + ['positions' => $initialPositions]);
    $add = static function (Command $type) use ($fleetId, &$ids): void {
        $row = FleetCommands::addCommand($fleetId, $type, ['cancelled' => true, 'qaIndexProbe' => true]);
        $ids[] = (int)$row['id'];
        FleetCommands::update(['id' => $row['id'], 'executed' => 1]);
    };
    $base = md5((string)Command::OBSERVE->value);
    $target = md5((string)Command::RECYCLE->value);
    $count = static fn(string $key): int => (int)(($indexesProperty->getValue()['command_type']->get($key) ?: [])['id'] ?? 0);
    $clone = static function (Table $old, array $columns, int $buckets): IndexMainFaultTable {
        $new = new IndexMainFaultTable($buckets, 1.0);
        foreach ($columns as $name => $definition) $new->column($name, ...$definition);
        if (!$new->create()) throw new RuntimeException('Нет временной native-обёртки.');
        foreach ($old as $key => $row) if (!$new->set((string)$key, $row)) throw new RuntimeException('Не скопирована native-строка.');
        $new->reads = $new->writes = 0;
        return $new;
    };
    try {
        foreach ([129, 4097] as $size) {
            while ($count($base) < $size) $add(Command::OBSERVE);
            // При смене типа добавление создаст новый хвост; удаление старого
            // хвоста тоже требует native del, так как 129/4097 == 1 mod16.
            do { $add(Command::RECYCLE); } while ($count($target) % 16 !== 0);
            $keyId = $ids[0];
            $rowBefore = FleetCommands::findExistingById($keyId);
            $indexes = $indexesProperty->getValue();
            $positionStores = $positionsProperty->getValue();
            $sync = $syncProperty->getValue();
            $sequences = $sequencesProperty->getValue();
            $layout = FleetCommands::runtimeLayout();
            $index = $clone($indexes['command_type'], ['id' => [Table::TYPE_INT, 8],
                'ids' => [Table::TYPE_STRING, $layout['indexIdsBytes']]], $layout['indexBuckets']);
            $positions = $clone($positionStores[FleetCommands::class]['table'],
                array_fill_keys($layout['positionColumns'], [Table::TYPE_STRING, 24]), $layout['positionBuckets']);
            $marker = $clone($sync, ['need_update' => [Table::TYPE_INT, 8], 'need_delete' => [Table::TYPE_INT, 8],
                'last_id' => [Table::TYPE_INT, 8], 'dirty_since' => [Table::TYPE_FLOAT]], $layout['syncBuckets']);
            $indexesProperty->setValue(null, array_replace($indexes, ['command_type' => $index]));
            $stores = $positionStores;
            $stores[FleetCommands::class]['table'] = $positions;
            $positionsProperty->setValue(null, $stores);
            $syncProperty->setValue(null, $marker);
            try {
                $tables = $indexesProperty->getValue() + ['positions' => $positions, 'sync' => $marker];
                $before = $digest($tables);
                $attempt = static function (callable $operation, ?IndexMainFaultTable $fault, ?array $failure, string $label)
                    use ($check, $keyId, $rowBefore, $digest, $tables, $before): array {
                    if ($fault !== null) $fault->failure = $failure;
                    foreach ($tables as $table) if ($table instanceof IndexMainFaultTable) $table->reads = $table->writes = 0;
                    memory_reset_peak_usage(); $memory = memory_get_usage(); $time = hrtime(true);
                    $failed = false;
                    try { $operation(); } catch (RuntimeException) { $failed = true; }
                    $elapsed = (hrtime(true) - $time) / 1000000;
                    $peak = memory_get_peak_usage() - $memory;
                    $reads = $writes = 0;
                    foreach ($tables as $table) if ($table instanceof IndexMainFaultTable) { $reads += $table->reads; $writes += $table->writes; }
                    $check($failed && ($fault === null || $fault->failure === null), 'ожидаемый native отказ ' . $label);
                    $check(FleetCommands::findExistingById($keyId) === $rowBefore, 'main восстановлена ' . $label);
                    $check($digest($tables) === $before, 'все индексы/позиции/marker восстановлены ' . $label);
                    return ['reads' => $reads, 'writes' => $writes, 'peakBytes' => $peak, 'ms' => round($elapsed, 3)];
                };
                $update = static fn() => FleetCommands::update(['id' => $keyId, 'command_type' => Command::RECYCLE->value]);
                $samples[$size] = $attempt($update, $marker, ['set', (string)$keyId], 'marker');
                if ($size === 4097) {
                    $attempt($update, $index, ['set', $base], 'head set');
                    $attempt($update, $index, ['del', $base . ':' . intdiv($size - 1, 16)], 'tail del');
                    $attempt($update, $index, ['set', $target . ':' . intdiv($count($target), 16)], 'new tail set');
                    $last = array_key_last(iterator_to_array(FleetCommands::iterateIndexIds('command_type', [(string)Command::OBSERVE->value])));
                    $members = iterator_to_array(FleetCommands::iterateIndexIds('command_type', [(string)Command::OBSERVE->value]));
                    $attempt($update, $positions, ['set', (string)$members[$last]], 'swapped position set');
                    $attempt(static fn() => FleetCommands::delete(['id' => $keyId]), $positions, ['del', (string)$keyId], 'removed position del');
                    $attempt(static fn() => FleetCommands::update(['id' => $keyId, 'params' => str_repeat('x', 2049)]), null, null, 'native main truncation');
                }
            } finally {
                $indexesProperty->setValue(null, $indexes);
                $positionsProperty->setValue(null, $positionStores);
                $syncProperty->setValue(null, $sync);
                $sequencesProperty->setValue(null, $sequences);
                $check(FleetCommands::findExistingById($keyId) === $rowBefore, 'не оставлена main мутация');
            }
        }
        $check($samples[4097]['reads'] <= $samples[129]['reads'] + 4, 'чтения не растут с группой');
        $check($samples[4097]['writes'] <= $samples[129]['writes'] + 4, 'записи не растут с группой');
        $check($samples[4097]['peakBytes'] < 65536, 'undo не копирует большую группу');
        $saved = FleetCommands::findExistingById($ids[5]);
        FleetCommands::delete(['id' => $saved['id']]);
        $check(FleetCommands::findExistingById($saved['id']) === null, 'успешное удаление main');
        $check($initialPositions->get((string)$saved['id']) === false, 'удаление всех позиций ID');
        FleetCommands::discardUncommittedRow($saved);
        FleetCommands::restoreDeletedRow($saved);
        $check(FleetCommands::findExistingById($saved['id']) === $saved, 'восстановление удалённой main');
        $check((int)$initialSync->get((string)$saved['id'])['need_delete'] === 0, 'DELETE marker снят');
        $restored = $digest($initialIndexes + ['positions' => $initialPositions]);
        FleetCommands::restoreDeletedRow($saved);
        $check($digest($initialIndexes + ['positions' => $initialPositions]) === $restored, 'идемпотентный restore не дублирует ID');
        $expected = FleetCommands::getFleetCommandChain($fleetId);
        try {
            \SPGame\Game\Services\FleetControlService::commit([\SPGame\Game\Services\FleetsData::getInstance()->get($fleetId)],
                static function () use ($fleetId): void {
                    FleetCommands::clearFleetQueue($fleetId);
                    throw new LogicException('B071_EXPECTED_DELTA_UNDO');
                });
        } catch (LogicException $error) { $check($error->getMessage() === 'B071_EXPECTED_DELTA_UNDO', 'ожидаемый bulk rollback'); }
        $check(FleetCommands::getFleetCommandChain($fleetId) === $expected, 'B070 вернул удалённую цепочку');
        $check((new ReflectionProperty(FleetCommands::class, 'changeFrames'))->getValue() === [], 'delta undo закрыт');
        $result = ['ok' => true, 'checks' => $checks, 'samples' => $samples, 'createdQaCommands' => count($ids)];
    } catch (Throwable $error) { $result = ['ok' => false, 'checks' => $checks, 'error' => $error->getMessage()]; }
    finally {
        foreach (array_reverse($ids) as $id) {
            $row = FleetCommands::findExistingById($id);
            if ($row !== null) FleetCommands::discardUncommittedRow($row);
            // Эти ID никогда не были в SQL и не выдавались сетевому клиенту.
            if ($initialSync->exists((string)$id) && !$initialSync->del((string)$id)) throw new RuntimeException('Не очищен QA marker.');
        }
    }
    try {
        $check(FleetCommands::getAll() === $initial, 'чужие команды и QA очередь не изменены');
        $check($digest($initialIndexes + ['positions' => $initialPositions]) === $initialDigest, 'исходные native индексы и позиции восстановлены');
    } catch (Throwable $error) { $result['ok'] = false; $result['error'] = $error->getMessage(); }
    $result['checks'] = $checks;
    $handle = fopen($output, 'x');
    if ($handle === false) throw new RuntimeException('Нет файла результата B071.');
    chmod($output, 0600);
    try { fwrite($handle, json_encode($result, JSON_THROW_ON_ERROR)); } finally { fclose($handle); }
    if (!$result['ok']) throw new RuntimeException('Основной B071 probe не пройден.');
};
