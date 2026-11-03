<?php

declare(strict_types=1);

// Настоящие services/repositories с изолированными runtime Tables, без MySQL.
require __DIR__ . '/FleetManagementTest.php';

use SPGame\Core\Message;
use SPGame\Game\Enums\{FleetCommandType, FleetMissionType};
use SPGame\Game\Fleet\{Fleet, FleetTarget};
use SPGame\Game\Repositories\{FleetCommands, Fleets, FleetsShips, PlayerQueue};
use SPGame\Game\Services\{FleetCommandsService, FleetsServices, PageActionService};
use SPGame\Game\Space\{Coordinate, ObjectType};

function limitsFleet(array $fixture, int $id, int $owner = 100, int $home = 10): Fleet
{
    $row = Fleets::getById(1);
    $row['id'] = $id;
    $row['owner_id'] = $owner;
    $row['home_planet_id'] = $home;
    $row['status'] = Fleet::STATUS_IDLE;
    $row['mission'] = 0;
    Fleets::add($row);
    $ships = FleetsShips::findById(1);
    $ships['id'] = $ships['fleet_id'] = $id;
    $ships['owner_id'] = $owner;
    FleetsShips::add($ships);
    return $fixture['account']->getFleetsData()->get($id);
}

function limitsMissionData(Fleet $fleet, TransportAccountData $account, FleetMissionType $mission): array
{
    $coordinate = Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray();
    $travel = $fleet->getTravelInfo(FleetTarget::fromCoordinates($coordinate), $account, 10.0, $mission);
    return ['fleetId' => $fleet->getId(), 'missionType' => $mission->value, 'coordinates' => $coordinate,
        'speedFactor' => 10.0, 'fuel' => (int)ceil($travel['fuel']['max']), 'resources' => [901 => 10, 902 => 0, 903 => 0]];
}

function limitsStart(array $data, Fleet $fleet, TransportAccountData $account): void
{
    FleetCommandsService::startMission($fleet, $account, $data['missionType'], $data['coordinates'],
        $data['speedFactor'], $data['fuel'], $data['resources'], 100);
}

function limitsRequest(array $data): Message
{
    $request = (new Message())->setMode('fleets')->setAction('SendMission');
    foreach ($data as $key => $value) $request->setData($key, $value);
    return $request;
}

$f = installManagementFixture();
$account = $f['account'];
$account['Ships'] = ['small_cargo' => 5];
$limits = FleetsServices::getLimits($account);
transportAssert($limits['missions']['max'] === 1 && $limits['base']['max'] === 2, 'Начальные лимиты 1 и 2.');
FleetsServices::AddToOrbit([202 => 1], $account, 100);
transportAssert($account['Ships']['small_cargo'] === 4 && FleetsServices::getLimits($account)['base']['used'] === 2,
    'Создание занимает ровно одно место и переносит выбранные корабли.');
managementRejects(fn() => FleetsServices::AddToOrbit([202 => 1], $account, 100));
transportAssert($account['Ships']['small_cargo'] === 4 && Fleets::getById(3) === null,
    'Лимит отклоняет создание до изменения кораблей и repository.');
FleetsServices::MoveShip(1, 0, 202, 1, $account, 100);
transportAssert($account['Ships']['small_cargo'] === 3, 'Добавлять корабли в существующий флот при полном лимите можно.');
FleetsServices::DisbandFleet(2, $account, 100);
transportAssert(FleetsServices::getLimits($account)['base']['used'] === 1, 'Расформирование освобождает место.');
FleetsServices::AddToOrbit([202 => 1], $account, 100);
transportAssert(FleetsServices::getLimits($account)['base']['used'] === 2, 'Освободившийся слот снова доступен.');
echo "OK limits: create, reject without mutations, add ships, disband, reuse slot\n";

$f = installManagementFixture();
$account = $f['account'];
$account['Ships'] = ['small_cargo' => 5];
FleetsServices::AddToOrbit([999 => 1, 202 => 0], $account, 100);
transportAssert(Fleets::getById(2) === null, 'Пустой состав не расходует слот.');
$foreign = limitsFleet($f, 2, 200);
transportAssert(FleetsServices::getLimits($account)['base']['used'] === 1, 'Чужие флоты не занимают места игрока.');
$account['Techs']['computer_tech'] = 3;
$account['Techs']['fleet_logistics_tech'] = 4;
$limits = FleetsServices::getLimits($account);
transportAssert($limits['missions']['max'] === 4 && $limits['base']['max'] === 6, 'Каждый завершённый уровень даёт один слот.');
echo "OK limits: empty selection, ownership and completed research formulas\n";

$f = installManagementFixture();
$account = $f['account'];
$second = limitsFleet($f, 2);
$firstData = limitsMissionData($f['fleet'], $account, FleetMissionType::TRANSPORT);
$secondData = limitsMissionData($second, $account, FleetMissionType::TRANSPORT);
// Оба запроса валидны до выполнения первого. Повторная проверка при execution обязательна.
foreach ([$firstData, $secondData] as $data) {
    $response = (new Message())->setRequestId('limits-ack');
    $request = limitsRequest($data);
    PageActionService::handle($request, $account, 1000, $response);
    transportAssert($response->getStatus() === 'ok', 'Свободный слот допускает асинхронный ACK.');
}
limitsStart($firstData, $f['fleet'], $account);
$before = $account['Resources'];
managementRejects(fn() => limitsStart($secondData, $second, $account));
transportAssert($account['Resources'] === $before && FleetCommands::getFleetQueue(2) === []
    && $second->getStatus() === Fleet::STATUS_IDLE, 'Вторая принятая команда не обходит последний слот.');
$response = new Message();
$request = limitsRequest($secondData);
PageActionService::handle($request, $account, 1000, $response);
transportAssert($response->getStatus() === 'error', 'При полном лимите новый request отклоняется.');
transportAssert(FleetsServices::getLimits($account)['base']['used'] === 2, 'Улетевший флот сохраняет домашнее место.');
while (($command = FleetCommands::getActiveCommand(1)) !== null) {
    FleetCommandsService::completeCommand($command, $f['fleet'], $account);
}
transportAssert(FleetsServices::getLimits($account)['missions']['used'] === 0, 'STAY освобождает миссионный слот.');
limitsStart($secondData, $second, $account);
transportAssert(FleetsServices::getLimits($account)['missions']['used'] === 1, 'После завершения слот повторно доступен.');
echo "OK limits: request ACK, execution recheck, no resource mutation, round-trip and mission slot reuse\n";

$f = installManagementFixture();
$account = $f['account'];
limitsFleet($f, 2, 100, 11);
limitsFleet($f, 3, 100, 11);
$data = limitsMissionData($f['fleet'], $account, FleetMissionType::RELOCATE);
$before = $account['Resources'];
managementRejects(fn() => limitsStart($data, $f['fleet'], $account));
transportAssert($account['Resources'] === $before && FleetCommands::getFleetQueue(1) === [],
    'Передислокация на заполненную базу не расходует топливо/груз и не создаёт команды.');
$account['Techs']['fleet_logistics_tech'] = 1;
limitsStart($data, $f['fleet'], $account);
$targetLimits = FleetsServices::getLimits($account, $f['target'])['base'];
transportAssert($targetLimits['used'] === 3 && $targetLimits['incoming'] === 1, 'Летящая передислокация резервирует цель.');
managementRejects(fn() => FleetsServices::assertBaseSlot($account, $f['target']));
while (($command = FleetCommands::getActiveCommand(1)) !== null) {
    FleetCommandsService::completeCommand($command, $f['fleet'], $account);
}
$targetLimits = FleetsServices::getLimits($account, $f['target'])['base'];
transportAssert($targetLimits['used'] === 3 && $targetLimits['incoming'] === 0 && $targetLimits['based'] === 3,
    'После прибытия резерв становится обычным местом, без двойного учёта.');
transportAssert(FleetsServices::getLimits($account, $f['source'])['base']['used'] === 0,
    'После передислокации прежнее домашнее место освобождается.');
echo "OK limits: relocation capacity, reservation, arrival and old base release\n";

$f = installManagementFixture();
$account = $f['account'];
$f['fleet']->setMission(FleetMissionType::RELOCATE);
$f['fleet']->setStatus(Fleet::STATUS_MISSION);
// Межсистемный Fleet.target — граница, а база назначения находится в STAY.
$f['fleet']->setTargetCoordinate(Coordinate::object(1, 10, 16, angle: 0.0));
$f['fleet']->save();
$at = Coordinate::object(2, 15, 3, ObjectType::MOON)->toArray();
FleetCommands::addCommand(1, FleetCommandType::STAY, ['at' => $at]);
$moon = ['id' => 99, 'owner_id' => 100, 'galaxy' => 2, 'system' => 15, 'planet' => 3, 'planet_type' => 3];
$planet = $moon; $planet['planet_type'] = 1;
transportAssert(FleetsServices::getLimits($account, $moon)['base']['incoming'] === 1,
    'Межсистемный резерв берётся по финальной базе, включая луну.');
transportAssert(FleetsServices::getLimits($account, $planet)['base']['incoming'] === 0,
    'Планета и луна на одной орбите имеют отдельные места.');
echo "OK limits: cross-system destination, moon vs planet reservation\n";

$f = installManagementFixture();
$account = $f['account'];
limitsFleet($f, 2);
limitsFleet($f, 3);
$account['Ships'] = ['small_cargo' => 5];
$request = (new Message())->setMode('fleets')->setAction('toOrbit')->setData('items', [202 => 1]);
$response = (new Message())->setRequestId('limits-create-error');
transportAssert(PageActionService::handle($request, $account, 1000, $response), 'Ошибка создания использует обычный error response.');
transportAssert($response->getStatus() === 'error' && $response->source()['requestId'] === 'limits-create-error'
    && PlayerQueue::getByAccount(1000) === null, 'Недопустимое создание не попадает в PlayerQueue, requestId сохранён.');
transportAssert(Fleets::getById(1) !== null && Fleets::getById(2) !== null && Fleets::getById(3) !== null,
    'Существующие флоты сверх лимита не удаляются.');
echo "OK limits: legacy over-cap fleets preserved, blocked creation does not enqueue\n";

$f = installManagementFixture();
$account = $f['account'];
$account['Ships'] = ['small_cargo' => 5];
for ($i = 0; $i < 2; $i++) {
    $response = (new Message())->setRequestId('create-' . $i);
    PageActionService::handle($request, $account, 1000, $response);
    transportAssert($response->getStatus() === 'ok', 'Пока место свободно, request только принимается.');
}
transportAssert(FleetsServices::getLimits($account)['base']['used'] === 1, 'ACK не создаёт флот синхронно.');
FleetsServices::AddToOrbit([202 => 1], $account, 100);
managementRejects(fn() => FleetsServices::AddToOrbit([202 => 1], $account, 100));
transportAssert($account['Ships']['small_cargo'] === 4 && FleetsServices::getLimits($account)['base']['used'] === 2,
    'Две принятые команды создания не расходуют один последний слот дважды.');
echo "OK limits: two accepted creation requests revalidate the last base slot\n";
