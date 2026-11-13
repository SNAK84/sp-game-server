<?php

declare(strict_types=1);

define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Game\Repositories\{FleetCommands, Fleets, FleetsShips, Planets, PlanetResources, Messages, Vars};
use SPGame\Game\Services\{AccountData, EventLoop, FleetCommandsService as Commands,
    FleetControlService as Control, FleetLoadingService as Loading, FleetPlanService as Plan};

function loadingFixture(bool $moon = false): array
{
    [, $fleet] = nativePlanFixture();
    Planets::update(['id' => 102, 'owner_id' => 1, 'planet_type' => $moon ? 3 : 1]);
    Planets::update(['id' => 103, 'owner_id' => 1]);
    if ($moon) {
        $anchor = Planets::findExistingById(102);
        Planets::add(array_replace($anchor, ['id' => 104, 'owner_id' => 2, 'planet_type' => 1]));
    }
    return [new AccountData(1), $fleet];
}
function loadingStep(int $orbit = 2, array $settings = [], int $objectType = 1): array
{
    return ['kind' => 'load', 'coordinates' => ['type' => 1, 'galaxy' => 1, 'system' => 1,
        'orbit' => $orbit, 'objectType' => $objectType], 'speedFactor' => 10] + $settings;
}
function loadingStart($owner, $fleet, array $settings = [], int $objectType = 1): array
{
    Control::savePlan($fleet, $owner, ['version' => Control::state($fleet)['version'], 'fuel' => 1000,
        'steps' => [loadingStep(2, $settings, $objectType), ['kind' => 'return']]], 1000);
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    $load = FleetCommands::getActiveCommand(1);
    researchAssert((int)$load['command_type'] === 31, 'LOAD не является отдельным due событием.');
    return $load;
}
function loadingSnapshot($fleet): array
{
    return [Fleets::getById(1), PlanetResources::findExistingById(102), FleetCommands::getFleetCommandChain(1)];
}
class LoadingFailureTable extends \Swoole\Table
{
    public ?Closure $failure = null;
    public function set(string $key, array $value): bool
    {
        if ($this->failure !== null && ($this->failure)($value)) { $this->failure = null; return false; }
        return parent::set($key, $value);
    }
}
function loadingFailure(string $class, ?Closure $failure = null): void
{
    $property = new ReflectionProperty($class, 'table');
    $table = new LoadingFailureTable(128);
    foreach ((new ReflectionProperty($class, 'tableSchema'))->getValue()['columns'] as $name => $column) {
        $table->column($name, ...$column['swoole']);
    }
    $table->create();
    foreach ($property->getValue() as $row) $table->set((string)$row['id'], $row);
    $property->setValue(null, $table);
    $table->failure = $failure ?? static fn() => true;
}

researchCase('load: prepare не резервирует будущие запасы; владелец и режим валидируются', static function (): void {
    [$owner, $fleet] = loadingFixture();
    $before = loadingSnapshot($fleet);
    Control::preparePlan($fleet, $owner, ['version' => Control::state($fleet)['version'],
        'steps' => [loadingStep(2, ['resources' => [901 => 1e10]]), ['kind' => 'return']]]);
    researchAssert(loadingSnapshot($fleet) === $before, 'Prepare изменил мир.');
    foreach ([['fuel' => -1], ['fuel' => INF], ['resources' => [921 => 1]], ['fillResource' => 921], ['shortage' => 'bad']] as $input) {
        defendRejected(static fn() => Loading::settings($input));
    }
    Planets::update(['id' => 102, 'owner_id' => 2]);
    defendRejected(static fn() => Control::preparePlan($fleet, $owner, ['version' => Control::state($fleet)['version'],
        'steps' => [loadingStep(), ['kind' => 'return']]]));
});
researchCase('load: планета — топливо и груз списываются ровно один раз; replay не меняет мир', static function (): void {
    [$owner, $fleet] = loadingFixture();
    $before = PlanetResources::findExistingById(102);
    $load = loadingStart($owner, $fleet, ['fuel' => 500, 'resources' => [901 => 100, 902 => 50, 903 => 25]]);
    researchAssert(PlanetResources::findExistingById(102) === $before, 'Ресурсы зарезервированы до due LOAD.');
    Commands::completeCommand($load, $fleet, $owner);
    $row = PlanetResources::findExistingById(102);
    researchAssert($row['metal'] === $before['metal'] - 100 && $row['crystal'] === $before['crystal'] - 50
        && $row['deuterium'] === $before['deuterium'] - 525, 'Неверное списание.');
    researchAssert($fleet->getResources()[901]->getCount() === 100.0 && $fleet->getResources()[903]->getCount() === 25.0, 'Груз не зачислен.');
    $after = loadingSnapshot($fleet);
    Commands::completeCommand($load, $fleet, $owner);
    researchAssert($after === loadingSnapshot($fleet), 'Stale replay повторил погрузку.');
    nativePlanFinish($owner, $fleet);
    researchAssert($fleet->getFuelCurrent() === 0.0 && $fleet->getResources()[901]->getCount() === 0.0, 'Возврат не выгрузил груз.');
});
researchCase('load: луна — свой склад, не склад планеты; WorkPlanet восстанавливается', static function (): void {
    [$owner, $fleet] = loadingFixture(true);
    $load = loadingStart($owner, $fleet, ['resources' => [902 => 200]], 3);
    $home = PlanetResources::findExistingById(101);
    $work = $owner['WorkPlanet'];
    Commands::completeCommand($load, $fleet, $owner);
    researchAssert(PlanetResources::findExistingById(101) === $home && $fleet->getResources()[902]->getCount() === 200.0
        && $owner['WorkPlanet'] === $work, 'Затронут домашний склад или не восстановлен WorkPlanet.');
});
researchCase('load: строгая нехватка/нулевой запас — пауза без частичного списания и одно уведомление', static function (): void {
    [$owner, $fleet] = loadingFixture();
    PlanetResources::update(['id' => 102, 'metal' => 0, 'deuterium' => 100]);
    $load = loadingStart($owner, $fleet, ['fuel' => 50, 'resources' => [901 => 100]]);
    $before = [PlanetResources::findExistingById(102), $fleet->getFuelCurrent()];
    Commands::completeCommand($load, $fleet, $owner);
    researchAssert($before === [PlanetResources::findExistingById(102), $fleet->getFuelCurrent()]
        && Control::state($fleet)['reason'] === 'load', 'Нехватка оставила частичную погрузку.');
    $version = Control::state($fleet)['version'];
    Control::notifyFuelStops(1001); Control::notifyFuelStops(1002);
    researchAssert(Control::state($fleet)['version'] === $version, 'Доставка уведомления инвалидировала открытый редактор.');
    $notices = array_filter(Messages::getAll(), static fn($row) => $row['sample'] === 'FleetLoadStopped');
    researchAssert(count($notices) === 1, 'Нет сообщения или есть дубль.');
});
researchCase('load: partial — приоритет топлива, затем металл/кристалл/дейтерий из общего склада', static function (): void {
    [$owner, $fleet] = loadingFixture();
    PlanetResources::update(['id' => 102, 'metal' => 10, 'crystal' => 20, 'deuterium' => 100]);
    $load = loadingStart($owner, $fleet, ['fuel' => 80, 'resources' => [901 => 50, 902 => 50, 903 => 50], 'shortage' => 'partial']);
    Commands::completeCommand($load, $fleet, $owner);
    $receipt = Control::params(FleetCommands::getCommand((int)$load['id']))['loaded'];
    researchAssert($receipt['fuel'] === 80 && $receipt['resources'] == [901 => 10, 902 => 20, 903 => 20], 'Неверный приоритет.');
    $row = PlanetResources::findExistingById(102);
    researchAssert($row['metal'] === 0.0 && $row['crystal'] === 0.0 && $row['deuterium'] === 0.0, 'Неверный остаток склада.');
});
researchCase('load: нулевой склад partial продолжает маршрут без выдуманных ресурсов', static function (): void {
    [$owner, $fleet] = loadingFixture();
    PlanetResources::update(['id' => 102, 'metal' => 0, 'crystal' => 0, 'deuterium' => 0]);
    $load = loadingStart($owner, $fleet, ['fuel' => 100, 'resources' => [901 => 100], 'shortage' => 'partial']);
    Commands::completeCommand($load, $fleet, $owner);
    researchAssert(Control::params(FleetCommands::getCommand((int)$load['id']))['loaded']['fuel'] === 0
        && $fleet->getResources()[901]->getCount() === 0.0 && !Control::state($fleet)['paused'], 'Нулевой склад создал груз/паузу.');
});
researchCase('load: fill использует остаток вместимости после бака, кредиты не занимают трюм', static function (): void {
    [$owner, $fleet] = loadingFixture();
    $load = loadingStart($owner, $fleet, ['fuel' => 200, 'fillResource' => 901]);
    $fleet->getResources()[921]->setCount(1e9);
    $free = $fleet->getFreeCapacity(); $stock = PlanetResources::findExistingById(102)['metal'];
    Commands::completeCommand($load, $fleet, $owner);
    researchAssert(abs($fleet->getResources()[901]->getCount() - min($free - 200, $stock)) < 1e-6, 'Неверное заполнение.');
    researchAssert($fleet->getFreeCapacity() >= -.000001 && $fleet->getResources()[921]->getCount() === 1e9, 'Превышена вместимость или потеряны кредиты.');
});
researchCase('load: нехватка вместимости — strict ничего не берёт; partial ограничен фактическим трюмом', static function (): void {
    foreach (['wait', 'partial'] as $policy) {
        [$owner, $fleet] = loadingFixture();
        $load = loadingStart($owner, $fleet, ['fuel' => 200, 'resources' => [901 => 300], 'shortage' => $policy]);
        $fleet->getResources()[902]->setCount($fleet->getFreeCapacity() - 100); $fleet->save();
        $before = PlanetResources::findExistingById(102);
        Commands::completeCommand($load, $fleet, $owner);
        if ($policy === 'wait') researchAssert(PlanetResources::findExistingById(102) === $before, 'Strict списал часть.');
        else researchAssert(PlanetResources::findExistingById(102)['deuterium'] === $before['deuterium'] - 100
            && $fleet->getResources()[901]->getCount() === 0.0, 'Partial не отдал приоритет топливу/превысил вместимость.');
    }
});
researchCase('load: смена владельца, бой или неверная фактическая позиция не списывают ресурсы', static function (): void {
    foreach (['owner', 'position', 'battle'] as $change) {
        [$owner, $fleet] = loadingFixture();
        $load = loadingStart($owner, $fleet, ['resources' => [901 => 100]]);
        if ($change === 'owner') Planets::update(['id' => 102, 'owner_id' => 2]);
        if ($change === 'position') $fleet->setCurrentCoordinate(\SPGame\Game\Space\Coordinate::object(1, 1, 3, \SPGame\Game\Space\ObjectType::PLANET), 1001);
        if ($change === 'battle') \SPGame\Game\Repositories\PlanetBattles::store(102, ['phase' => 'running', 'fleets' => []]);
        $before = PlanetResources::findExistingById(102);
        Commands::completeCommand($load, $fleet, $owner);
        researchAssert(PlanetResources::findExistingById(102) === $before && Control::state($fleet)['reason'] === 'load', 'Недоступная цель изменена: ' . $change);
    }
});
researchCase('load: отказы склада, Fleet и marker — полный rollback, затем retry и replay', static function (): void {
    foreach ([PlanetResources::class, Fleets::class, FleetCommands::class] as $class) {
        [$owner, $fleet] = loadingFixture();
        $load = loadingStart($owner, $fleet, ['fuel' => 100, 'resources' => [901 => 200, 903 => 30]]);
        $before = loadingSnapshot($fleet);
        loadingFailure($class);
        $caught = false;
        try { Commands::completeCommand($load, $fleet, $owner); } catch (RuntimeException $error) { $caught = true; }
        researchAssert($caught && loadingSnapshot($fleet) === $before, 'Rollback неполный: ' . $class);
        Commands::completeCommand($load, $fleet, $owner);
        $after = loadingSnapshot($fleet);
        Commands::completeCommand($load, $fleet, $owner);
        researchAssert(loadingSnapshot($fleet) === $after && $fleet->getResources()[901]->getCount() === 200.0, 'Retry/replay повреждён.');
    }
});
researchCase('load: запасы и состав изменились после prepare — используются фактические значения', static function (): void {
    [$owner, $fleet] = loadingFixture();
    $load = loadingStart($owner, $fleet, ['resources' => [901 => 150000], 'shortage' => 'partial']);
    PlanetResources::update(['id' => 102, 'metal' => 180000]);
    FleetsShips::update(['id' => 1, 'small_cargo_count' => 1]); $fleet->reloadFromRuntime();
    $free = $fleet->getFreeCapacity();
    Commands::completeCommand($load, $fleet, new AccountData(1));
    researchAssert($fleet->getResources()[901]->getCount() === $free
        && PlanetResources::findExistingById(102)['metal'] === 180000 - $free, 'Использована старая вместимость/запас.');
});
researchCase('load: отказ следующего MOVE после commit — receipt предотвращает двойную погрузку', static function (): void {
    [$owner, $fleet] = loadingFixture();
    $load = loadingStart($owner, $fleet, ['fuel' => 100, 'resources' => [901 => 200]]);
    loadingFailure(FleetCommands::class, static fn($row) => (int)$row['command_type'] === 10 && (float)$row['execute_time'] > 0);
    $caught = false;
    try { Commands::completeCommand($load, $fleet, $owner); } catch (RuntimeException) { $caught = true; }
    $retry = FleetCommands::getActiveCommand(1);
    researchAssert($caught && (int)$retry['id'] === (int)$load['id'] && isset(Control::params($retry)['loaded']), 'Нет retry receipt.');
    $before = PlanetResources::findExistingById(102);
    // Имитация загрузки из persistent snapshot: результат не хранится только в PHP object.
    $fleet->reloadFromRuntime();
    Commands::completeCommand($retry, $fleet, new AccountData(1));
    researchAssert(PlanetResources::findExistingById(102) === $before && $fleet->getResources()[901]->getCount() === 200.0
        && (int)FleetCommands::getActiveCommand(1)['command_type'] === 10, 'Повторно списаны ресурсы или маршрут не продолжен.');
});
researchCase('load: отказ следующего MOVE САБ не оставляет группу-призрак; retry не грузит дважды', static function (): void {
    [$owner, $fleet] = loadingFixture();
    Planets::update(['id' => 103, 'owner_id' => 3]);
    researchRepository(\SPGame\Game\Repositories\FleetAttackGroups::class, []);
    Control::savePlan($fleet, $owner, ['version' => Control::state($fleet)['version'], 'fuel' => 1000,
        'steps' => [loadingStep(2, ['resources' => [901 => 100]]), nativeMission(3, 3, ['group' => []]), ['kind' => 'return']]], 1000);
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    $load = FleetCommands::getActiveCommand(1);
    loadingFailure(FleetCommands::class, static fn($row) => (int)$row['command_type'] === 10 && (float)$row['execute_time'] > 0);
    $caught = false;
    try { Commands::completeCommand($load, $fleet, $owner); } catch (RuntimeException) { $caught = true; }
    researchAssert($caught && \SPGame\Game\Repositories\FleetAttackGroups::getAll() === [], 'Сохранена группа после отката активации.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    researchAssert(count(\SPGame\Game\Repositories\FleetAttackGroups::getAll()) === 1
        && $fleet->getResources()[901]->getCount() === 100.0, 'Retry создал дубли.');
});
researchCase('load: пополнение после паузы и Resume без топлива — погрузка вынесена из внешнего commit', static function (): void {
    [$owner, $fleet] = loadingFixture();
    PlanetResources::update(['id' => 102, 'deuterium' => 0]);
    $load = loadingStart($owner, $fleet, ['fuel' => 100]);
    Commands::completeCommand($load, $fleet, $owner);
    $fleet->setFuelCurrent(0); $fleet->save();
    PlanetResources::update(['id' => 102, 'deuterium' => 1000]);
    $owner = new AccountData(1);
    researchAssert(Control::prepareResume($fleet, $owner, 'available', 1100) === 'available', 'Пустой бак блокирует погрузку.');
    Control::resume($fleet, $owner, 1100, false, 'available');
    researchAssert(PlanetResources::findExistingById(102)['deuterium'] === 1000.0, 'Resume списал ресурс внутри внешнего commit.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    researchAssert(PlanetResources::findExistingById(102)['deuterium'] === 900.0, 'Погрузка не повторилась.');
});
researchCase('load: исправленный после паузы план можно продолжить с пустым баком у того же объекта', static function (): void {
    [$owner, $fleet] = loadingFixture();
    $load = loadingStart($owner, $fleet, ['fuel' => 1e10]);
    Commands::completeCommand($load, $fleet, $owner);
    $fleet->setFuelCurrent(0); $fleet->save();
    Control::savePlan($fleet, $owner, ['version' => Control::state($fleet)['version'],
        'steps' => [loadingStep(2, ['fuel' => 100]), ['kind' => 'return']]], 1100);
    researchAssert(Control::state($fleet)['loading_pending'], 'Не распознана погрузка за ручной паузой.');
    Control::resume($fleet, $owner, 1100, false, 'available');
    researchAssert((int)FleetCommands::getActiveCommand(1)['command_type'] === 31, 'Не активирована погрузка.');
    Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
    researchAssert($fleet->getFuelCurrent() > 0, 'Пустой бак не заправился.');
});
researchCase('load: EventLoop актуализирует добычу до due LOAD планеты', static function (): void {
    [$owner, $fleet] = loadingFixture();
    PlanetResources::update(['id' => 102, 'metal' => 0]);
    $load = loadingStart($owner, $fleet, ['fillResource' => 901]);
    $loop = (new ReflectionClass(EventLoop::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($loop, 'processFleetObjectStateAtTime');
    $method->invoke($loop, $load, $fleet, (float)$load['execute_time']);
    $current = PlanetResources::findExistingById(102)['metal'];
    researchAssert($current > 0 && Planets::findExistingById(102)['update_time'] === (float)$load['execute_time'], 'Добыча не доведена до прибытия.');
    Commands::completeCommand($load, $fleet, new AccountData(1));
    researchAssert(abs($fleet->getResources()[901]->getCount() - $current) < 1e-6, 'Добыча потеряна/добавлена дважды.');
});
researchCase('load: ошибка Messages не откатывает паузу; уведомление доставляется повторно без дубля', static function (): void {
    [$owner, $fleet] = loadingFixture();
    $load = loadingStart($owner, $fleet, ['fuel' => 1e10]);
    Commands::completeCommand($load, $fleet, $owner);
    loadingFailure(Messages::class);
    Control::notifyFuelStops(1001);
    researchAssert(!isset(Control::params(FleetCommands::getNextPendingCommand(1))['loadNotified']), 'Потерян retry сообщения.');
    Control::notifyFuelStops(1002); Control::notifyFuelStops(1003);
    researchAssert(count(array_filter(Messages::getAll(), static fn($row) => $row['sample'] === 'FleetLoadStopped')) === 1,
        'Сообщение не восстановилось ровно один раз.');
});
researchCase('load: база → погрузка А → погрузка/заправка Б → выгрузка дома; суммы и история', static function (): void {
    [$owner, $fleet] = loadingFixture();
    $home = PlanetResources::findExistingById(101);
    $allBefore = array_fill_keys([901, 902, 903], 0.0);
    foreach ([101, 102, 103] as $id) foreach ($allBefore as $resource => $unused) {
        $allBefore[$resource] += PlanetResources::findExistingById($id)[Vars::$resource[$resource]];
    }
    Control::savePlan($fleet, $owner, ['version' => Control::state($fleet)['version'], 'fuel' => 1000,
        'steps' => [loadingStep(2, ['resources' => [901 => 100]]),
            loadingStep(3, ['fuel' => 500, 'resources' => [902 => 50, 903 => 25]]), ['kind' => 'return']]], 1000);
    $burned = 0.0; $limit = 30;
    while ($limit-- > 0 && ($active = FleetCommands::getActiveCommand(1)) !== null) {
        if (in_array((int)$active['command_type'], [10, 11, 12], true)) $burned += Control::params($active)['fuelCost'];
        Commands::completeCommand($active, $fleet, $owner);
    }
    researchAssert($limit > 0 && $fleet->getStatus() === 0, 'Маршрут не завершился.');
    $after = PlanetResources::findExistingById(101);
    researchAssert($after['metal'] === $home['metal'] + 100 && $after['crystal'] === $home['crystal'] + 50
        && $fleet->getFuelCurrent() === 0.0, 'Не выгружен промежуточный груз.');
    foreach ($allBefore as $resource => $before) {
        $afterTotal = 0.0;
        foreach ([101, 102, 103] as $id) $afterTotal += PlanetResources::findExistingById($id)[Vars::$resource[$resource]];
        researchAssert(abs($before - $afterTotal - ($resource === 903 ? $burned : 0)) < 1e-6, 'Нарушено сохранение ресурса ' . $resource);
    }
});
researchCase('load: настоящий due scheduler проходит обе погрузки и возврат в хронологическом порядке', static function (): void {
    [$owner, $fleet] = loadingFixture();
    \SPGame\Core\Connect::init(8);
    Control::savePlan($fleet, $owner, ['version' => Control::state($fleet)['version'], 'fuel' => 1000,
        'steps' => [loadingStep(2, ['resources' => [901 => 100]]),
            loadingStep(3, ['fuel' => 500, 'resources' => [902 => 50]]), ['kind' => 'return']]], 1000);
    $loop = (new ReflectionClass(EventLoop::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($loop, 'logger'))->setValue($loop, \SPGame\Core\Logger::getInstance());
    $method = new ReflectionMethod($loop, 'processFleetEvents');
    $limit = 30;
    while ($limit-- > 0 && ($active = FleetCommands::getActiveCommand(1)) !== null) {
        researchAssert($method->invoke($loop, (float)$active['execute_time']), 'Scheduler остановил мировой tick.');
    }
    researchAssert($limit > 0 && FleetCommands::getFleetQueue(1) === [] && $fleet->getStatus() === 0
        && $fleet->getFuelCurrent() === 0.0 && $fleet->getResources()[901]->getCount() === 0.0,
        'Scheduler не завершил LOAD/возврат: ' . json_encode(Control::state($fleet)));
});

restore_error_handler();
echo "RESULT intermediate loading: $passed passed / $failed failed; SQL calls=" . \SPGame\Core\Database::$calls . "\n";
exit($failed > 0 || \SPGame\Core\Database::$calls > 0 ? 1 : 0);
