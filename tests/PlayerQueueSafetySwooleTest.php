<?php

declare(strict_types=1);

// Только новые Swoole Tables этого процесса; fixture запрещает любые SQL-вызовы.
define('SP_GAME_RESEARCH_FIXTURE_ONLY', true);
require __DIR__ . '/ResearchResourcesIsolationTest.php';

use SPGame\Core\{Database, Logger};
use SPGame\Game\Repositories\{PlanetBattles, Planets, PlayerQueue};
use SPGame\Game\Services\EventLoop;
use Swoole\{Event, Timer};

final class QueueSafetyLogger extends Logger
{
    public array $errors = [];
    public int $failures = 0;
    public function __construct() {}
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void
    {
        if ($this->failures > 0) { --$this->failures; throw new RuntimeException('fixture logger failure'); }
        $this->errors[] = ['message' => $message, 'context' => $context];
    }
}

final class QueueSafetyLoop extends EventLoop
{
    public array $visited = [];
    public function drain(int $accountId): bool { return $this->processPlayerEvents($accountId); }
    protected function processPlayerEvent(array $event, int $accountId): bool
    {
        $this->visited[] = (int)$event['id'];
        if ($event['action'] === 'fixture-runtime') throw new RuntimeException('fixture runtime failure');
        if ($event['action'] === 'fixture-error') throw new Error('fixture error');
        $result = parent::processPlayerEvent($event, $accountId);
        if ($event['action'] === 'fixture-after-commit') throw new RuntimeException('fixture notification failure');
        return $result;
    }
}

final class QueueSafetyWakeupProbe
{
    public static int $woken = 0;
    public function __wakeup(): void { ++self::$woken; }
}

function queueSafetyFixture(): QueueSafetyLogger
{
    $logger = new QueueSafetyLogger();
    (new ReflectionProperty(Logger::class, 'instance'))->setValue(null, $logger);
    researchFixture();
    return $logger;
}

function queueSafetyRaw(string $payload, string $action = PlayerQueue::ActionRenamePlanet): int
{
    $id = PlayerQueue::addQueue(1, 1, 101, $action, []);
    if (strlen($payload) > 512) {
        \SPGame\Game\Repositories\PlayerQueuePayloads::put(PlayerQueue::findExistingById($id), $payload);
        $payload = serialize(['_payloadId' => $id]);
    }
    PlayerQueue::update(['id' => $id, 'data' => $payload]);
    return $id;
}

researchCase('корректный payload и пустой массив: roundtrip, priority/time, pop и null', static function (): void {
    $logger = queueSafetyFixture();
    $payload = ['items' => [1, 2, ['amount' => 2.5, 'enabled' => false, 'optional' => null]], 'name' => 'Русский текст'];
    $later = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, $payload, 2);
    $first = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, [], 1);
    $second = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, $payload, 1);
    PlayerQueue::update(['id' => $first, 'added_at' => 1.0]);
    PlayerQueue::update(['id' => $second, 'added_at' => 2.0]);
    $events = PlayerQueue::getByAccount(1);
    researchAssert(array_column($events, 'id') === [$first, $second, $later], 'Изменена сортировка.');
    researchAssert($events[0]['data'] === [] && $events[1]['data'] === $payload, 'Изменён корректный payload.');
    foreach ([$first, $second, $later] as $id) researchAssert(PlayerQueue::popByAccount(1)['id'] === $id, 'pop изменил порядок.');
    researchAssert(PlayerQueue::getByAccount(1) === null && PlayerQueue::popByAccount(1) === null, 'Пустая очередь не null.');
    researchAssert($logger->errors === [], 'Корректные данные вызвали error.');
});

researchCase('старое обрезание до 512 байт: ни одного вызова игрового обработчика, соседнее событие выполняется', static function (): void {
    $logger = queueSafetyFixture();
    $raw = serialize(['action' => 'SetFleetCommands', 'plan' => ['steps' => array_fill(0, 3,
        ['kind' => 'mission', 'mission' => 10, 'coordinates' => ['galaxy' => 1, 'system' => 1, 'orbit' => 2],
            'percent' => 50, 'diagnostic' => 'payload-secret-do-not-log'])]]);
    researchAssert(strlen($raw) > 512, 'Fixture не воспроизводит обрезание.');
    $bad = queueSafetyRaw(substr($raw, 0, 512), PlayerQueue::ActionFleetControl);
    $good = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionRenamePlanet, ['Name' => 'После ошибки']);
    $before = researchLedger();
    $loop = new QueueSafetyLoop();
    researchAssert($loop->drain(1), 'Нет обновления после корректного события.');
    researchAssert($loop->visited === [$good] && PlayerQueue::findExistingById($bad) === null, 'Повреждённое событие попало в gameplay.');
    researchAssert(Planets::findExistingById(101)['name'] === 'После ошибки', 'Соседнее событие не выполнено.');
    researchAssert($before === researchLedger(), 'Повреждённое событие изменило ресурсы.');
    researchAssert(count($logger->errors) === 1 && $logger->errors[0]['context']['queueId'] === $bad, 'Нет точного error по ID.');
    researchAssert(!str_contains(json_encode($logger->errors), 'payload-secret'), 'В лог попало содержимое payload.');
    $loop->drain(1);
    researchAssert(count($logger->errors) === 1, 'Повреждённая запись повторяется на следующем tick.');
});

researchCase('false/null/scalar/пустая строка/объекты/рекурсия/глубина отклоняются без wakeup и warning', static function (): void {
    $logger = queueSafetyFixture(); QueueSafetyWakeupProbe::$woken = 0;
    $recursive = []; $recursive['self'] = &$recursive;
    $deep = []; for ($i = 0; $i < 80; ++$i) $deep = ['child' => $deep];
    $payloads = ['', 'not serialized', serialize(false), serialize(null), serialize(42), serialize('text'),
        serialize(new QueueSafetyWakeupProbe()), serialize(['nested' => new QueueSafetyWakeupProbe()]), serialize($recursive), serialize($deep)];
    $before = researchLedger();
    foreach ($payloads as $payload) queueSafetyRaw($payload);
    researchAssert(PlayerQueue::getByAccount(1) === null, 'Некорректный payload принят.');
    researchAssert(PlayerQueue::getAll() === [] && count($logger->errors) === count($payloads), 'Не все повреждённые записи отклонены.');
    researchAssert(QueueSafetyWakeupProbe::$woken === 0 && researchLedger() === $before, 'Десериализация вызвала пользовательский код или gameplay.');
});

researchCase('pop пропускает повреждённую запись, но возвращает и удаляет следующую корректную', static function (): void {
    queueSafetyFixture(); $bad = queueSafetyRaw('broken');
    $good = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, []);
    researchAssert(PlayerQueue::popByAccount(1)['id'] === $good, 'pop вернул повреждённую запись.');
    researchAssert(PlayerQueue::findExistingById($bad) === null && PlayerQueue::getByAccount(1) === null, 'После pop остались записи.');
});

researchCase('локальный обработчик warning восстанавливает внешний error handler', static function (): void {
    queueSafetyFixture(); queueSafetyRaw('a:1:{');
    $calls = 0;
    $handler = static function () use (&$calls): bool { ++$calls; return true; };
    set_error_handler($handler);
    try {
        PlayerQueue::getByAccount(1);
        $observed = set_error_handler(static fn(): bool => true);
        restore_error_handler();
        researchAssert($observed === $handler && $calls === 0, 'Нарушен внешний error handler.');
    } finally { restore_error_handler(); }
});

researchCase('настоящий TypeError игрового обработчика не блокирует следующий event', static function (): void {
    $logger = queueSafetyFixture();
    $bad = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesRead, ['ReadId' => 'не массив']);
    $good = PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionRenamePlanet, ['Name' => 'Живой цикл']);
    $loop = new QueueSafetyLoop(); $loop->drain(1);
    researchAssert($loop->visited === [$bad, $good] && Planets::findExistingById(101)['name'] === 'Живой цикл', 'После TypeError обработка остановилась.');
    researchAssert(PlayerQueue::getByAccount(1) === null && $logger->errors[0]['context']['exception'] === TypeError::class, 'Ошибка не отклонена/не записана.');
});

researchCase('RuntimeException и Error: отклонение один раз, без автоматического повторения', static function (): void {
    $logger = queueSafetyFixture();
    PlayerQueue::addQueue(1, 1, 101, 'fixture-runtime', []);
    PlayerQueue::addQueue(1, 1, 101, 'fixture-error', []);
    PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionRenamePlanet, ['Name' => 'Соседнее']);
    $loop = new QueueSafetyLoop(); $loop->drain(1); $loop->drain(1);
    researchAssert(count($loop->visited) === 3 && count($logger->errors) === 2, 'Ошибочные действия повторяются или блокируют соседей.');
    researchAssert(Planets::findExistingById(101)['name'] === 'Соседнее', 'Не выполнена корректная команда.');
});

researchCase('ошибка после удаления исполненного event не создаёт повторное выполнение', static function (): void {
    $logger = queueSafetyFixture();
    PlayerQueue::addQueue(1, 1, 101, 'fixture-after-commit', []);
    PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, []);
    $loop = new QueueSafetyLoop(); $loop->drain(1); $loop->drain(1);
    researchAssert(count($loop->visited) === 2 && count($logger->errors) === 1, 'Повтор после ошибки уведомления.');
    researchAssert($logger->errors[0]['context']['discarded'] === false, 'Исполненное событие удаляется как новое.');
});

researchCase('штатный запрет действия во время боя остаётся LogicException, а не неожиданной ошибкой', static function (): void {
    $logger = queueSafetyFixture();
    PlanetBattles::store(101, ['id' => 'fixture-battle', 'phase' => 'running']);
    PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionQueueCancel, ['QueueId' => 1]);
    PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, []);
    $before = researchLedger(); $loop = new QueueSafetyLoop(); $loop->drain(1);
    researchAssert(PlayerQueue::getByAccount(1) === null && $before === researchLedger(), 'Guard изменил ресурсы или оставил событие.');
    researchAssert($logger->errors === [], 'Штатный guard попал в catch неожиданной ошибки.');
});

researchCase('исчезнувшая исходная планета сохраняет прежнее поведение отклонения', static function (): void {
    queueSafetyFixture();
    PlayerQueue::addQueue(1, 1, 999, PlayerQueue::ActionRenamePlanet, ['Name' => 'Не создавать']);
    PlayerQueue::addQueue(1, 1, 101, PlayerQueue::ActionMessagesNew, []);
    $loop = new QueueSafetyLoop(); $loop->drain(1);
    researchAssert(Planets::findExistingById(999) === null && PlayerQueue::getByAccount(1) === null, 'Изменено отклонение исчезнувшего объекта.');
});

researchCase('настоящий coroutine Timer: TypeError/RuntimeException, следующий tick и соседний timer продолжаются', static function (): void {
    $logger = queueSafetyFixture();
    $calls = $heartbeat = 0; $timedOut = false; $ids = $cids = [];
    $watchdog = Timer::after(1500, static function () use (&$timedOut): void { $timedOut = true; Timer::clearAll(); });
    $loop = new EventLoop();
    $loop->register(static function (int $id) use (&$calls, &$ids, &$cids): void {
        ++$calls; $ids[] = $id; $cids[] = Swoole\Coroutine::getCid();
        if ($calls === 1) throw new TypeError('fixture timer type error');
        if ($calls === 2) throw new RuntimeException('fixture timer runtime error');
        Timer::clear($id);
    }, 5);
    $loop->register(static function (int $id) use (&$calls, &$heartbeat, $watchdog): void {
        ++$heartbeat;
        if ($calls >= 3 && $heartbeat >= 3) { Timer::clear($id); Timer::clear($watchdog); }
    }, 7);
    $loop->start(); Event::wait();
    researchAssert(!$timedOut && $calls === 3 && $heartbeat >= 3, 'Timer остановился после исключения.');
    researchAssert(count(array_unique($ids)) === 1 && min($cids) > 0, 'Не сохранён ID timer или тест прошёл без coroutine.');
    researchAssert(count($logger->errors) === 2, 'Не записаны обе ошибки timer.');
});

researchCase('ошибка Logger в защитном Timer уходит в fallback, следующий tick жив', static function (): void {
    $logger = queueSafetyFixture(); $logger->failures = 1;
    $calls = 0; $timedOut = false;
    $watchdog = Timer::after(1500, static function () use (&$timedOut): void { $timedOut = true; Timer::clearAll(); });
    $loop = new EventLoop();
    $loop->register(static function (int $id) use (&$calls, $watchdog): void {
        if (++$calls === 1) throw new Error('fixture original timer failure');
        Timer::clear($id); Timer::clear($watchdog);
    }, 5);
    $loop->start(); Event::wait();
    researchAssert(!$timedOut && $calls === 2 && $logger->failures === 0, 'Сбой Logger вышел из callback.');
});

restore_error_handler();
echo "RESULT PlayerQueue safety: $passed passed / $failed failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
