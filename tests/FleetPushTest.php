<?php

declare(strict_types=1);

// Используем существующий runtime fixture без Swoole/MySQL.
require __DIR__ . '/FleetTransportTest.php';

use SPGame\Core\Connect;
use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Services\EventLoop;
use SPGame\Game\Services\FleetCommandsService;
use SPGame\Game\Services\FleetsData;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\ObjectType;

final class FleetPushEventLoop extends EventLoop
{
    public array $sentAccounts = [];

    public function runFleetEvents(float $now): bool
    {
        return $this->processFleetEvents($now);
    }

    protected function sendActualData(int $accountId): void
    {
        $this->sentAccounts[] = $accountId;
    }

    protected function processFleetObjectStateAtTime(array $command, Fleet $fleet, float $eventTime): void
    {
        // Эти тесты проверяют выбор получателей после команд; расчёт production
        // чужой планеты тестируется отдельно.
    }
}

function installFleetPushFixture(bool $invalidTransition = false): Fleet
{
    testIncomingFleetIsVisibleWithoutPrivateCargoData();
    Connect::init();
    Connect::set(1, '127.0.0.1', 1234, 1000);
    Connect::set(2, '127.0.0.1', 1235, 2000);

    $fleet = FleetsData::getInstance()->get(1);
    $fleet->setReturn(false);
    $fleet->setCurrentCoordinate(Coordinate::point(1, 9, 1000.0, 0.0), 100.0);
    $fleet->setTargetCoordinate(Coordinate::object(1, 10, 8, ObjectType::PLANET));
    $fleet->setFuelTotal(10000.0);
    $fleet->setFuelCurrent(10000.0);
    $fleet->save();

    $commands = FleetCommandsService::setFleetCommands(1, [
        ['type' => FleetCommandType::MOVE_BETWEEN_SYSTEMS, 'params' => [
            'target' => Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray(),
        ]],
        ['type' => FleetCommandType::MOVE_IN_SYSTEM, 'params' => [
            'target' => Coordinate::object(1, 10, 8, ObjectType::PLANET)->toArray(),
            'speedFactor' => 10.0,
            'returnLeg' => false,
        ]],
    ]);
    $params = $commands[0]['params'];
    if (!$invalidTransition) {
        $params['transitionPoint'] = Coordinate::point(1, 10, 1000.0, 0.0)->toArray();
    }
    FleetCommandsService::activateCommand((int)$commands[0]['id'], 150.0, $params);
    return $fleet;
}

$fleet = installFleetPushFixture();
$loop = new FleetPushEventLoop();
transportAssert($loop->runFleetEvents(150.0), 'Межсистемный участок должен завершиться.');
transportAssert(
    $loop->sentAccounts === [1000, 2000],
    'Смена межсистемного участка должна обновлять отправителя и получателя.'
);
transportAssert(
    (int)FleetCommands::getActiveCommand(1)['command_type'] === FleetCommandType::MOVE_IN_SYSTEM->value,
    'Push должен происходить после активации нового внутрисистемного участка.'
);
echo "OK transition pushes sender and recipient after next segment activation\n";

FleetCommands::addCommand(1, FleetCommandType::MOVE_IN_SYSTEM, [
    'target' => Coordinate::point(1, 10, 1000.0, 0.0)->toArray(),
    'speedFactor' => 10.0,
    'returnLeg' => true,
]);
$outbound = FleetCommands::getActiveCommand(1);
$loop->sentAccounts = [];
transportAssert($loop->runFleetEvents((float)$outbound['execute_time']), 'Переход к возврату должен завершиться.');
transportAssert($fleet->isReturn(), 'Следующая команда должна активировать обратное плечо.');
transportAssert($loop->sentAccounts === [1000, 2000], 'При начале возврата прежний получатель должен получить обновление.');
$viewer = new AccountData(2000);
$page = new FleetsPage(new \SPGame\Core\Message());
transportAssert($page->buildVisibleFlyingFleetList($viewer) === [], 'Snapshot при возврате должен убрать чужой входящий флот.');
echo "OK return activation pushes removal to former recipient\n";

$return = FleetCommands::getActiveCommand(1);
$loop->sentAccounts = [];
transportAssert($loop->runFleetEvents((float)$return['execute_time']), 'Обратный участок должен завершиться.');
transportAssert($loop->sentAccounts === [1000], 'Уже скрытый обратный рейс должен обновлять только владельца.');
echo "OK later return segment only updates owner\n";

installFleetPushFixture(true);
$loop = new FleetPushEventLoop();
transportAssert($loop->runFleetEvents(150.0), 'Повреждённая MOVE-команда не должна останавливать мировой tick.');
transportAssert($loop->sentAccounts === [], 'Ошибка выполнения не должна выдавать snapshot успешной команды.');
transportAssert(FleetCommands::getActiveCommand(1) !== null, 'Ошибка не должна удалять активную команду.');
echo "OK failed command does not emit successful push\n";

$fleet = installFleetPushFixture();
$fleet->setFuelCurrent(0.0);
$fleet->save();
$loop = new FleetPushEventLoop();
transportAssert($loop->runFleetEvents(150.0), 'Недостаток топлива завершает участок и ставит следующий на пассивную паузу без остановки scheduler.');
transportAssert($loop->sentAccounts === [1000, 2000], 'Пассивная остановка должна обновить обоих игроков.');
transportAssert(FleetCommands::getActiveCommand(1) === null && FleetCommands::getNextPendingCommand(1) !== null,
    'Не создаётся фиктивный переход без топлива.');
$owner = new AccountData(1000);
$page = new FleetsPage(new \SPGame\Core\Message());
$paused = $page->buildFlyingFleetList($owner);
transportAssert(isset($paused[1]) && $paused[1]['blocked_reason'] !== null && $paused[1]['mission_end_time'] === null,
    'Остановившийся флот виден владельцу без фиктивного времени прибытия.');
transportAssert($fleet->getFuelCurrent() === 0.0, 'Обновление отображения не выдаёт флоту топливо.');
echo "OK fuel shortage refreshes paused route without free fuel or scheduler failure\n";

Connect::setGalaxyView(1, 1, 8);
Connect::setGalaxyView(2, 2, 3);
transportAssert(Connect::getGalaxyView(1) === ['galaxy' => 1, 'system' => 8], 'Первая карта должна сохраниться.');
transportAssert(Connect::getGalaxyView(2) === ['galaxy' => 2, 'system' => 3], 'Контекст второго клиента должен быть независимым.');
Connect::setGalaxyView(1, 0, 0);
transportAssert(Connect::getGalaxyView(1) === null, 'Переключение планеты должно сбросить контекст карты.');
transportAssert(Connect::getGalaxyView(2) === ['galaxy' => 2, 'system' => 3], 'Сброс не должен менять другой клиент.');
echo "OK galaxy view is scoped to connection and can be reset\n";
