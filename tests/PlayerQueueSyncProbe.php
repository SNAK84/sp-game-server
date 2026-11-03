<?php

declare(strict_types=1);

// B042: только native-компоненты очереди; без SQL, EventLoop и второго игрового мира.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Environment, Logger, LogLevel};
use SPGame\Game\Repositories\{PlayerQueue as Queue, PlayerQueuePayloads as Payloads};
use Swoole\Table;

if (($argv[1] ?? '') !== '--dev-native') throw new LogicException('Нужен --dev-native.');
Environment::init(__DIR__ . '/../.env');
$environment = new ReflectionProperty(Environment::class, 'env');
$environment->setValue(null, array_replace($environment->getValue(), [
    'LOG_FILE' => '/tmp/sp-game-b042-probe.log', 'LOG_ECHO' => 'false',
]));
(new ReflectionProperty(Logger::class, 'minLevel'))->setValue(Logger::getInstance(), LogLevel::ERROR);

class QueueSyncTable extends Table
{
    public ?string $fault = null;
    public string $operation = 'set';
    public function set(string $key, array $value): bool
    {
        if ($this->operation !== 'set' || $this->fault === null) return parent::set($key, $value);
        $fault = $this->fault; $this->fault = null;
        if ($fault === 'after-false') parent::set($key, $value);
        return false;
    }
    public function del(string $key): bool
    {
        if ($this->operation !== 'del' || $this->fault === null) return parent::del($key);
        $fault = $this->fault; $this->fault = null;
        if ($fault === 'after-false') parent::del($key);
        return false;
    }
}

function queueSyncRows(Table $table): array
{
    $rows = [];
    foreach ($table as $key => $row) $rows[(string)$key] = $row;
    ksort($rows);
    return $rows;
}

function queueSyncState(): array
{
    $state = [];
    foreach (['table', 'syncTable'] as $name) $state[$name] = queueSyncRows((new ReflectionProperty(Queue::class, $name))->getValue());
    // ID намеренно не откатывается при неудачной публикации.
    unset($state['syncTable']['meta']['last_id']);
    foreach ((new ReflectionProperty(Queue::class, 'indexTables'))->getValue() as $name => $table) $state['indexes'][$name] = queueSyncRows($table);
    $state['payloads'] = Payloads::count();
    return $state;
}

function queueSyncInstall(string $name): QueueSyncTable
{
    $property = new ReflectionProperty(Queue::class, $name === 'table' ? 'table' : 'indexTables');
    $value = $property->getValue();
    $old = $name === 'table' ? $value : $value[$name];
    $table = new QueueSyncTable(128);
    if ($name === 'table') {
        foreach ((new ReflectionProperty(Queue::class, 'tableSchema'))->getValue()['columns'] as $field => $def) {
            $table->column($field, $def['swoole'][0], $def['swoole'][1] ?? 0);
        }
    } else {
        $table->column('id', Table::TYPE_INT);
        $table->column('ids', Table::TYPE_STRING, 2048);
    }
    $table->create();
    foreach ($old as $key => $row) $table->set((string)$key, $row);
    if ($name === 'table') $property->setValue(null, $table);
    else { $value[$name] = $table; $property->setValue(null, $value); }
    return $table;
}

$checks = [];
function queueSyncCheck(string $name, bool $ok): void
{
    global $checks;
    $checks[$name] = $ok;
    if (!$ok) echo 'FAIL ', $name, PHP_EOL;
}
function queueSyncEmpty(): bool
{
    $pending = Queue::getPendingSync();
    return $pending['update'] === [] && $pending['delete'] === []
        && (new ReflectionProperty(Queue::class, 'syncTable'))->getValue()->count() === 1;
}

Queue::init();
$capacity = (new ReflectionProperty(Queue::class, 'syncTable'))->getValue()->size;
$schema = (new ReflectionProperty(Queue::class, 'tableSchema'))->getValue();
$payload = ['padding' => str_repeat('x', 600)];
$id = Queue::addQueue(15, 9, 26, Queue::SendActualeData, []);
queueSyncCheck('pending-event-has-no-sql-marker', queueSyncEmpty());
Queue::popByAccount(15);
queueSyncCheck('delete-has-no-tombstone', queueSyncEmpty());
$completed = 0; $volumeError = null;
try {
    for ($i = 0; $i < 5000; ++$i) {
        $id = Queue::addQueue(15, 9, 26, Queue::SendActualeData, []);
        $event = Queue::popByAccount(15);
        if ($event === null || $event['id'] !== $id) throw new RuntimeException('Нарушен порядок события.');
        ++$completed;
    }
} catch (Throwable $error) { $volumeError = get_class($error); }
queueSyncCheck('5000-events-beyond-2048-without-error', $completed === 5000 && $volumeError === null);
queueSyncCheck('volume-leaves-only-meta', Queue::count() === 0 && queueSyncEmpty());
queueSyncCheck('last-id-is-not-reset', Queue::getLastId() === $completed + 1);

// Новый локальный экземпляр компонентов, чтобы проверить остальные ветки и на старом коде.
Queue::init();
$last = 0;
for ($i = 0; $i < 100; ++$i) {
    $id = Queue::addQueue(15, 9, 26, Queue::SendActualeData, $payload);
    $event = Queue::popByAccount(15);
    if ($id <= $last || $event['data'] !== $payload) throw new RuntimeException('Повреждён большой payload.');
    $last = $id;
}
queueSyncCheck('large-payloads-released-without-markers', Payloads::count() === 0 && Queue::count() === 0 && queueSyncEmpty());
$id = Queue::addQueue(15, 9, 26, Queue::SendActualeData, []);
$row = Queue::findExistingById($id);
Queue::update(['id' => $id, 'priority' => 2]);
queueSyncCheck('update-has-no-sql-marker', queueSyncEmpty());
$row = Queue::findExistingById($id);
Queue::discardUncommittedRow($row);
queueSyncCheck('discard-has-no-tombstone', queueSyncEmpty() && Queue::findExistingById($id) === null);
Queue::restoreDeletedRow($row);
queueSyncCheck('restore-has-no-sql-marker', queueSyncEmpty() && Queue::findExistingById($id) === $row);
Queue::delete($row);

Queue::init();
$main = queueSyncInstall('table');
$index = queueSyncInstall('account_id');
foreach (['table' => $main, 'index' => $index] as $name => $table) {
    foreach (['before-false', 'after-false'] as $fault) {
        $before = queueSyncState();
        $table->fault = $fault; $caught = false;
        try { Queue::addQueue(15, 9, 26, Queue::SendActualeData, $payload); }
        catch (Throwable) { $caught = true; }
        queueSyncCheck("enqueue/$name/$fault", $caught && queueSyncState() === $before);
    }
}
$id = Queue::addQueue(15, 9, 26, Queue::SendActualeData, $payload);
$row = Queue::findExistingById($id);
foreach (['before-false', 'after-false'] as $fault) {
    $before = queueSyncState();
    $main->operation = 'del'; $main->fault = $fault; $caught = false;
    try { Queue::delete($row); } catch (Throwable) { $caught = true; }
    queueSyncCheck("delete/$fault/preserves-payload", $caught && queueSyncState() === $before
        && Queue::getByAccount(15)[0]['data'] === $payload);
}
$main->operation = 'set';
Queue::delete($row);
queueSyncCheck('successful-delete-releases-payload', Payloads::count() === 0 && queueSyncEmpty());

$decode = new ReflectionMethod(Queue::class, 'decodeRestartEvent');
$publish = new ReflectionMethod(Queue::class, 'publish');
$expected = [];
foreach ([5001 => [], 5002 => $payload] as $id => $data) {
    $event = ['id' => $id, 'account_id' => 15, 'user_id' => 9, 'planet_id' => 26,
        'action' => Queue::SendActualeData, 'priority' => 1, 'added_at' => microtime(true),
        'data' => $data, 'ready' => 1, 'payload_ttl' => $data === [] ? null : 90.0];
    $decoded = $decode->invoke(null, json_encode($event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $id);
    $ttl = $decoded['payload_ttl']; unset($decoded['payload_ttl']);
    $decoded['data'] = serialize($decoded['data']); $decoded['ready'] = 0;
    $publish->invoke(null, $decoded, $ttl);
    Queue::setLastId(max(Queue::getLastId(), $id));
    $expected[$id] = $data;
}
$restored = Queue::getByAccount(15);
queueSyncCheck('restart-codec-small-and-large', array_column($restored, 'id') === array_keys($expected)
    && $restored[0]['data'] === [] && $restored[1]['data'] === $payload && queueSyncEmpty());
$saved = Payloads::snapshot(Queue::findExistingById(5002));
queueSyncCheck('restart-payload-ttl', $saved['expires_at'] > microtime(true) + 85 && $saved['expires_at'] <= microtime(true) + 90);
queueSyncCheck('next-id-after-restored-events', Queue::nextId() > 5002);
while (Queue::popByAccount(15) !== null) { }
queueSyncCheck('restored-events-drain-cleanly', Queue::count() === 0 && Payloads::count() === 0 && queueSyncEmpty());
queueSyncCheck('capacities-unchanged', $capacity === 2048 && $schema['columns']['data']['swoole'][1] === 512
    && (new ReflectionProperty(Queue::class, 'syncTable'))->getValue()->size === $capacity);
$passed = count(array_filter($checks));
echo json_encode(['passed' => $passed, 'failed' => count($checks) - $passed, 'smallEventsCompleted' => $completed,
    'volumeError' => $volumeError, 'largeEventsCompleted' => 100, 'syncCapacity' => $capacity,
    'finalSyncRows' => (new ReflectionProperty(Queue::class, 'syncTable'))->getValue()->count(),
    'sqlWrites' => false, 'eventLoopStarted' => false]), PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
