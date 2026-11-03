<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
// Failure injection не должна добавлять ложные игровые ошибки в общий game.log.
$logger = SPGame\Core\Logger::getInstance();
(new ReflectionProperty(SPGame\Core\Logger::class, 'islogFile'))->setValue($logger, false);
require __DIR__ . '/FleetAttackTest.php';

use SPGame\Game\Repositories\{FleetCommands, Fleets, FleetsShips, Messages, PlanetBattles, PlanetResources, Users};
use SPGame\Game\Services\{FleetAttackService, FleetBattleService};

function reportPipelineStart(): array
{
    $f = attackFixture();
    attackStart($f);
    attackArrive($f);
    $now = attackCalculate();
    return [$f, $now];
}

[$f, $now] = reportPipelineStart();
$before = [Fleets::getAll(), FleetsShips::getAll(), FleetCommands::getAll(), PlanetResources::getAll(), Messages::getAll()];
BattleReportsMemory::$failPrepare = true;
FleetAttackService::process($now);
transportAssert([Fleets::getAll(), FleetsShips::getAll(), FleetCommands::getAll(), PlanetResources::getAll(), Messages::getAll()] === $before,
    'SQL failure подготовки архива не меняет gameplay и сообщения.');
transportAssert(PlanetBattles::state(11)['phase'] === 'finished', 'Ошибка БД оставляет бой для повторной попытки.');
BattleReportsMemory::$failPrepare = false;
FleetAttackService::process($now + 1);
$state = PlanetBattles::state(11);
$row = BattleReportsMemory::$rows[$state['id']];
transportAssert($state['phase'] === 'applied' && $row['ready'], 'Retry применяет бой и публикует архив.');
$snapshot = $row['report'];
transportAssert($snapshot['version'] === 1 && count($snapshot['rounds']) > 0 && count($snapshot['groups']) > 0,
    'Есть version, группы и все раунды.');
transportAssert(!str_contains(json_encode($snapshot), $state['seed']) && !array_key_exists('application', $snapshot)
    && !array_key_exists('mission', $snapshot), 'В публичном DTO нет seed и служебного commit/route state.');
$saved = json_encode($snapshot, JSON_THROW_ON_ERROR);
foreach ($snapshot['groups'] as $group) {
    transportAssert($group['before'] === $group['survived'] + $group['restored'] + $group['lost'], 'Потери/восстановление сходятся.');
    transportAssert(isset($snapshot['participants'][$group['participant']]), 'Группа ссылается на snapshot участника.');
}
FleetAttackService::process($now + 2);
transportAssert(count(BattleReportsMemory::$rows) === 1 && json_encode(BattleReportsMemory::$rows[$state['id']]['report']) === $saved,
    'Повтор обработки не дублирует и не меняет доклад.');
echo "OK archive prepare failure leaves gameplay unchanged; retry publishes immutable public snapshot\n";

[$f, $now] = reportPipelineStart();
BattleReportsMemory::$failPublish = true;
FleetAttackService::process($now);
$state = PlanetBattles::state(11);
$resources = PlanetResources::findExistingById(11);
$messages = Messages::getAll();
transportAssert($state['phase'] === 'settled' && !BattleReportsMemory::$rows[$state['id']]['ready'],
    'При ошибке публикации итог применён, но prepared ещё скрыт.');
BattleReportsMemory::$failPublish = false;
FleetAttackService::process($now + 1);
transportAssert(PlanetBattles::state(11)['phase'] === 'applied' && BattleReportsMemory::$rows[$state['id']]['ready'], 'Повтор публикует тот же доклад.');
transportAssert(PlanetResources::findExistingById(11) === $resources && Messages::getAll() === $messages,
    'При повторной публикации нет второго списания/добычи/сообщений.');
echo "OK archive publish failure retries without applying loot or messages twice\n";

[$f, $now] = reportPipelineStart();
$reflection = new ReflectionClass(Messages::class);
$table = $reflection->getProperty('table')->getValue();
$table->failedWrites = 1;
FleetAttackService::process($now);
$state = PlanetBattles::state(11);
transportAssert(!BattleReportsMemory::$rows[$state['id']]['ready'], 'Неудачный gameplay commit не публикует доклад.');
FleetAttackService::process($now + 1);
transportAssert(PlanetBattles::state(11)['phase'] === 'applied' && count(BattleReportsMemory::$rows) === 1,
    'Retry после runtime rollback использует тот же ID боя.');
echo "OK gameplay rollback keeps report hidden until successful commit\n";

if (in_array('--export-report', $argv ?? [], true)) {
    echo 'REPORT_FIXTURE ', json_encode(BattleReportsMemory::$rows[$state['id']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
}
