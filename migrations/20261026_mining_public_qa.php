<?php

declare(strict_types=1);

// Только подготовка QA10 на остановленном main. Сами миссии отправляются через SPA.
require __DIR__ . '/../vendor/autoload.php';
use SPGame\Core\{Database, Environment};
use SPGame\Game\Repositories as R;
use SPGame\Game\Services\FleetSurveyService;

Environment::init(__DIR__ . '/../.env'); $db = Database::getInstance();
$assert = static function (bool $ok, string $why): void { if (!$ok) throw new LogicException($why); };
$assert(($argv[1] ?? '') === '--prepare', 'Требуется --prepare.');
$socket = @stream_socket_client('tcp://127.0.0.1:' . Environment::getInt('WS_PORT', 9501), $errno, $error, .5);
if (is_resource($socket)) { fclose($socket); throw new LogicException('Сначала остановите main.'); }
$assert(($db->fetchOne('SELECT login FROM accounts WHERE id=16')['login'] ?? '') === 'qafor_260924', 'Другой QA-аккаунт.');
$assert($db->fetchOne("SELECT site_id FROM mining_checkpoints WHERE phase='prepared'") === null, 'Pending journal.');
$assert($db->fetchOne("SELECT id FROM space_battles WHERE phase<>'applied'") === null, 'Активный бой.');
$assert($db->fetchOne("SELECT id FROM fleets WHERE owner_id=10 AND name LIKE 'QA Public %'") === null, 'Fixture уже создан.');
R\Vars::init();
foreach ([R\Config::class,R\Techs::class,R\Planets::class,R\Fleets::class,R\FleetsShips::class,R\FleetCommands::class,
    R\OrbitalResourceSites::class,R\ResourceBases::class,R\ResourceSurveys::class] as $repository) $repository::init();
$home=R\Planets::findExistingById(27);$assert((int)$home['owner_id']===10,'Другая домашняя планета.');
$tech = R\Techs::findExistingById(10); $patch=['id'=>10];$before=[];
foreach ([108=>10,134=>10,136=>3,117=>100] as $id=>$level) {
    $name=R\Vars::$resource[$id];$before[$name]=$tech[$name];$patch[$name]=max($level,(int)$tech[$name]);
}
R\Techs::update($patch);
$now=(float)sprintf('%.6F',microtime(true));
foreach ([19,24] as $id) {
    $site=R\OrbitalResourceSites::findExistingById($id);$assert($site!==null && R\ResourceBases::findExistingById($id)===null,'Объект уже занят.');
    $known=R\ResourceSurveys::findByIndex('owner_site',[10,$id]);
    $reveal=FleetSurveyService::reveal($site,$known,FleetSurveyService::stock($site,$now),$now,100,16,bin2hex(random_bytes(16)));
    if($known) R\ResourceSurveys::update(['id'=>$known['id']]+$reveal);
    else R\ResourceSurveys::add(['owner_id'=>10,'site_id'=>$id]+$reveal);
}
$ids=[];
foreach (['gas'=>224,'belt'=>223,'stay'=>223] as $label=>$ship) {
    $row=['owner_id'=>10,'home_planet_id'=>27,'fallback_planet_id'=>27,'name'=>'QA Public '.$label,
        'status'=>0,'mission'=>0,'current_position_time'=>$now];
    foreach(['start','current','target'] as $p) foreach(['coordinate_type'=>1,'galaxy'=>$home['galaxy'],'system'=>$home['system'],
        'orbit'=>$home['planet'],'object_type'=>1] as $field=>$value) $row[$p.'_'.$field]=$value;
    $id=R\Fleets::add($row);$assert($id!==null,'Флот не создан.');
    R\FleetsShips::add(['id'=>$id,'fleet_id'=>$id,'owner_id'=>10,R\Vars::$resource[$ship].'_count'=>1]);$ids[$label]=$id;
}
foreach([R\Techs::class,R\ResourceSurveys::class,R\Fleets::class,R\FleetsShips::class] as $repository) {
    $repository::syncToDatabase();$pending=$repository::getPendingSync();
    $assert($pending['update']===[] && $pending['delete']===[],'Fixture не сохранён.');
}
echo json_encode(['fleets'=>$ids,'techBefore'=>$before,'surveySites'=>[19,24],'issuedShips'=>[223=>2,224=>1],
    'planetResourcesChanged'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
