<?php

declare(strict_types=1);

// B038: native-отказы на READ ONLY снимке dev-БД, без второго игрового мира.
require __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories\EntitySettings;
use Swoole\Table;

if (($argv[1] ?? '') !== '--dev-readonly') throw new LogicException('Нужен --dev-readonly.');
Environment::init(__DIR__ . '/../.env');
$environment = new ReflectionProperty(Environment::class, 'env');
$environment->setValue(null, array_replace($environment->getValue(), [
    'LOG_FILE' => '/tmp/sp-game-b038-probe.log', 'LOG_ECHO' => 'false',
]));
$db = Database::getInstance();
$db->query('SET TRANSACTION READ ONLY');
$db->beginTransaction();

class EntitySettingsProbeTable extends Table
{
    public ?string $fault = null;
    public function set(string $key, array $value): bool
    {
        $fault = $this->fault;
        $this->fault = null;
        if ($fault === null) return parent::set($key, $value);
        if (str_starts_with($fault, 'after-')) parent::set($key, $value);
        if ($fault === 'after-throw') throw new RuntimeException('Контрольный отказ B038.');
        return $fault === 'silent-success';
    }
}

function settingsRows(Table $table): array
{
    $result = [];
    foreach ($table as $key => $row) $result[(string)$key] = $row;
    ksort($result);
    return $result;
}

function settingsInstall(string $property, array $rows): EntitySettingsProbeTable
{
    $table = new EntitySettingsProbeTable(32768);
    if ($property === 'table') {
        foreach ((new ReflectionProperty(EntitySettings::class, 'tableSchema'))->getValue()['columns'] as $name => $column) {
            $table->column($name, $column['swoole'][0], $column['swoole'][1] ?? 0);
        }
    } else {
        foreach (['need_update', 'need_delete', 'last_id'] as $name) $table->column($name, Table::TYPE_INT);
    }
    $table->create();
    foreach ($rows as $key => $row) $table->set((string)$key, $row);
    (new ReflectionProperty(EntitySettings::class, $property))->setValue(null, $table);
    return $table;
}

function settingsCheck(bool $ok, string $name): void
{
    if (!$ok) throw new RuntimeException($name);
    echo 'OK ', $name, PHP_EOL;
}

try {
    $qa = $db->fetchOne('SELECT p.id FROM planets p JOIN users u ON u.id=p.owner_id '
        . 'JOIN accounts a ON a.id=u.account_id WHERE a.login=? AND p.planet_type=1 ORDER BY p.id LIMIT 1', ['qaown_260924']);
    if ($qa === null) throw new LogicException('Нет разрешённой QA-планеты.');
    EntitySettings::init();
    $baseline = [];
    foreach (['table', 'syncTable'] as $property) {
        $baseline[$property] = settingsRows((new ReflectionProperty(EntitySettings::class, $property))->getValue());
    }
    $planet = (int)$qa['id'];
    $key = (string)($planet * 1000 + 1);
    $row = ['planet_id' => $planet, 'entity_id' => 1, 'efficiency' => 70, 'last_used' => 0];
    $failures = $passed = 0;
    foreach (['add-new', 'add-existing', 'update'] as $operation) {
        foreach (['table', 'syncTable'] as $target) {
            foreach (['before-false', 'after-false', 'after-throw', 'silent-success'] as $fault) {
                $before = $baseline;
                unset($before['syncTable'][$key]);
                if ($operation === 'add-new') unset($before['table'][$key]);
                else $before['table'][$key] = array_replace($row, ['efficiency' => 100]);
                $main = settingsInstall('table', $before['table']);
                $sync = settingsInstall('syncTable', $before['syncTable']);
                $faultTable = $target === 'table' ? $main : $sync;
                $faultTable->fault = $fault;
                $caught = false;
                try {
                    if ($operation === 'update') EntitySettings::update($row);
                    else EntitySettings::add($row);
                } catch (Throwable) { $caught = true; }
                $ok = $caught && settingsRows($main) === $before['table'] && settingsRows($sync) === $before['syncTable'];
                echo $ok ? 'OK ' : 'FAIL ', "$operation/$target/$fault", PHP_EOL;
                $ok ? ++$passed : ++$failures;
            }
        }
    }
    if ($failures > 0) {
        echo json_encode(['passed' => $passed, 'failed' => $failures, 'sqlReadOnly' => true]), PHP_EOL;
        exit(1);
    }
    $main = settingsInstall('table', $baseline['table']);
    $sync = settingsInstall('syncTable', $baseline['syncTable']);
    settingsCheck(EntitySettings::add($row) === (int)$key && $main->get($key) === $row, 'composite-key-without-synthetic-id-column');
    EntitySettings::update(['planet_id' => $planet, 'entity_id' => 1, 'efficiency' => 80]);
    settingsCheck(EntitySettings::getEfficiency($planet, 1) === 80 && EntitySettings::get($planet, 1)['efficiency'] === 80,
        'efficiency-read-and-update');
    settingsCheck($sync->get($key)['need_update'] === 1, 'composite-dirty-marker');
    $beforeOther = settingsRows($main);
    unset($beforeOther[$key]);
    $afterOther = $baseline['table'];
    unset($afterOther[$key]);
    settingsCheck($beforeOther === $afterOther, 'other-planets-and-entities-unchanged');
    $sync->del($key);
    EntitySettings::add(array_replace($row, ['efficiency' => 90]), false);
    settingsCheck($main->get($key)['efficiency'] === 90 && !$sync->exists($key), 'startup-add-without-dirty-marker');
    echo json_encode(['passed' => $passed + 5, 'failed' => 0, 'sqlReadOnly' => true]), PHP_EOL;
} finally {
    $db->rollBack();
}
