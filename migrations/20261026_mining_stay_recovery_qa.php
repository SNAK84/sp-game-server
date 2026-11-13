<?php

declare(strict_types=1);

// Только восстановление QA74 после найденного при browser-проверке handoff.
// Экономический checkpoint и добытые ресурсы не изменяются.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories as R;

Environment::init(__DIR__ . '/../.env');
$assert = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$assert(($argv[1] ?? '') === '--recover', 'Требуется --recover.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите main.'); }
$db = Database::getInstance();
$assert($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") === null, 'Pending journal.');
R\Vars::init();
foreach ([R\ResourceBases::class,R\Fleets::class,R\FleetCommands::class] as $repository) $repository::init();
$fleet=R\Fleets::findExistingById(74);
$assert($fleet['name']==='QA Public belt' && (int)$fleet['owner_id']===10 && (int)$fleet['current_site_id']===19, 'Другой флот.');
$rows=R\FleetCommands::getFleetCommandChain(74);$mining=$stay=null;
foreach($rows as $row) {
    if((int)$row['command_type']===61) $mining=$row;
    if((int)$row['command_type']===52) $stay=$row;
}
$run=json_decode($mining['params'] ?? '{}',true)['mining'] ?? [];
$params=json_decode($stay['params'] ?? '{}',true);
$assert(($run['phase'] ?? '')==='finished' && ($run['finish'] ?? '')==='stay'
    && (int)$mining['executed']===1 && ($params['pause'] ?? '')==='plan', 'Не тот handoff.');
$assert(R\ResourceBases::findExistingById(19)===null, 'Объект уже занят.');
$now=(float)sprintf('%.6F',microtime(true));
R\ResourceBases::add(['id'=>19,'owner_id'=>10,'created_at'=>$now,'updated_at'=>$now]);
R\FleetCommands::update(['id'=>$mining['id'],'executed'=>0]);
unset($params['pause'],$params['planError']);
R\FleetCommands::update(['id'=>$stay['id'],'execute_time'=>0.0,'params'=>json_encode($params,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
foreach ([R\ResourceBases::class,R\FleetCommands::class] as $repository) {
    $repository::syncToDatabase();$pending=$repository::getPendingSync();
    $assert($pending['update']===[] && $pending['delete']===[], 'Recovery не сохранён.');
}
echo json_encode(['fleetId'=>74,'siteId'=>19,'replayFinishedCommand'=>$mining['id'],
    'economicStateChanged'=>false,'reportMustNotDuplicate'=>true]),PHP_EOL;
