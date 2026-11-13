<?php

declare(strict_types=1);

// Общий in-memory fixture: настоящие repositories/services, без MySQL/Swoole.
require __DIR__ . '/FleetTransportTest.php';

use SPGame\Core\Message;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\PlayerQueue;
use SPGame\Game\Services\FleetsServices;
use SPGame\Game\Services\FleetsData;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Services\PageActionService;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\ObjectType;
use SPGame\Game\Enums\FleetCommandType;

function managementRejects(callable $action): void
{
    try {
        $action();
    } catch (InvalidArgumentException|LogicException $exception) {
        return;
    }
    throw new RuntimeException('Ожидалось отклонение недопустимой операции.');
}

function installManagementFixture(): array
{
    static $fixtures = [];
    $fixture = installTransportFixture();
    $registry = FleetsData::getInstance();
    $fleets = new ReflectionProperty(FleetsData::class, 'Fleets');
    $fleets->setAccessible(true);
    $fleets->setValue($registry, [1 => $fixture['fleet']]);
    $property = new ReflectionProperty(AccountData::class, 'FleetsData');
    $property->setAccessible(true);
    $property->setValue($fixture['account'], $registry);
    // Деструктор Fleet удаляет ID из singleton: старые fixtures живут до конца теста.
    $fixtures[] = $fixture;
    return $fixture;
}

$fixture = installManagementFixture();
$account = $fixture['account'];
$fleet = $fixture['fleet'];
transportAssert($fleet->getName() === '', 'Старые флоты используют название по ID.');
FleetsServices::RenameFleet(1, '  Северный дозор  ', $account);
transportAssert($fleet->getName() === 'Северный дозор', 'Название должно нормализоваться.');
transportAssert(Fleets::getById(1)['name'] === 'Северный дозор', 'Название должно попасть в runtime repository.');
transportAssert(in_array(1, Fleets::getPendingSync()['update'], true), 'Переименование должно сохраняться обычным RepositorySaver.');
foreach ([null, [], 123, 'X', str_repeat('Я', 33), '<img src=x>', "А\nБ", '.Флот', 'Флот.'] as $name) {
    managementRejects(fn() => FleetsServices::RenameFleet(1, $name, $account));
    transportAssert($fleet->getName() === 'Северный дозор', 'Ошибочное имя не должно менять состояние.');
}
FleetsServices::RenameFleet(1, '', $account);
transportAssert($fleet->getName() === '', 'Пустое название возвращает отображение по ID.');
echo "OK rename: Unicode, validation, reset and dirty marker\n";

foreach (['DisbandFleet', 'RenameFleet'] as $action) {
    foreach ([null, 0, -1, [], true, 999] as $id) {
        $fixture = installManagementFixture();
        $account = $fixture['account'];
        $request = (new Message())->setMode('fleets')->setAction($action)->setData('Name', 'Дозор');
        if ($id !== null) $request->setData('FleetId', $id);
        $response = (new Message())->setRequestId('management-test');
        transportAssert(PageActionService::handle($request, $account, 1000, $response), 'Action должен возвращать штатный ACK/error.');
        transportAssert($response->getStatus() === 'error', 'Некорректный FleetId должен быть отклонён.');
        transportAssert(PlayerQueue::getByAccount(1000) === null, 'Недопустимая команда не должна попадать в очередь.');
        transportAssert(Fleets::getById(1) !== null, 'Другой флот не должен изменяться.');
    }
    $fixture = installManagementFixture();
    $account = $fixture['account'];
    $account['User']['id'] = 200;
    $request = (new Message())->setMode('fleets')->setAction($action)->setData('FleetId', 1)->setData('Name', 'Чужое имя');
    $response = new Message();
    PageActionService::handle($request, $account, 1000, $response);
    transportAssert($response->getStatus() === 'error' && PlayerQueue::getByAccount(1000) === null, 'Чужой флот нельзя менять даже ручным запросом.');
}
echo "OK invalid and foreign fleet commands do not enqueue\n";

foreach (['mission', 'pending', 'otherPlanet', 'otherObject', 'foreign'] as $case) {
    $fixture = installManagementFixture();
    $account = $fixture['account'];
    $account['Ships'] = ['small_cargo' => 7];
    $fleet = $fixture['fleet'];
    if ($case === 'mission') $fleet->setStatus(Fleet::STATUS_MISSION);
    if ($case === 'pending') FleetCommands::addCommand(1, FleetCommandType::STAY, []);
    if ($case === 'otherPlanet') $account['WorkPlanet'] = 11;
    if ($case === 'otherObject') $fleet->setCurrentCoordinate(Coordinate::object(1, 10, 7, ObjectType::MOON), 100);
    if ($case === 'foreign') $account['User']['id'] = 200;
    $before = $account['Resources'];
    managementRejects(fn() => FleetsServices::DisbandFleet(1, $account, 100));
    transportAssert($account['Ships']['small_cargo'] === 7 && $account['Resources'] === $before, 'Отказ не должен возвращать корабли/ресурсы.');
    transportAssert(Fleets::getById(1) !== null, 'Отклонённое расформирование не должно удалять флот.');
}
echo "OK disband rejects flight, commands, wrong planet/object and owner\n";

$fixture = installManagementFixture();
$account = $fixture['account'];
$fleet = $fixture['fleet'];
$account['Ships'] = ['small_cargo' => 7];
$before = $account['Resources'];
$fleet->getResources()[901]->setCount(25);
$fleet->getResources()[902]->setCount(15);
$fleet->getResources()[903]->setCount(3);
$fleet->setFuelTotal(7);
$fleet->setFuelCurrent(7);
$commandId = FleetCommands::addCommand(1, FleetCommandType::STAY, []);
FleetCommands::markExecuted((int)$commandId['id']);
transportAssert(FleetsServices::DisbandFleet(1, $account, 100), 'Свободный собственный флот должен расформироваться.');
transportAssert($account['Ships']['small_cargo'] === 17, 'Десять кораблей должны вернуться ровно один раз.');
foreach ([901 => 25, 902 => 15, 903 => 10] as $id => $amount) {
    transportAssert($account['Resources'][$id]['count'] === $before[$id]['count'] + $amount, 'Груз и топливо должны возвращаться полностью.');
}
transportAssert(Fleets::getById(1) === null && FleetsShips::findExistingById(1) === null, 'Обе runtime записи должны удалиться.');
transportAssert(in_array(1, Fleets::getPendingSync()['delete'], true) && in_array(1, FleetsShips::getPendingSync()['delete'], true), 'Удаление должно попасть в RepositorySaver.');
transportAssert(FleetCommands::getFleetCommandChain(1) === [], 'Расформированный флот не должен оставлять команды.');
transportAssert(!FleetsServices::DisbandFleet(1, $account, 100) && $account['Ships']['small_cargo'] === 17, 'Повтор не должен дублировать возврат.');
echo "OK disband returns ships, cargo and fuel exactly once and marks deletion\n";

$fixture = installManagementFixture();
$account = $fixture['account'];
$request = (new Message())->setMode('fleets')->setAction('DisbandFleet')->setData('FleetId', 1);
$response = new Message();
PageActionService::handle($request, $account, 1000, $response);
transportAssert($response->getStatus() === 'ok', 'Валидное действие получает ACK.');
transportAssert(count(PlayerQueue::getByAccount(1000) ?? []) === 1, 'Принятое действие должно ждать EventLoop.');
transportAssert(Fleets::getById(1) !== null, 'ACK не должен расформировывать флот синхронно.');
$fixture['fleet']->setStatus(Fleet::STATUS_MISSION);
managementRejects(fn() => FleetsServices::DisbandFleet(1, $account, 100));
echo "OK ACK remains asynchronous and execution revalidates state\n";
