<?php

declare(strict_types=1);

// Настоящие отдельные Swoole Tables. Fixture запрещает любые SQL-запросы.
define('SP_GAME_RESUME_FIXTURE_ONLY', true);
require __DIR__ . '/FleetResumeCargoSwooleTest.php';

use SPGame\Core\Database;
use SPGame\Game\Repositories\{FleetCommands, Fleets, Messages};
use SPGame\Game\Services\{FleetControlService as Control, Notification};

function fuelNotices(): array
{
    return array_values(array_filter(Messages::getAll(), static fn($row) => $row['sample'] === 'FleetFuelStopped'));
}

class FuelNoticeFailureTable extends \Swoole\Table
{
    public bool $failNext = false;
    public function set(string $key, array $value): bool
    {
        if ($this->failNext) { $this->failNext = false; return false; }
        return parent::set($key, $value);
    }
}

function fuelNoticeFailNextWrite(string $class): FuelNoticeFailureTable
{
    $property = new ReflectionProperty($class, 'table');
    $before = $property->getValue();
    $schema = (new ReflectionProperty($class, 'tableSchema'))->getValue();
    $table = new FuelNoticeFailureTable(128);
    foreach ($schema['columns'] as $name => $column) $table->column($name, ...$column['swoole']);
    $table->create();
    foreach ($before as $row) $table->set((string)$row['id'], $row);
    $property->setValue(null, $table);
    $table->failNext = true;
    return $table;
}

researchCase('fuel notice: отказ Messages/PlayerQueue оставляет retry, без дубля после восстановления', static function (): void {
    foreach ([Messages::class, \SPGame\Game\Repositories\PlayerQueue::class] as $class) {
        [$owner, $fleet] = resumeCargoFixture();
        fuelNoticeFailNextWrite($class);
        Control::notifyFuelStops(1001);
        $command = FleetCommands::getNextPendingCommand(1);
        researchAssert(!isset(Control::params($command)['fuelNotified']), 'После отказа потерян retry.');
        $expected = $class === Messages::class ? 0 : 1;
        researchAssert(count(fuelNotices()) === $expected, 'Неверное число сообщений после отказа.');
        Control::notifyFuelStops(1002); Control::notifyFuelStops(1003);
        researchAssert(count(fuelNotices()) === 1
            && isset(Control::params(FleetCommands::getNextPendingCommand(1))['fuelNotified']), 'Retry не завершился ровно один раз.');
    }
});

researchCase('fuel notice: один эпизод, повтор tick и новые Tables после reload', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $fleet->setName('Караван'); $fleet->save();
    Control::notifyFuelStops(1001);
    Control::notifyFuelStops(1002);
    $messages = fuelNotices();
    researchAssert(count($messages) === 1, 'Повтор tick создал дубликат или нет сообщения.');
    $data = json_decode($messages[0]['data'], true, 512, JSON_THROW_ON_ERROR);
    researchAssert($data['FleetName'] === 'Караван' && $data['ResourcesCargoFuel'][903] > 0
        && str_contains($data['Position'], '[1:1]'), 'Нет имени, позиции или дейтерия.');
    $commands = FleetCommands::getAll();
    foreach ($commands as &$row) $row['create_time'] = round($row['create_time'], 6);
    unset($row);
    researchRepository(FleetCommands::class, $commands);
    $fleet->reloadFromRuntime();
    Control::notifyFuelStops(1003);
    researchAssert(count(fuelNotices()) === 1, 'Reload/точность времени создали повтор.');
});

researchCase('fuel notice: реальный MOVE до исчерпания бака → completion → одно сообщение', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    Control::resume($fleet, $owner, 1001, false, 'available');
    $move = FleetCommands::getActiveCommand(1);
    researchAssert(!empty(Control::params($move)['fuelStop']), 'Не создан ограниченный топливом MOVE.');
    \SPGame\Game\Services\FleetCommandsService::completeCommand($move, $fleet, $owner);
    researchAssert(Control::state($fleet)['reason'] === 'fuel' && FleetCommands::getActiveCommand(1) === null, 'Флот не остановился.');
    Control::notifyFuelStops(2000); Control::notifyFuelStops(2001);
    researchAssert(count(fuelNotices()) === 1, 'Реальный completion не дал ровно одно сообщение.');
});

researchCase('fuel notice: новый эпизод после продолжения, имя сохраняется на момент события', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $fleet->setName('Первое имя'); $fleet->save();
    Control::notifyFuelStops(1001);
    Control::resume($fleet, $owner, 1001, true);
    $fleet->setName('Второе имя'); $fleet->save();
    Control::pause(FleetCommands::getActiveCommand(1), 'fuel');
    Control::notifyFuelStops(1002); Control::notifyFuelStops(1003);
    $data = array_map(static fn($row) => json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR), fuelNotices());
    researchAssert(count($data) === 2 && $data[0]['FleetName'] === 'Первое имя'
        && $data[1]['FleetName'] === 'Второе имя' && $data[0]['FuelEpisode'] !== $data[1]['FuelEpisode'],
        'Новый эпизод потерян или переписано историческое имя.');
});

researchCase('fuel notice: отменённый commit и ручная остановка не создают ложное сообщение', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $command = FleetCommands::getNextPendingCommand(1);
    $params = Control::params($command); unset($params['pause']);
    Control::patchCommand((int)$command['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
    $before = FleetCommands::getFleetCommandChain(1);
    try {
        Control::commit([$fleet], static function () use ($command): void {
            Control::pause(FleetCommands::getCommand((int)$command['id']), 'fuel');
            throw new RuntimeException('injected rollback');
        });
        throw new RuntimeException('Не выполнена failure injection.');
    } catch (RuntimeException $error) {
        researchAssert($error->getMessage() === 'injected rollback', 'Неожиданная ошибка rollback.');
    }
    Control::notifyFuelStops(1001);
    researchAssert(fuelNotices() === [] && FleetCommands::getFleetCommandChain(1) === $before, 'Rollback оставил сообщение/marker.');
    Control::pause(FleetCommands::getCommand((int)$command['id']), 'manual');
    Control::notifyFuelStops(1002);
    researchAssert(fuelNotices() === [], 'Ручная остановка объявлена нехваткой топлива.');
});

researchCase('fuel notice: ошибка marker после сообщения, retry не создаёт дубль и не портит JSON', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    $command = FleetCommands::getNextPendingCommand(1);
    $params = Control::params($command); $params['padding'] = '';
    $params['padding'] = str_repeat('x', 2040 - strlen(json_encode($params, JSON_THROW_ON_ERROR)));
    $encoded = json_encode($params, JSON_THROW_ON_ERROR);
    Control::patchCommand((int)$command['id'], ['params' => $encoded]);
    Control::notifyFuelStops(1001); Control::notifyFuelStops(1002);
    researchAssert(count(fuelNotices()) === 1, 'Ошибка marker дублирует сообщение.');
    researchAssert(FleetCommands::getCommand((int)$command['id'])['params'] === $encoded, 'Переполнение испортило JSON команды.');
    unset($params['padding']);
    Control::patchCommand((int)$command['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
    Control::notifyFuelStops(1003);
    researchAssert(count(fuelNotices()) === 1
        && isset(Control::params(FleetCommands::getCommand((int)$command['id']))['fuelNotified']), 'Retry не завершил доставку.');
});

researchCase('UI actions: база, движение, остановка, ограничения технологий и чужой флот', static function (): void {
    $idleOwner = defendFixture();
    $idle = \SPGame\Game\Services\FleetsData::getInstance()->get(1);
    $idleActions = Control::actions($idle, $idleOwner);
    researchAssert(!$idleActions['StopFleet'] && !$idleActions['ResumeFleet'] && $idleActions['SetFleetCommands'], 'Неверные действия базируемого флота.');
    [$owner, $fleet] = resumeCargoFixture();
    $actions = Control::actions($fleet, $owner);
    researchAssert(!$actions['StopFleet'] && $actions['ResumeFleet'] && $actions['SetFleetCommands'], 'Неверные действия паузы.');
    Control::resume($fleet, $owner, 1001, true);
    $actions = Control::actions($fleet, $owner);
    researchAssert($actions['StopFleet'] && !$actions['ResumeFleet'] && $actions['SetFleetCommands'], 'Неверные действия движения.');
    $owner['Techs']['computer_tech'] = 0; $owner['Techs']['fleet_logistics_tech'] = 0;
    $actions = Control::actions($fleet, $owner);
    researchAssert(!$actions['StopFleet'] && !$actions['SetFleetCommands'], 'UI обходит технологии.');
    $other = new \SPGame\Game\Services\AccountData(2);
    researchAssert(!in_array(true, Control::actions($fleet, $other), true), 'UI разрешает управление чужим флотом.');
});

researchCase('names: fallback и snapshot до удаления исходного флота', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    researchAssert(Notification::fleetName(1, '') === 'Флот #1', 'Нет fallback имени.');
    $fleet->setName('Разведчик'); $fleet->save();
    $name = $fleet->getName();
    $planet = ['name' => 'База', 'galaxy' => 1, 'system' => 1, 'planet' => 1];
    Notification::sendFleetReturned($owner, 1, $planet, 1001);
    $fleet->setName('Новое имя'); $fleet->save();
    Notification::sendFleetReturned($owner, 1, $planet, 1002);
    $messages = array_values(Messages::getAll());
    researchAssert(json_decode($messages[0]['data'], true)['FleetName'] === 'Разведчик'
        && json_decode($messages[1]['data'], true)['FleetName'] === 'Новое имя', 'Общие сообщения не сохраняют историческое имя.');
    Fleets::delete(['id' => 1]);
    researchAssert(Notification::fleetName(1, $name) === 'Разведчик', 'Имя потеряно при удалении флота.');
    $coordinate = ['galaxy' => 1, 'system' => 1, 'orbit' => 2];
    Notification::sendFleetColonizationResult($owner, 1, ['coordinate' => $coordinate,
        'fleetName' => $name, 'success' => false, 'reason' => 'occupied'], 1003);
    Notification::sendFleetSpyResult($owner, 1, ['coordinate' => $coordinate,
        'fleetName' => $name, 'unavailable' => true], 1004);
    foreach (array_slice(array_values(Messages::getAll()), 2) as $message) {
        researchAssert(json_decode($message['data'], true)['FleetName'] === 'Разведчик', 'Удалённый флот потерял имя в результате действия.');
    }
    Control::notifyFuelStops(1001);
    researchAssert(fuelNotices() === [], 'Удалённому флоту отправлено сообщение об остановке.');
});

researchCase('fuel notice: отсутствующий владелец не создаёт default account/планету', static function (): void {
    [$owner, $fleet] = resumeCargoFixture();
    \SPGame\Game\Repositories\Users::delete(['id' => 1]);
    $accounts = \SPGame\Game\Repositories\Accounts::getAll();
    $planets = \SPGame\Game\Repositories\Planets::getAll();
    Control::notifyFuelStops(1001);
    researchAssert(fuelNotices() === [] && \SPGame\Game\Repositories\Accounts::getAll() === $accounts
        && \SPGame\Game\Repositories\Planets::getAll() === $planets, 'Уведомление создало игровые defaults.');
});

researchAssert(Database::$calls === 0, 'Тест обратился к игровой MySQL.');
echo "RESULT fleet fuel notices: $passed passed / $failed failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 ? 1 : 0);
