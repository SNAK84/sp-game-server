<?php

declare(strict_types=1);

// Только новые native Tables; fixture запрещает SQL и не подключается к игре.
define('SP_GAME_PLAN_FIXTURE_ONLY', true);
require __DIR__ . '/FleetPlanSwooleTest.php';

use SPGame\Core\{Database, Logger, Message};
use SPGame\Game\Repositories\{FleetCommands, Fleets, PlayerQueue, PlayerQueuePayloads as Payloads, Planets};
use SPGame\Game\Services\{EventLoop, FleetControlService, PageActionService};
use Swoole\Table;

\SPGame\Core\Connect::init(64); // Пустые подключения этого процесса, без настоящего WebSocket.

final class PayloadTestLoop extends EventLoop
{
    public function drain(int $accountId): bool { return $this->processPlayerEvents($accountId); }
}

function payloadTable(string $property): Table
{
    return (new ReflectionProperty(Payloads::class, $property))->getValue();
}

function payloadLarge(): array { return ['text' => str_repeat('p', 600)]; }

function payloadSized(int $bytes): array
{
    $data = ['text' => ''];
    for ($i = 0; $i < 5; ++$i) {
        $delta = $bytes - strlen(serialize($data));
        if ($delta === 0) return $data;
        $data['text'] = str_repeat('x', strlen($data['text']) + $delta);
    }
    throw new RuntimeException('Не удалось подготовить payload заданного размера.');
}

function payloadExpectFailure(callable $call): void
{
    try { $call(); }
    catch (LogicException|UnexpectedValueException $error) { return; }
    throw new RuntimeException('Ожидаемый отказ не произошёл.');
}

function payloadPlan(array $ownerAndFleet): array
{
    [, $fleet] = $ownerAndFleet;
    return ['version' => FleetControlService::state($fleet)['version'],
        'steps' => [nativeMission(10, 2, ['percent' => 50]), nativeMission(10, 3, ['percent' => 50]), ['kind' => 'return']],
        'fuel' => 10000, 'resources' => [901 => 1000]];
}

function payloadEnqueuePlan(array &$ownerAndFleet, array $plan): Message
{
    [$owner, $fleet] = $ownerAndFleet;
    $message = new Message(['mode' => 'fleets', 'action' => 'SetFleetCommands', 'requestId' => 'payload-plan-ack',
        'data' => ['fleetId' => $fleet->getId(), 'plan' => $plan]]);
    $response = (new Message())->setRequestId('payload-plan-ack');
    researchAssert(PageActionService::handle($message, $owner, 1, $response), 'Запрос не обработан.');
    return $response;
}

researchCase('маленький payload остаётся inline, большой — ссылка до 512 байт; повторное чтение не потребляет данные', static function (): void {
    researchFixture();
    $small = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, ['value' => 1]);
    researchAssert(PlayerQueue::findExistingById($small)['data'] === serialize(['value' => 1]) && Payloads::count() === 0, 'Малое событие попало в буфер.');
    $large = payloadLarge();
    $id = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, $large);
    $row = PlayerQueue::findExistingById($id);
    researchAssert(strlen($row['data']) <= 512 && unserialize($row['data']) === ['_payloadId' => $id], 'В очереди остался полный payload.');
    researchAssert(Payloads::count() === 1, 'Не создан временный payload.');
    for ($i = 0; $i < 2; ++$i) researchAssert(PlayerQueue::getByAccount(1)[1]['data'] === $large, 'Чтение потребило/исказило payload.');
    PlayerQueue::popByAccount(1); $event = PlayerQueue::popByAccount(1);
    researchAssert($event['data'] === $large && Payloads::count() === 0 && PlayerQueue::getByAccount(1) === null, 'pop не освободил payload.');
});

researchCase('12 этапов: ACK с requestId, EventLoop применяет весь план, затем освобождает буфер', static function (): void {
    $fixture = nativePlanFixture(); [$owner, $fleet] = $fixture;
    $plan = payloadPlan($fixture); $plan['steps'] = array_fill(0, 11, nativeMission(10, 2, ['percent' => 10])); $plan['steps'][] = ['kind' => 'return'];
    $before = researchLedger(); $response = payloadEnqueuePlan($fixture, $plan);
    researchAssert($response->getStatus() === 'ok' && $response->source()['requestId'] === 'payload-plan-ack', 'Нет коррелированного ACK.');
    researchAssert($fleet->getStatus() === 0 && $before === researchLedger() && FleetCommands::getFleetQueue(1) === [], 'ACK применил игровой план.');
    researchAssert(Payloads::count() === 1 && strlen(array_values(PlayerQueue::getAll())[0]['data']) < 512, 'Нет компактной ссылки.');
    (new PayloadTestLoop())->drain(1); $fleet->reloadFromRuntime();
    researchAssert(count(FleetControlService::state($fleet)['steps']) === 12 && $fleet->getResources()[901]->getCount() === 1000.0, 'EventLoop применил не весь план/неверную погрузку.');
    researchAssert(Payloads::count() === 0 && PlayerQueue::getByAccount(1) === null, 'После commit остался payload.');
    nativePlanFinish($owner, $fleet);
});

researchCase('граница 512/513 байт: inline и ссылка восстанавливают данные без обрезания', static function (): void {
    researchFixture();
    $small = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadSized(512));
    researchAssert(strlen(PlayerQueue::findExistingById($small)['data']) === 512 && Payloads::count() === 0, 'Ровно 512 байт не остались inline.');
    $large = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadSized(513));
    researchAssert(strlen(PlayerQueue::findExistingById($large)['data']) < 512 && Payloads::count() === 1, '513 байт не вынесены в буфер.');
    $events = PlayerQueue::getByAccount(1);
    researchAssert($events[0]['data'] === payloadSized(512) && $events[1]['data'] === payloadSized(513), 'Граничный payload изменён.');
});

researchCase('до публикации событие не исполняется и не считается повреждённым', static function (): void {
    researchFixture();
    $id = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
    PlayerQueue::update(['id' => $id, 'ready' => 0]);
    researchAssert(PlayerQueue::getByAccount(1) === null && Payloads::count() === 1
        && PlayerQueue::findExistingById($id) !== null, 'Незавершённый enqueue обработан/удалён.');
    PlayerQueue::update(['id' => $id, 'ready' => 1]);
    researchAssert(PlayerQueue::getByAccount(1)[0]['data'] === payloadLarge(), 'Опубликованное событие недоступно.');
});

researchCase('устаревшая версия: прежний маршрут и ресурсы не меняются, payload удаляется', static function (): void {
    $fixture = nativePlanFixture(); [, $fleet] = $fixture;
    payloadEnqueuePlan($fixture, payloadPlan($fixture));
    $unrelated = FleetControlService::add($fleet->getId(), \SPGame\Game\Enums\FleetCommandType::STAY, ['at' => $fleet->getCurrentCoordinate()->toArray()]);
    $before = FleetCommands::getFleetCommandChain(1); $resources = researchLedger(); $row = Fleets::getById(1);
    (new PayloadTestLoop())->drain(1);
    researchAssert($before === FleetCommands::getFleetCommandChain(1) && $resources === researchLedger() && $row === Fleets::getById(1), 'Отклонённый план изменил состояние.');
    researchAssert(Payloads::count() === 0 && PlayerQueue::getByAccount(1) === null, 'Отклонённый план утёк в буфере.');
});

researchCase('потерянный и просроченный payload не выполняются; соседнее событие работает', static function (): void {
    foreach (['missing', 'expired'] as $failure) {
        researchFixture();
        $id = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionRenamePlanet, ['Name' => 'Нельзя', 'padding' => str_repeat('x', 600)]);
        $row = PlayerQueue::findExistingById($id);
        if ($failure === 'missing') Payloads::release($row);
        else payloadTable('table')->set((string)$id, ['expires_at' => 1.0]);
        PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionRenamePlanet, ['Name' => 'После отказа']);
        (new PayloadTestLoop())->drain(1);
        researchAssert(Planets::findExistingById(101)['name'] === 'После отказа' && Payloads::count() === 0 && PlayerQueue::getByAccount(1) === null, 'Просроченный план исполнен или сосед заблокирован.');
    }
});

researchCase('TTL очищает ссылки offline-игрока и payload, но не маленькие события', static function (): void {
    researchFixture();
    $id = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
    $small = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, []);
    payloadTable('table')->set((string)$id, ['expires_at' => 1.0]);
    PlayerQueue::deleteExpiredPayloads(microtime(true));
    researchAssert(Payloads::count() === 0 && PlayerQueue::findExistingById($id) === null && PlayerQueue::findExistingById($small) !== null, 'TTL изменил чужое событие/оставил ссылку.');
    researchAssert(payloadTable('accounts')->count() === 0, 'TTL не освободил квоту аккаунта.');
});

researchCase('ссылка привязана к ID события, account/user/planet/action; чужой payload не удаляется', static function (): void {
    foreach (['id', 'account_id', 'user_id', 'planet_id', 'action'] as $field) {
        researchFixture();
        $id = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
        $row = PlayerQueue::findExistingById($id); $wrong = $row;
        $wrong[$field] = $field === 'action' ? 'other' : $row[$field] + 1;
        payloadExpectFailure(static fn() => Payloads::read($wrong));
        Payloads::release($wrong);
        researchAssert(Payloads::count() === 1 && Payloads::read($row) === serialize(payloadLarge()), 'Чужое событие прочитало/удалило payload.');
    }
    $id2 = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, []);
    PlayerQueue::update(['id' => $id2, 'data' => serialize(['_payloadId' => $id])]);
    researchAssert(count(PlayerQueue::getByAccount(1)) === 1 && Payloads::count() === 1, 'Поддельная ссылка принята или удалила оригинал.');
});

researchCase('квота аккаунта: отказ без записи; delete и retry освобождают место', static function (): void {
    researchFixture(); $ids = [];
    for ($i = 0; $i < Payloads::MAX_PER_ACCOUNT; ++$i) $ids[] = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
    $before = PlayerQueue::getAll();
    payloadExpectFailure(static fn() => PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge()));
    researchAssert($before === PlayerQueue::getAll() && Payloads::count() === Payloads::MAX_PER_ACCOUNT, 'Отказ создал запись/утечку.');
    PlayerQueue::delete(PlayerQueue::findExistingById($ids[0]));
    PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
    researchAssert(Payloads::count() === Payloads::MAX_PER_ACCOUNT, 'После освобождения места retry не работает.');
});

researchCase('PageAction при заполненном буфере возвращает error с requestId без изменения маршрута', static function (): void {
    $fixture = nativePlanFixture(); [, $fleet] = $fixture;
    for ($i = 0; $i < Payloads::MAX_PER_ACCOUNT; ++$i) PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
    $before = PlayerQueue::getAll(); $resources = researchLedger(); $chain = FleetCommands::getFleetCommandChain(1);
    $response = payloadEnqueuePlan($fixture, payloadPlan($fixture));
    researchAssert($response->getStatus() === 'error' && $response->source()['requestId'] === 'payload-plan-ack', 'Переполнение не вернуло коррелированный error.');
    researchAssert($before === PlayerQueue::getAll() && $resources === researchLedger() && $chain === FleetCommands::getFleetCommandChain(1), 'Отказ изменил игровой state.');
    (new PayloadTestLoop())->drain(1);
    $response = payloadEnqueuePlan($fixture, payloadPlan($fixture));
    researchAssert($response->getStatus() === 'ok' && Payloads::count() === 1, 'Повтор после освобождения буфера не принят.');
});

researchCase('глобальный лимит 1024, отдельные аккаунты, TTL освобождает всю ёмкость', static function (): void {
    researchFixture();
    for ($i = 1; $i <= Payloads::MAX_PENDING; ++$i) {
        Payloads::put(['id' => $i, 'account_id' => $i, 'user_id' => $i, 'planet_id' => 101, 'action' => 'test'], serialize(payloadLarge()));
    }
    researchAssert(Payloads::count() === Payloads::MAX_PENDING, 'Буфер не вмещает заявленный лимит.');
    payloadExpectFailure(static fn() => PlayerQueue::addQueue(9000, 9000, 101, PlayerQueue::ActionMessagesNew, payloadLarge()));
    researchAssert(PlayerQueue::getAll() === [] && Payloads::count() === Payloads::MAX_PENDING, 'Переполнение создало event/перезаписало payload.');
    Payloads::deleteExpired(PHP_FLOAT_MAX);
    researchAssert(Payloads::count() === 0 && payloadTable('accounts')->count() === 0, 'Очистка утекла по памяти/квотам.');
    PlayerQueue::addQueue(9000, 9000, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
});

researchCase('два отдельных процесса используют общий буфер и не превышают квоту одного аккаунта', static function (): void {
    researchFixture(); $workers = [];
    for ($worker = 0; $worker < 2; ++$worker) {
        $process = new Swoole\Process(static function (Swoole\Process $process) use ($worker): void {
            $accepted = $rejected = 0;
            try {
                for ($i = 0; $i < Payloads::MAX_PER_ACCOUNT; ++$i) {
                    try {
                        Payloads::put(['id' => 100 + $worker * 10 + $i, 'account_id' => 1, 'user_id' => 1,
                            'planet_id' => 101, 'action' => 'test'], serialize(payloadLarge()));
                        ++$accepted;
                    } catch (LogicException) { ++$rejected; }
                }
                $process->write(json_encode(['accepted' => $accepted, 'rejected' => $rejected]));
                $process->exit(0);
            } catch (Throwable $error) { $process->write(json_encode(['error' => $error->getMessage()])); $process->exit(1); }
        }, false, SOCK_STREAM, false);
        $process->start(); $workers[] = $process;
    }
    $accepted = $rejected = 0;
    foreach ($workers as $worker) {
        $result = json_decode($worker->read(), true, 512, JSON_THROW_ON_ERROR);
        researchAssert(!isset($result['error']), 'Child process: ' . ($result['error'] ?? ''));
        $accepted += $result['accepted']; $rejected += $result['rejected'];
    }
    for ($i = 0; $i < count($workers); ++$i) { $status = Swoole\Process::wait(); researchAssert($status['code'] === 0, 'Дочерний процесс завершился с ошибкой.'); }
    researchAssert($accepted === Payloads::MAX_PER_ACCOUNT && $rejected === Payloads::MAX_PER_ACCOUNT
        && Payloads::count() === Payloads::MAX_PER_ACCOUNT, 'Буфер не общий или квота превышена конкурентными записями.');
});

researchCase('занятый mutex не блокирует процесс бесконечно; после освобождения повтор работает', static function (): void {
    researchFixture();
    $process = new Swoole\Process(static function (Swoole\Process $process): void {
        $lock = (new ReflectionProperty(Payloads::class, 'lock'))->getValue();
        $lock->lock(); $process->write('locked');
        $process->read(); $lock->unlock(); $process->exit(0);
    }, false, SOCK_STREAM, false);
    $process->start(); researchAssert($process->read() === 'locked', 'Child не занял mutex.');
    $start = microtime(true);
    try {
        try {
            PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
            throw new LogicException('Команда прошла через занятый mutex.');
        } catch (LogicException $error) {
            researchAssert(str_contains($error->getMessage(), 'заблокировать'), 'Неожиданная ошибка mutex.');
        }
        researchAssert(microtime(true) - $start < 2.0 && Payloads::count() === 0 && PlayerQueue::getAll() === [], 'Timeout оставил event/payload или не ограничен.');
        $small = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, []);
        $startSmall = microtime(true);
        researchAssert(PlayerQueue::popByAccount(1)['id'] === $small && microtime(true) - $startSmall < .5, 'Маленькое событие зависит от занятого mutex буфера.');
    } finally { $process->write('release'); $status = Swoole\Process::wait(); researchAssert($status['code'] === 0, 'Child не освободил mutex.'); }
    PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
    researchAssert(Payloads::count() === 1, 'Повтор после освобождения mutex не работает.');
});

researchCase('лимит байт проверяется до ID и записи, включая ровно 16 КиБ', static function (): void {
    researchFixture();
    $exact = payloadSized(Payloads::MAX_BYTES);
    researchAssert(strlen(serialize($exact)) === Payloads::MAX_BYTES, 'Неверный размер граничной fixture.');
    PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, $exact);
    $lastId = PlayerQueue::getLastId(); $before = PlayerQueue::getAll(); $exact['text'] .= 'x';
    payloadExpectFailure(static fn() => PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, $exact));
    researchAssert(PlayerQueue::getLastId() === $lastId && PlayerQueue::getAll() === $before && Payloads::count() === 1, 'Переполнение изменило очередь.');
});

researchCase('ошибка основной Table после сохранения payload: rollback освобождает буфер и позволяет retry', static function (): void {
    researchFixture();
    $original = (new ReflectionProperty(PlayerQueue::class, 'table'))->getValue();
    $small = new Table(64); $small->column('id', Table::TYPE_INT, 4); $small->column('data', Table::TYPE_STRING, 1); $small->create();
    (new ReflectionProperty(PlayerQueue::class, 'table'))->setValue(null, $small);
    // Native Swoole обрежет строку; read-back обязан обнаружить неуспешную запись.
    try {
        payloadExpectFailure(static fn() => PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge()));
        researchAssert(Payloads::count() === 0 && payloadTable('accounts')->count() === 0 && PlayerQueue::getAll() === [], 'После failed enqueue остался payload.');
    } finally { (new ReflectionProperty(PlayerQueue::class, 'table'))->setValue(null, $original); $GLOBALS['researchWarnings'] = []; }
    $id = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
    researchAssert(PlayerQueue::getByAccount(1)[0]['id'] === $id, 'Retry после failed enqueue не работает.');
});

researchCase('повреждённый внешний payload не выполняется и освобождается', static function (): void {
    researchFixture();
    $id = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionRenamePlanet, ['Name' => 'Нельзя', 'padding' => str_repeat('x', 600)]);
    payloadTable('table')->set((string)$id, ['data' => 'broken serialized data']);
    $before = Planets::findExistingById(101);
    (new PayloadTestLoop())->drain(1);
    researchAssert($before === Planets::findExistingById(101) && Payloads::count() === 0 && PlayerQueue::getByAccount(1) === null, 'Повреждённые данные изменили runtime/не очистились.');
});

researchCase('ошибка индекса после записи строки: нет ACK успешного enqueue, строка и payload откатываются', static function (): void {
    researchFixture();
    $property = new ReflectionProperty(PlayerQueue::class, 'indexTables'); $before = $property->getValue();
    $broken = $before; unset($broken['account_id']); $property->setValue(null, $broken);
    try {
        payloadExpectFailure(static fn() => PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge()));
        researchAssert(PlayerQueue::getAll() === [] && Payloads::count() === 0 && payloadTable('accounts')->count() === 0, 'Failed index enqueue оставил event/payload.');
    } finally { $property->setValue(null, $before); }
    PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, payloadLarge());
    researchAssert(count(PlayerQueue::getByAccount(1)) === 1, 'Retry после исправления индекса не работает.');
});

researchCase('сбой добавления игровых команд: rollback маршрута и ресурсов, очистка payload, успешный повтор', static function (): void {
    $fixture = nativePlanFixture(); [$owner, $fleet] = $fixture;
    $plan = payloadPlan($fixture); payloadEnqueuePlan($fixture, $plan);
    $original = (new ReflectionProperty(FleetCommands::class, 'table'))->getValue();
    $table = new Table(64);
    foreach ((new ReflectionProperty(FleetCommands::class, 'tableSchema'))->getValue()['columns'] as $name => $column) {
        $table->column($name, $column['swoole'][0], $name === 'params' ? 8 : ($column['swoole'][1] ?? 8));
    }
    $table->create();
    // addCommand успевает записать обрезанную строку; read-back обнаруживает failure.
    (new ReflectionProperty(FleetCommands::class, 'table'))->setValue(null, $table);
    $before = FleetCommands::getFleetCommandChain(1); $resources = researchLedger(); $row = Fleets::getById(1);
    try {
        (new PayloadTestLoop())->drain(1); $fleet->reloadFromRuntime();
        researchAssert($before === FleetCommands::getFleetCommandChain(1) && $resources === researchLedger() && $row === Fleets::getById(1), 'Failed commit оставил частичный маршрут/ресурсы.');
        researchAssert(Payloads::count() === 0 && PlayerQueue::getByAccount(1) === null, 'Failed commit оставил payload.');
    } finally { (new ReflectionProperty(FleetCommands::class, 'table'))->setValue(null, $original); }
    $response = payloadEnqueuePlan($fixture, $plan); (new PayloadTestLoop())->drain(1); $fleet->reloadFromRuntime();
    researchAssert($response->getStatus() === 'ok' && $fleet->getResources()[901]->getCount() === 1000.0
        && count(FleetControlService::state($fleet)['steps']) === 3 && Payloads::count() === 0, 'Повтор после rollback не работает.');
});

restore_error_handler();
echo "RESULT PlayerQueue payloads: $passed passed / $failed failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
