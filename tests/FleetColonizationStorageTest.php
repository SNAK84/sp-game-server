<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Services\FleetColonizationService;
use Swoole\Table;

if (!class_exists(Table::class)) {
    echo "SKIP: проверка реального размера params требует Swoole.\n";
    exit(0);
}

// Только локальные Tables процесса. MySQL и игровой runtime не используются.
$repository = new ReflectionClass(FleetCommands::class);
$schema = $repository->getProperty('tableSchema')->getValue();
$table = new Table(16);
foreach ($schema['columns'] as $name => $definition) {
    $table->column($name, ...$definition['swoole']);
}
$table->create();
$sync = new Table(16);
foreach (['need_update', 'need_delete', 'last_id'] as $name) $sync->column($name, Table::TYPE_INT, 8);
$sync->create();
$repository->getProperty('table')->setValue(null, $table);
$repository->getProperty('syncTable')->setValue(null, $sync);
$repository->getProperty('indexes')->setValue(null, []);
$finish = (new ReflectionClass(FleetColonizationService::class))->getMethod('finishCommand');
$params = ['target' => ['type' => 1, 'galaxy' => 1, 'system' => 6, 'orbit' => 4, 'objectType' => 1]];
$command = ['id' => 1, 'fleet_id' => 16, 'command_type' => 34, 'execute_time' => 0.0,
    'params' => json_encode($params, JSON_THROW_ON_ERROR), 'executed' => 0, 'create_time' => 100.0];
$result = ['coordinate' => $params['target'], 'success' => true,
    'planet' => ['id' => 42, 'name' => 'Колония', 'physicalData' => str_repeat('x', 2048)],
    'resources' => [901 => 100.0, 902 => 50.0, 903 => 25.0], 'depositedFuel' => 1000.0, 'consumed' => true];
$table->set('1', $command);
$finish->invoke(null, $command, $params, $result);
$saved = FleetCommands::getCommand(1);
$decoded = json_decode($saved['params'], true, 512, JSON_THROW_ON_ERROR);
if ($saved['executed'] !== 1 || $decoded['colonyResult'] !== ['success' => true, 'planetId' => 42]
    || $decoded['target'] !== $params['target'] || strlen($saved['params']) > $schema['columns']['params']['swoole'][1]) {
    throw new RuntimeException('Успешный результат не сохранён в реальный params без обрезания.');
}
echo 'OK success: params=' . strlen($saved['params']) . " bytes, executed=1\n";

$table->set('1', $command);
$finish->invoke(null, $command, $params, ['coordinate' => $params['target'], 'success' => false,
    'reason' => 'Достигнут лимит планет (3/3). Необходимо повысить астрофизику.']);
$saved = FleetCommands::getCommand(1);
$decoded = json_decode($saved['params'], true, 512, JSON_THROW_ON_ERROR);
if ($saved['executed'] !== 1 || $decoded['colonyResult'] !== ['success' => false, 'planetId' => null]) {
    throw new RuntimeException('Результат отказа не сохранён без обрезания.');
}
echo 'OK rejection: params=' . strlen($saved['params']) . " bytes, executed=1\n";
