<?php

declare(strict_types=1);

// Узкая диагностика B006 на SELECT-снимке dev-БД. Никаких syncToDatabase()/main.php.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{FleetCommands, Fleets, FleetsShips, Users, Vars};
use SPGame\Game\Services\FleetControlService;
use Swoole\Table;

if (($argv[1] ?? '') !== '--dev-readonly') throw new LogicException('Нужен --dev-readonly.');
Environment::init(__DIR__ . '/../.env');
$environment = new ReflectionProperty(Environment::class, 'env');
$environment->setValue(null, array_replace($environment->getValue(), [
    'LOG_FILE' => '/tmp/sp-game-b006-probe.log', 'LOG_ECHO' => 'false',
]));
$db = Database::getInstance();
$db->query('SET TRANSACTION READ ONLY');
$db->beginTransaction();

class RepositoryProbeTable extends Table
{
    public ?string $failKey = null;
    public int $failSet = 0;
    public bool $afterWrite = false;
    public bool $throwAfter = false;
    public bool $failDelete = false;
    public function set(string $key, array $value): bool
    {
        if ($this->failSet > 0 && ($this->failKey === null || $this->failKey === $key) && --$this->failSet === 0) {
            if ($this->afterWrite) parent::set($key, $value);
            if ($this->throwAfter) throw new RuntimeException('B006 probe after write');
            return false;
        }
        return parent::set($key, $value);
    }
    public function del(string $key): bool
    {
        if ($this->failDelete && $this->failKey === $key) { $this->failDelete = false; return false; }
        return parent::del($key);
    }
}

function probeCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function probeRows(Table $table): array
{
    $rows = [];
    foreach ($table as $key => $row) $rows[(string)$key] = $row;
    ksort($rows);
    return $rows;
}
function probeState(string $class): array
{
    $result = [];
    foreach (['table', 'syncTable'] as $property) $result[$property] = probeRows((new ReflectionProperty($class, $property))->getValue());
    foreach ((new ReflectionProperty($class, 'indexTables'))->getValue() as $name => $table) $result['indexes'][$name] = probeRows($table);
    return $result;
}
function probeInstall(string $class, string $property, ?string $index = null): RepositoryProbeTable
{
    $prop = new ReflectionProperty($class, $property);
    $value = $prop->getValue();
    $old = $index === null ? $value : $value[$index];
    $table = new RepositoryProbeTable(32768);
    if ($property === 'table') {
        foreach ((new ReflectionProperty($class, 'tableSchema'))->getValue()['columns'] as $name => $column) {
            $table->column($name, $column['swoole'][0], $column['swoole'][1] ?? 0);
        }
    } elseif ($property === 'syncTable') {
        foreach (['need_update', 'need_delete', 'last_id'] as $name) $table->column($name, Table::TYPE_INT, 8);
    } else {
        $table->column('id', Table::TYPE_INT, 8);
        $unique = (new ReflectionProperty($class, 'indexes'))->getValue()[$index]['Unique'];
        if (!$unique) $table->column('ids', Table::TYPE_STRING, 2048);
    }
    $table->create();
    foreach ($old as $key => $row) $table->set((string)$key, $row);
    if ($index === null) $prop->setValue(null, $table);
    else { $value[$index] = $table; $prop->setValue(null, $value); }
    return $table;
}
function probeFailure(string $label, string $class, callable $operation): void
{
    $before = probeState($class);
    $error = null;
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    probeCheck($error !== null, $label . ': отказ не обнаружен');
    $after = probeState($class);
    if ($after !== $before) echo 'DIFF ', json_encode(['mainUnchanged' => $after['table'] === $before['table'],
        'indexesUnchanged' => $after['indexes'] === $before['indexes'],
        'markersUnchanged' => $after['syncTable'] === $before['syncTable']], JSON_THROW_ON_ERROR), PHP_EOL;
    probeCheck($after === $before, $label . ': изменились row/index/marker');
    echo 'OK ', $label, PHP_EOL;
}

try {
    probeCheck(($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login'] ?? '') === 'qafor_260924', 'Другой dev/QA.');
    Vars::init();
    foreach ([Fleets::class, FleetsShips::class, FleetCommands::class, Users::class] as $class) $class::init();
    $row = FleetCommands::getCommand(190);
    probeCheck($row !== null && (int)$row['fleet_id'] === 56 && (int)Fleets::getById(56)['owner_id'] === 10, 'Другая QA-команда.');
    $deadline = ceil(microtime(true)) + 7200;
    $change = ['execute_time' => $deadline, 'command_type' => 10];
    $main = probeInstall(FleetCommands::class, 'table');
    $index = probeInstall(FleetCommands::class, 'indexTables', 'execute_time');
    $sync = probeInstall(FleetCommands::class, 'syncTable');
    foreach (['before', 'after-false', 'after-throw'] as $kind) {
        $main->failKey = '190'; $main->failSet = 1;
        $main->afterWrite = $kind !== 'before'; $main->throwAfter = $kind === 'after-throw';
        probeFailure('main/' . $kind, FleetCommands::class, static fn() => FleetControlService::patchCommand(190, $change));
    }
    $index->failKey = md5((string)$deadline); $index->failSet = 1;
    probeFailure('non-unique/new bucket', FleetCommands::class, static fn() => FleetControlService::patchCommand(190, $change));
    $index->failKey = md5((string)$row['execute_time']); $index->failDelete = true;
    // Если прежняя группа не пуста, её перезапись проверяется вместо удаления.
    $index->failSet = 1;
    probeFailure('non-unique/old bucket', FleetCommands::class, static fn() => FleetControlService::patchCommand(190, $change));
    $index->failSet = 0; $index->failDelete = false;
    $part = md5((string)$row['execute_time']) . ':1';
    if ($index->exists($part)) {
        $index->failKey = $part; $index->failSet = 1;
        probeFailure('non-unique/second chunk', FleetCommands::class, static fn() => FleetControlService::patchCommand(190, $change));
    }
    $sync->failKey = '190'; $sync->failSet = 1;
    probeFailure('dirty marker', FleetCommands::class, static fn() => FleetControlService::patchCommand(190, $change));
    $sync->failKey = '190'; $sync->failSet = 1; $sync->afterWrite = true;
    probeFailure('dirty marker/after write', FleetCommands::class, static fn() => FleetControlService::patchCommand(190, $change));
    $sync->afterWrite = false;

    $fleet = new Fleet(56);
    $before = probeState(FleetCommands::class);
    $index->failKey = md5((string)$deadline); $index->failSet = 1;
    $caught = false;
    try { FleetControlService::commit([$fleet], static fn() => FleetControlService::patchCommand(190, $change)); }
    catch (Throwable) { $caught = true; }
    probeCheck($caught && probeState(FleetCommands::class) === $before, 'FleetControl rollback не восстановил состояние.');
    FleetControlService::patchCommand(190, $change);
    probeCheck(FleetCommands::getCommand(190)['execute_time'] === $deadline, 'Retry не сохранился.');
    probeCheck(in_array(190, array_column(FleetCommands::findByIndex('execute_time', [(string)$deadline]) ?? [], 'id'), true), 'Retry не попал в новый индекс.');
    probeCheck(!in_array(190, array_column(FleetCommands::findByIndex('execute_time', [(string)$row['execute_time']]) ?? [], 'id'), true), 'Retry оставил старый индекс.');
    FleetControlService::patchCommand(190, $row);
    echo 'OK FleetControl rollback, retry and old/new membership', PHP_EOL;
    $before = probeState(FleetCommands::class);
    FleetControlService::patchCommand(190, $row);
    probeCheck(probeState(FleetCommands::class) === $before, 'No-op поменял индексы/marker.');
    $error = null;
    $index->failKey = md5((string)$deadline); $index->failSet = 1;
    try {
        FleetControlService::commit([$fleet], static function () use ($change): void {
            FleetControlService::patchCommand(190, ['params' => '{"probe":"first-write"}']);
            FleetControlService::patchCommand(190, $change);
        });
    } catch (Throwable $caught) { $error = $caught; }
    probeCheck($error !== null && probeState(FleetCommands::class) === $before, 'Rollback после успешной первой записи потерял состояние.');
    echo 'OK no-op and commit rollback after earlier successful write', PHP_EOL;

    $new = array_replace($row, ['id' => max(array_keys(FleetCommands::getAll())) + 1000, 'execute_time' => $deadline]);
    foreach (['main', 'index', 'marker'] as $kind) {
        $table = match ($kind) { 'main' => $main, 'index' => $index, 'marker' => $sync };
        $table->failKey = $kind === 'index' ? md5((string)$deadline) : (string)$new['id'];
        $table->failSet = 1;
        probeFailure('add/' . $kind, FleetCommands::class, static fn() => FleetCommands::add($new));
    }
    $unique = probeInstall(Users::class, 'indexTables', 'account');
    $unique->failKey = md5('1000016'); $unique->failSet = 1;
    probeFailure('unique/new bucket', Users::class, static fn() => Users::update(['id' => 10, 'account_id' => 1000016]));
    $unique->failKey = md5('16'); $unique->failDelete = true;
    probeFailure('unique/delete old bucket', Users::class, static fn() => Users::update(['id' => 10, 'account_id' => 1000016]));
    echo 'RESULT B006: native checks passed; SQL transaction READ ONLY; no saver/server started', PHP_EOL;
} finally {
    $db->rollBack();
}
