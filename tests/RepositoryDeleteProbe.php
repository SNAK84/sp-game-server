<?php

declare(strict_types=1);

// B039: native-отказы на READ ONLY снимке dev-БД, не второй игровой сервер.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\{FleetCommands, Users};
use Swoole\Table;

if (($argv[1] ?? '') !== '--dev-readonly') throw new LogicException('Нужен --dev-readonly.');
Environment::init(__DIR__ . '/../.env');
$environment = new ReflectionProperty(Environment::class, 'env');
$environment->setValue(null, array_replace($environment->getValue(), [
    'LOG_FILE' => '/tmp/sp-game-b039-probe.log', 'LOG_ECHO' => 'false',
]));
$db = Database::getInstance();
$db->query('SET TRANSACTION READ ONLY');
$db->beginTransaction();

class RepositoryDeleteTable extends Table
{
    public array $faults = [];
    public array $calls = [];

    private function faultFor(string $operation, string $key): ?string
    {
        $this->calls[$operation][$key] = ($this->calls[$operation][$key] ?? 0) + 1;
        foreach ($this->faults as $number => &$fault) {
            if ($fault['key'] !== $key || ($fault['operation'] !== '*' && $fault['operation'] !== $operation)) continue;
            if (--$fault['nth'] > 0) return null;
            $kind = $fault['kind'];
            unset($this->faults[$number]);
            return $kind;
        }
        return null;
    }

    public function arm(string $operation, string $key, string $kind, int $nth = 1): void
    {
        $this->faults[] = compact('operation', 'key', 'kind', 'nth');
    }

    public function set(string $key, array $value): bool
    {
        $fault = $this->faultFor('set', $key);
        if ($fault === null) return parent::set($key, $value);
        if ($fault === 'silent-success') {
            // True с неверным after проверяем даже при перезаписи неизменной части индекса.
            $field = array_key_first($value);
            $value[$field] = (int)$value[$field] + 1;
            parent::set($key, $value);
            return true;
        }
        if (str_starts_with($fault, 'after-')) parent::set($key, $value);
        if (str_ends_with($fault, '-throw')) throw new RuntimeException('Контрольный отказ B039.');
        return false;
    }

    public function del(string $key): bool
    {
        $fault = $this->faultFor('del', $key);
        if ($fault === null) return parent::del($key);
        if (str_starts_with($fault, 'after-')) parent::del($key);
        if (str_ends_with($fault, '-throw')) throw new RuntimeException('Контрольный отказ B039.');
        return $fault === 'silent-success';
    }
}

function deleteRows(Table $table): array
{
    $rows = [];
    foreach ($table as $key => $row) $rows[(string)$key] = $row;
    ksort($rows);
    return $rows;
}

function deleteState(string $class): array
{
    $state = [];
    foreach (['table', 'syncTable'] as $name) $state[$name] = deleteRows((new ReflectionProperty($class, $name))->getValue());
    foreach ((new ReflectionProperty($class, 'indexTables'))->getValue() as $name => $table) $state['indexes'][$name] = deleteRows($table);
    return $state;
}

function deleteInstall(string $class, array $state): array
{
    $tables = [];
    $indexes = (new ReflectionProperty($class, 'indexes'))->getValue();
    foreach (['table', 'syncTable', ...array_keys($state['indexes'])] as $name) {
        $rows = $state[$name] ?? $state['indexes'][$name];
        // Размер по реальному снимку: не выделять десятки тысяч строк на каждый case.
        $table = new RepositoryDeleteTable(max(128, count($rows) * 4));
        if ($name === 'table') {
            foreach ((new ReflectionProperty($class, 'tableSchema'))->getValue()['columns'] as $column => $def) {
                $table->column($column, $def['swoole'][0], $def['swoole'][1] ?? 0);
            }
        } elseif ($name === 'syncTable') {
            foreach (['need_update', 'need_delete', 'last_id'] as $column) $table->column($column, Table::TYPE_INT);
        } else {
            $table->column('id', Table::TYPE_INT);
            $sample = reset($rows) ?: [];
            $unique = $indexes[$name]['Unique'] ?? !array_key_exists('ids', $sample);
            if (!$unique) $table->column('ids', Table::TYPE_STRING, 2048);
        }
        $table->create();
        foreach ($rows as $key => $row) $table->set((string)$key, $row);
        $table->calls = [];
        $tables[$name] = $table;
    }
    foreach (['table', 'syncTable'] as $name) (new ReflectionProperty($class, $name))->setValue(null, $tables[$name]);
    (new ReflectionProperty($class, 'indexTables'))->setValue(null, array_diff_key($tables, ['table' => 1, 'syncTable' => 1]));
    return $tables;
}

$results = [];
function deleteCheck(string $label, bool $ok): void
{
    global $results;
    $results[$label] = $ok;
    if (!$ok) echo 'FAIL ', $label, PHP_EOL;
}

function deleteFailure(string $label, string $class, callable $operation, array $before): void
{
    $error = null;
    try { $operation(); } catch (Throwable $caught) { $error = $caught; }
    deleteCheck($label, $error !== null && deleteState($class) === $before);
}

try {
    if (($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login'] ?? '') !== 'qafor_260924') throw new LogicException('Другой dev/QA.');
    foreach ([FleetCommands::class, Users::class] as $class) $class::init();
    $command = FleetCommands::findExistingById(190);
    $user = Users::findExistingById(10);
    if ($command === null || (int)$command['fleet_id'] !== 56 || $user === null || (int)$user['account_id'] !== 16) {
        throw new LogicException('Изменилась QA-сцена: не создавать новый мир.');
    }
    $baselines = [];
    foreach ([FleetCommands::class, Users::class] as $class) $baselines[$class] = deleteState($class);
    $kinds = ['before-false', 'after-false', 'before-throw', 'after-throw', 'silent-success'];
    foreach ([FleetCommands::class => $command, Users::class => $user] as $class => $row) {
        $id = (string)$row['id'];
        $baseline = $baselines[$class];
        $targets = ['table' => $id, 'syncTable' => $id];
        foreach ((new ReflectionProperty($class, 'indexes'))->getValue() as $name => $index) {
            $values = array_map(static fn($field) => $row[$field], (array)$index['key']);
            $key = md5(implode('_', $values));
            $targets[$name] = $key;
            if (isset($baseline['indexes'][$name][$key . ':1'])) $targets[$name . '/chunk2'] = $key . ':1';
        }
        foreach (['delete', 'discardUncommittedRow'] as $operation) {
            foreach ($targets as $target => $key) foreach ($kinds as $kind) {
                $tables = deleteInstall($class, $baseline);
                $table = $tables[explode('/', $target)[0]];
                $table->arm($target === 'table' ? 'del' : ($target === 'syncTable' ? 'set' : '*'), $key, $kind);
                deleteFailure(basename(str_replace('\\', '/', $class)) . "/$operation/$target/$kind", $class,
                    static fn() => $class::$operation($row), $baseline);
            }
        }
        foreach ([null, ['need_update' => 0, 'need_delete' => 0, 'last_id' => 71],
            ['need_update' => 1, 'need_delete' => 0, 'last_id' => 72],
            ['need_update' => 0, 'need_delete' => 1, 'last_id' => 73]] as $marker) {
            $before = $baseline;
            unset($before['syncTable'][$id]);
            if ($marker !== null) $before['syncTable'][$id] = $marker;
            ksort($before['syncTable']);
            $tables = deleteInstall($class, $before);
            $tables['syncTable']->arm('set', $id, 'after-false');
            deleteFailure("$class/preserve-marker/" . ($marker['last_id'] ?? 'absent'), $class, static fn() => $class::delete($row), $before);
        }
        $tables = deleteInstall($class, $baseline);
        $class::delete($row);
        $deleted = deleteState($class);
        deleteCheck("$class/delete-success", $class::findExistingById((int)$id) === null
            && in_array((int)$id, $class::getPendingSync()['delete'], true));
        foreach (['table', 'syncTable'] as $target) foreach ($kinds as $kind) {
            $tables = deleteInstall($class, $deleted);
            $tables[$target]->arm('set', $id, $kind);
            deleteFailure("$class/restore/$target/$kind", $class, static fn() => $class::restoreDeletedRow($row), $deleted);
        }
        foreach ($targets as $target => $key) {
            if (in_array($target, ['table', 'syncTable'], true)) continue;
            foreach ($kinds as $kind) {
                $tables = deleteInstall($class, $deleted);
                $tables[explode('/', $target)[0]]->arm('*', $key, $kind);
                deleteFailure("$class/restore/$target/$kind", $class, static fn() => $class::restoreDeletedRow($row), $deleted);
            }
        }
        $tables = deleteInstall($class, $deleted);
        $class::restoreDeletedRow($row);
        deleteCheck("$class/restore-one-marker-commit", ($tables['syncTable']->calls['set'][$id] ?? 0) === 1
            && $class::findExistingById((int)$id) === $row
            && $tables['syncTable']->get($id)['need_delete'] === 0
            && in_array((int)$id, $class::getPendingSync()['update'], true));
        // Прежний restore писал marker дважды: отказ второй записи оставлял строку с DELETE.
        $tables = deleteInstall($class, $deleted);
        $tables['syncTable']->arm('set', $id, 'before-false', 2);
        try { $class::restoreDeletedRow($row); } catch (Throwable) { }
        deleteCheck("$class/no-unprotected-second-marker-write", $class::findExistingById((int)$id) === $row
            && $tables['syncTable']->get($id)['need_delete'] === 0);
        $before = $baseline;
        unset($before['table'][$id]);
        $tables = deleteInstall($class, $before);
        $tables['syncTable']->arm('set', $id, 'after-false');
        deleteFailure("$class/discard-absent-rollback", $class, static fn() => $class::discardUncommittedRow($row), $before);
        $tables = deleteInstall($class, $before);
        $class::discardUncommittedRow($row);
        deleteCheck("$class/discard-absent-success", $class::findExistingById((int)$id) === null
            && $tables['syncTable']->get($id)['need_delete'] === 1);
        $after = deleteState($class);
        $class::discardUncommittedRow($row);
        deleteCheck("$class/repeated-discard", deleteState($class) === $after);
        $class::restoreDeletedRow($row);
        $after = deleteState($class);
        $class::restoreDeletedRow($row);
        deleteCheck("$class/repeated-restore", deleteState($class) === $after);
    }
    $tables = deleteInstall(Users::class, $baselines[Users::class]);
    $phantom = array_replace($user, ['id' => 1000000010]);
    $before = deleteState(Users::class);
    Users::discardUncommittedRow($phantom);
    deleteCheck('discard-does-not-delete-another-unique-owner', deleteRows($tables['account']) === $before['indexes']['account']);

    $tables = deleteInstall(FleetCommands::class, $baselines[FleetCommands::class]);
    $attempt = array_replace($command, ['execute_time' => (float)$command['execute_time'] + 12345.0]);
    $group = md5((string)$attempt['execute_time']);
    $tables['execute_time']->set($group, ['id' => $command['id'], 'ids' => serialize([$command['id']])]);
    FleetCommands::discardUncommittedRow($attempt);
    $left = false;
    foreach ((new ReflectionProperty(FleetCommands::class, 'indexTables'))->getValue() as $table) {
        foreach ($table as $part) if (isset($part['ids'])
            && in_array($command['id'], unserialize($part['ids'], ['allowed_classes' => false]) ?: [], true)) $left = true;
    }
    deleteCheck('discard-removes-actual-and-attempted-index-values', !$left);

    $tables = deleteInstall(FleetCommands::class, $baselines[FleetCommands::class]);
    $tables['table']->arm('del', (string)$command['id'], 'after-false');
    $tables['table']->arm('set', (string)$command['id'], 'before-false');
    // Два независимых отказа: исходный DELETE и попытка вернуть строку.
    $rollbackError = null;
    try { FleetCommands::delete($command); } catch (Throwable $caught) { $rollbackError = $caught; }
    deleteCheck('failed-rollback-reported-not-silent', $rollbackError !== null
        && str_contains($rollbackError->getMessage(), 'Не выполнен rollback runtime записи')
        && $rollbackError->getPrevious() !== null && FleetCommands::findExistingById((int)$command['id']) === null);
    $passed = count(array_filter($results));
    echo json_encode(['passed' => $passed, 'failed' => count($results) - $passed, 'sqlReadOnly' => true]), PHP_EOL;
} finally {
    $db->rollBack();
}
exit(in_array(false, $results, true) ? 1 : 0);
