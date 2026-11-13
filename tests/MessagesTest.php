<?php

declare(strict_types=1);

namespace Swoole {
    if (!class_exists(Table::class)) {
        /** Только in-memory double для запуска узкого теста без расширения Swoole. */
        class Table implements \IteratorAggregate
        {
            public const TYPE_INT = 1, TYPE_FLOAT = 2, TYPE_STRING = 3;
            private array $rows = [];
            private array $stringLimits = [];
            public ?string $failOnceKey = null;
            public function __construct(int $size) {}
            public function column(string $name, int $type, int $size = 0): void
            {
                if ($type === self::TYPE_STRING) $this->stringLimits[$name] = $size;
            }
            public function create(): bool { return true; }
            public function get(string $key): array|false { return $this->rows[$key] ?? false; }
            public function exists(string $key): bool { return isset($this->rows[$key]); }
            public function set(string $key, array $row): bool
            {
                if ($this->failOnceKey === $key) { $this->failOnceKey = null; return false; }
                foreach ($this->stringLimits as $name => $limit) {
                    if (isset($row[$name])) $row[$name] = substr((string)$row[$name], 0, $limit);
                }
                $this->rows[$key] = array_replace($this->rows[$key] ?? [], $row);
                return true;
            }
            public function del(string $key): bool { unset($this->rows[$key]); return true; }
            public function incr(string $key, string $column, int $amount): int
            {
                return $this->rows[$key][$column] += $amount;
            }
            public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
        }
    }
}

namespace {
    require __DIR__ . '/bootstrap.php';

    use SPGame\Core\{Logger, Message};
    use SPGame\Game\Pages\MessagesPage;
    use SPGame\Game\Repositories\{Messages, PlayerQueue};
    use SPGame\Game\Services\{AccountData, Notification, PageActionService};
    use Swoole\Table;

    /** Настоящие repositories в изолированной памяти процесса; init()/MySQL не вызываются. */
    function installMessagesRepository(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        $schema = $reflection->getProperty('tableSchema')->getValue();
        $table = new Table(1024);
        foreach ($schema['columns'] as $name => $column) {
            $table->column($name, $column['swoole'][0], $column['swoole'][1] ?? 0);
        }
        $table->create();
        $sync = new Table(1024);
        foreach (['need_update' => 1, 'need_delete' => 1, 'last_id' => 8] as $name => $size) {
            $sync->column($name, Table::TYPE_INT, $size);
        }
        $sync->create();
        $sync->set('meta', ['need_update' => 0, 'need_delete' => 0, 'last_id' => 0]);
        $indexes = [];
        foreach ($reflection->getProperty('indexes')->getValue() as $name => $_) {
            $index = new Table(1024);
            $index->column('id', Table::TYPE_INT, 8);
            $index->column('ids', Table::TYPE_STRING, 2048);
            $index->create();
            $indexes[$name] = $index;
        }
        foreach (['table' => $table, 'syncTable' => $sync, 'indexTables' => $indexes,
            'logger' => Logger::getInstance()] as $name => $value) {
            $reflection->getProperty($name)->setValue(null, $value);
        }
    }

    final class MessagesTestAccount extends AccountData
    {
        private array $values = ['User' => ['id' => 100], 'Planet' => ['id' => 10]];
        public function __construct() {}
        public function &offsetGet(mixed $offset): mixed { return $this->values[$offset]; }
    }

    function messagesCheck(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }

    installMessagesRepository(Messages::class);
    installMessagesRepository(PlayerQueue::class);
    $account = new MessagesTestAccount();
    $page = (new MessagesPage(new Message()))->render($account);
    messagesCheck($page['allMessages'] === [] && $page['deleteBatchSize'] === 10, 'Пустой mailbox должен быть массивом.');

    $own = Messages::add(['to_id' => 100, 'type' => 2, 'time' => 200]);
    $other = Messages::add(['to_id' => 200, 'type' => 2, 'time' => 100]);
    $ownRead = Messages::add(['to_id' => 100, 'type' => 1, 'time' => 300, 'read' => 1]);
    messagesCheck(Notification::NewMessages(100) === 1, 'Непрочитанные берутся из runtime index.');
    messagesCheck(array_column(Notification::getAllMessages(100), 'id') === [$ownRead, $own], 'Новые сообщения идут первыми.');
    messagesCheck(Notification::normalizeMessageIds([$own, (string)$own]) === [$own], 'Повторяющиеся ID нормализуются.');
    $largestBatch = ['Ids' => array_fill(0, Notification::MESSAGE_BATCH_LIMIT, PHP_INT_MAX)];
    messagesCheck(strlen(serialize($largestBatch)) < 512, 'Пачка обязана помещаться в PlayerQueue.data.');

    foreach ([null, 1, [], [0], [-1], [true], [1.5], [null], [[]], ['x'],
        ['999999999999999999999999'], ['id' => $own], array_fill(0, 11, $own), [$other], [$own, $other], [99999]] as $ids) {
        $request = (new Message())->setMode('messages')->setAction('delete')->setData('Ids', $ids);
        $response = (new Message())->setRequestId('messages-test');
        messagesCheck(PageActionService::handle($request, $account, 1000, $response), 'Delete должен вернуть обычный ACK/error.');
        messagesCheck($response->getStatus() === 'error' && $response->source()['requestId'] === 'messages-test', 'Некорректный/чужой ID должен получить correlated error.');
        messagesCheck(PlayerQueue::getByAccount(1000) === null, 'Отклонённая команда не должна попадать в PlayerQueue.');
        messagesCheck(Messages::findById($own) !== null && Messages::findById($other) !== null, 'Ошибка не удаляет сообщения.');
    }
    echo "OK validation, ownership, requestId and queue payload size\n";

    $request = (new Message())->setMode('messages')->setAction('delete')->setData('Ids', [$own, $ownRead]);
    $response = new Message();
    PageActionService::handle($request, $account, 1000, $response);
    $event = PlayerQueue::getByAccount(1000)[0];
    messagesCheck($response->getStatus() === 'ok' && $event['action'] === PlayerQueue::ActionMessagesDelete,
        'Валидная команда должна быть принята асинхронно.');
    messagesCheck(Messages::findById($own) !== null, 'ACK не должен синхронно удалять сообщение.');
    messagesCheck(Notification::deleteMessages($event['user_id'], $event['data']['Ids']) === 2, 'Исполнение удаляет выбранные сообщения.');
    messagesCheck(Notification::getAllMessages(100) === null && Notification::NewMessages(100) === 0, 'Индексы и счётчик обновляются.');
    messagesCheck(Messages::findById($other) !== null, 'Чужое сообщение сохраняется.');
    messagesCheck(in_array($own, Messages::getPendingSync()['delete'], true), 'Удаление должно сохраняться обычным RepositorySaver.');
    messagesCheck(Notification::deleteMessages(100, [$own, $other, 99999]) === 0, 'Повтор и поддельное событие не должны удалять чужие сообщения.');
    PlayerQueue::delete($event);
    echo "OK asynchronous ACK, execution ownership, indexes, delete markers and idempotency\n";

    $changedOwner = Messages::add(['to_id' => 100, 'type' => 2]);
    $request->setData('Ids', [$changedOwner]);
    $response = new Message();
    PageActionService::handle($request, $account, 1000, $response);
    messagesCheck($response->getStatus() === 'ok', 'Владелец может поставить удаление в очередь.');
    Messages::update(['id' => $changedOwner, 'to_id' => 200]);
    messagesCheck(Notification::deleteMessages(100, [$changedOwner]) === 0 && Messages::findById($changedOwner) !== null,
        'Смена владельца между ACK и исполнением не должна обходить повторную проверку.');
    echo "OK ownership revalidated between acceptance and execution\n";

    $readId = Messages::add(['to_id' => 100, 'type' => 2]);
    Notification::setReadMessages(100, [$readId, $other]);
    messagesCheck(Messages::findById($readId)['read'] === 1 && Messages::findById($other)['read'] === 0,
        'Чтение меняет только сообщения текущего игрока.');
    messagesCheck(Notification::NewMessages(100) === 0, 'Чтение обновляет счётчик непрочитанных.');
    echo "OK read ownership and unread counter\n";

    Messages::setLastId(100000000);
    $many = [];
    for ($i = 0; $i < 320; ++$i) {
        $many[] = Messages::add(['to_id' => 300, 'type' => 23, 'time' => 1000 + $i]);
    }
    messagesCheck(array_column(Notification::getAllMessages(300), 'id') === array_reverse($many), 'Длинный mailbox не теряет сообщения.');
    messagesCheck(Notification::NewMessages(300) === 320, 'Длинный unread index не обрезается.');
    messagesCheck(count(Notification::getTypeMessages(300, Messages::Type::Transport)) === 320, 'Категория читает все части.');
    Messages::add(Messages::findById($many[1]));
    messagesCheck(count(Notification::getAllMessages(300)) === 320, 'Повторный add не дублирует ID.');
    $indexes = (new \ReflectionClass(Messages::class))->getProperty('indexTables')->getValue();
    $index = $indexes['to'];
    $key = md5('300');
    for ($part = 0; $part < 5; ++$part) {
        $row = $index->get($key . ($part === 0 ? '' : ':' . $part));
        messagesCheck($row !== false && strlen($row['ids']) <= 2048 && count(unserialize($row['ids'])) === 64,
            'Каждая из пяти частей содержит64 ID и помещается в2048 байт.');
    }
    if (property_exists($index, 'failOnceKey')) {
        $index->failOnceKey = $key . ':5';
        $addIndex = \Closure::bind(static fn() => Messages::addIndex('to', [300], 999999999), null, Messages::class);
        $rejected = false;
        try { $addIndex(); } catch (\RuntimeException) { $rejected = true; }
        messagesCheck($rejected && !$index->exists($key . ':5'), 'Отказ новой части обнаружен и убран.');
        messagesCheck(array_column(Notification::getAllMessages(300), 'id') === array_reverse($many), 'После отказа сохранён прежний индекс.');
    }
    Notification::setReadMessages(300, [$many[0], $many[63], $many[64], $many[127], $many[319]]);
    messagesCheck(Notification::NewMessages(300) === 315 && count(Notification::getAllMessages(300)) === 320,
        'Чтение на границах частей не теряет mailbox.');
    $deleted = [$many[0], $many[63], $many[64], $many[127], $many[128], $many[319]];
    messagesCheck(Notification::deleteMessages(300, $deleted) === 6, 'Удаляются ID из разных частей.');
    $remaining = array_values(array_diff($many, $deleted));
    messagesCheck(array_column(Notification::getAllMessages(300), 'id') === array_reverse($remaining), 'Остальные ID и порядок сохранены.');
    Messages::update(['id' => $remaining[50], 'to_id' => 301]);
    messagesCheck(count(Notification::getAllMessages(300)) === 313 && count(Notification::getAllMessages(301)) === 1,
        'Обновление ключа переносит ID между группами.');
    Messages::update(['id' => $remaining[50], 'to_id' => 300]);
    foreach (array_chunk($remaining, Notification::MESSAGE_BATCH_LIMIT) as $batch) Notification::deleteMessages(300, $batch);
    messagesCheck(Notification::getAllMessages(300) === null && Notification::NewMessages(300) === 0, 'Пустой mailbox действительно пуст.');
    for ($part = 0; $part < 5; ++$part) messagesCheck(!$index->exists($key . ($part === 0 ? '' : ':' . $part)), 'Пустые части удалены.');
    $index->set(md5('777'), ['id' => 1, 'ids' => 'a:2:{']);
    $rejected = false;
    try { Messages::findByIndex('to', [777]); } catch (\RuntimeException) { $rejected = true; }
    messagesCheck($rejected, 'Повреждённый индекс не выдаётся за пустой mailbox.');
    $index->del(md5('777'));
    echo "OK chunked indexes:320 messages, unread, duplicate, read, delete, owner change, cleanup and failed write\n";
}
