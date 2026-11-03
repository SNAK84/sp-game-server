<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Defaults;
use SPGame\Core\Database;
use SPGame\Game\Services\RepositorySaver;
use Swoole\Table;

class PlayerQueue extends BaseRepository
{
    public const ActionSelectPlanet     = "ActionSelectPlanet";
    public const ActionRenamePlanet     = "ActionRenamePlanet";
    public const ActionPhalanxScan      = "ActionPhalanxScan";

    public const ActionQueueUpgarde     = "ActionQueueUpgarde";
    public const ActionQueueDismantle   = "ActionQueueDismantle";
    public const ActionQueueCancel      = "ActionQueueCancel";
    public const ActionQueueHangarAdd   = "ActionQueueHangarAdd";
    public const ActionQueueHangarCancel = "ActionQueueHangarCancel";

    public const ActionQueueReCalcTech  = "ActionQueueReCalcTech";
    public const SendActualeData        = "SendActualeData";

    public const ActionMessagesRead     = "ActionMessagesRead";
    public const ActionMessagesNew      = "ActionMessagesNew";
    public const ActionMessagesDelete   = "ActionMessagesDelete";

    public const ActionFleetToOrbit     = "ActionFleetToOrbit";
    public const ActionFleetMoveShip    = "ActionFleetMoveShip";
    public const ActionFleetDisband     = "ActionFleetDisband";
    public const ActionFleetRename      = "ActionFleetRename";
    public const ActionFleetSendMission = "ActionFleetSendMission";
    public const ActionFleetRecallDefend = "ActionFleetRecallDefend";
    public const ActionFleetRecall = "ActionFleetRecall";
    public const ActionFleetGroupInvite = "ActionFleetGroupInvite";
    public const ActionFleetControl = "ActionFleetControl";
    /*
    public const ActionFleetSetCommands = "ActionFleetSetCommands";
    public const ActionFleetDeleteCommand = "ActionFleetDeleteCommand";
    public const ActionFleetGetAvailableMissions = "ActionFleetGetAvailableMissions";*/


    protected static Table $table;
    protected static string $className = 'PlayerQueue';
    protected static string $tableName = "";

    protected static array $tableSchema = [
        'columns' => [
            'id'         => ['swoole' => [Table::TYPE_INT, 4], 'default' => Defaults::AUTOID],
            'account_id' => ['swoole' => [Table::TYPE_INT, 4], 'default' => 0],
            'user_id'    => ['swoole' => [Table::TYPE_INT, 4], 'default' => 0],
            'planet_id'  => ['swoole' => [Table::TYPE_INT, 4], 'default' => 0],
            'action'     => ['swoole' => [Table::TYPE_STRING, 32], 'default' => ''],
            'priority'   => ['swoole' => [Table::TYPE_INT, 1], 'default' => 1],
            'added_at'   => ['swoole' => [Table::TYPE_FLOAT], 'default' => Defaults::MICROTIME],
            // Большие события хранятся отдельно; здесь остаётся только ссылка.
            'data'       => ['swoole' => [Table::TYPE_STRING, 512], 'default' => ''],
            'ready'      => ['swoole' => [Table::TYPE_INT, 1], 'default' => 1],
        ],
        'indexes' => [
            ['name' => 'PRIMARY', 'type' => 'PRIMARY', 'fields' => ['id']],
            ['name' => 'user', 'type' => 'INDEX', 'fields' => ['user_id']],
            ['name' => 'account', 'type' => 'INDEX', 'fields' => ['account_id']],
        ],
    ];

    /** @var array Таблицы индексов Swoole */
    protected static array $indexTables = [];

    /** Индексы Swoole */
    protected static array $indexes = [
        'account_id' => ['key' => ['account_id'], 'Unique' => false],
        'user_id' => ['key' => ['user_id'], 'Unique' => false],
        'planet_id' => ['key' => ['planet_id'], 'Unique' => false]
    ];

    public static function init(?RepositorySaver $saver = null): void
    {
        parent::init($saver);
        PlayerQueuePayloads::init();
    }

    public static function addQueue(int $accountId, int $userId, int $planetId, string $action, array $data = [], int $priority = 1): int
    {
        $payload = serialize($data);
        if (strlen($payload) > PlayerQueuePayloads::MAX_BYTES || array_key_exists('_payloadId', $data)) {
            throw new \InvalidArgumentException('Команда слишком велика для очереди событий.');
        }
        $id = self::nextId();

        $row = [
            'id'         => $id,
            'account_id' => $accountId,
            'user_id'    => $userId,
            'planet_id'  => $planetId,
            'action'     => $action,
            'priority'   => $priority,
            'added_at'   => microtime(true),
            'data'       => $payload,
            // EventLoop не должен забрать событие до проверки строки и индекса.
            'ready'      => 0,
        ];

        return self::publish($row);
    }

    private static function publish(array $row, ?float $payloadTtl = null): int
    {
        // Table::get возвращает колонки в порядке схемы, JSON-снимок — не обязан.
        $row = self::castRowToSchema($row);
        $id = (int)$row['id'];
        $payload = $row['data'];
        $external = strlen($payload) > static::$tableSchema['columns']['data']['swoole'][1];
        if ($external) {
            PlayerQueuePayloads::put($row, $payload, $payloadTtl);
            $row['data'] = serialize(['_payloadId' => $id]);
        }
        try {
            self::$logger->info("PlayerQueue addQueue", $row);
            self::add($row);
            if (self::findExistingById($id) !== $row
                || !in_array($id, array_column(self::findByIndex('account_id', (int)$row['account_id']) ?? [], 'id'), true)) {
                throw new \LogicException('Не удалось поставить команду в очередь событий.');
            }
            $row['ready'] = 1;
            if (!static::$table->set((string)$id, $row)) throw new \LogicException('Не удалось опубликовать событие игрока.');
        } catch (\Throwable $error) {
            try { self::discardUncommittedRow($row); }
            catch (\Throwable $rollback) {
                self::$logger->error('CRITICAL: rollback постановки PlayerQueue не выполнен',
                    ['queueId' => $id, 'message' => $rollback->getMessage(), 'originalMessage' => $error->getMessage()]);
            } finally {
                if ($external) {
                    try { PlayerQueuePayloads::release($row); }
                    catch (\Throwable $rollback) {
                        self::$logger->error('CRITICAL: rollback временного payload не выполнен',
                            ['queueId' => $id, 'message' => $rollback->getMessage(), 'originalMessage' => $error->getMessage()]);
                    }
                }
            }
            throw $error;
        }

        return $id;
    }

    /** Только после остановки workers и успешного финального сохранения мира. */
    public static function savePendingForRestart(): void
    {
        $events = [];
        foreach (self::getAll() as $row) {
            if ((int)$row['ready'] !== 1) continue;
            $event = self::decodeEvent($row);
            if ($event === null) throw new \RuntimeException('Не удалось сохранить повреждённое событие PlayerQueue.');
            $event['payload_ttl'] = null;
            if (strlen(serialize($event['data'])) > 512) {
                $payload = PlayerQueuePayloads::snapshot($row);
                // Время выключенного сервера не расходует остаток TTL буфера.
                $event['payload_ttl'] = $payload['expires_at'] - microtime(true);
                if ($event['payload_ttl'] <= 0) throw new \RuntimeException('Истёк срок ожидания сохраняемой команды.');
            }
            $json = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            self::decodeRestartEvent($json, (int)$event['id']);
            $events[(int)$event['id']] = $json;
        }
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Таблица — только одноразовая передача между штатными запусками,
            // не периодический журнал: иначе возможен replay уже применённых действий.
            if ($db->fetchOne('SELECT id FROM player_queue_restart LIMIT 1 FOR UPDATE') !== null) {
                throw new \LogicException('Предыдущий снимок PlayerQueue не был восстановлен.');
            }
            foreach ($events as $id => $json) {
                $db->query('INSERT INTO player_queue_restart (id, event_data) VALUES (?, ?)', [$id, $json]);
            }
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        self::$logger->info('Ожидающие события PlayerQueue сохранены', ['count' => count($events)]);
    }

    /** До запуска workers. Ошибка восстановления запрещает запуск сервера. */
    public static function restorePendingAfterRestart(): void
    {
        $db = Database::getInstance();
        $db->query('CREATE TABLE IF NOT EXISTS player_queue_restart (
            id INT NOT NULL PRIMARY KEY, event_data MEDIUMBLOB NOT NULL
        ) ENGINE=InnoDB');
        $db->beginTransaction();
        try {
            $events = $db->fetchAll('SELECT id, event_data FROM player_queue_restart ORDER BY id FOR UPDATE');
            if (count($events) > static::$tableSize) throw new \RuntimeException('Снимок PlayerQueue превышает размер очереди.');
            foreach ($events as $saved) {
                $row = self::decodeRestartEvent($saved['event_data'], (int)$saved['id']);
                if (self::findExistingById($row['id']) !== null) throw new \LogicException('Конфликт ID восстанавливаемого события.');
                $ttl = $row['payload_ttl'];
                unset($row['payload_ttl']);
                $row['data'] = serialize($row['data']);
                $row['ready'] = 0;
                self::publish($row, $ttl);
                self::setLastId(max(self::getLastId(), $row['id']));
            }
            // Удаляем только после проверки всех строк/индексов/payload.
            // Ни одного игрового эффекта до commit и запуска EventLoop ещё нет.
            $db->query('DELETE FROM player_queue_restart');
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        self::$logger->info('Ожидающие события PlayerQueue восстановлены', ['count' => count($events)]);
    }

    private static function decodeRestartEvent(string $json, int $id): array
    {
        if (strlen($json) > PlayerQueuePayloads::MAX_BYTES * 6 + 1024) {
            throw new \UnexpectedValueException('Слишком большая запись снимка PlayerQueue.');
        }
        $row = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $fields = ['id', 'account_id', 'user_id', 'planet_id', 'action', 'priority', 'added_at', 'data', 'ready', 'payload_ttl'];
        if (!is_array($row) || count($row) !== count($fields) || array_diff($fields, array_keys($row)) !== []) {
            throw new \UnexpectedValueException('Неверные поля снимка PlayerQueue.');
        }
        foreach (['id', 'account_id', 'user_id', 'planet_id', 'priority', 'ready'] as $field) {
            if (!is_int($row[$field])) throw new \UnexpectedValueException('Неверный тип поля снимка PlayerQueue.');
        }
        if ($row['id'] !== $id || $id <= 0 || $id > 2147483647 || $row['account_id'] <= 0 || $row['user_id'] <= 0
            || $row['planet_id'] < 0 || $row['priority'] < -128 || $row['priority'] > 127 || $row['ready'] !== 1
            || !is_string($row['action']) || $row['action'] === '' || strlen($row['action']) > 32
            || !is_numeric($row['added_at']) || !is_finite((float)$row['added_at']) || $row['added_at'] <= 0
            || !is_array($row['data']) || array_key_exists('_payloadId', $row['data'])) {
            throw new \UnexpectedValueException('Некорректное событие в снимке PlayerQueue.');
        }
        $bytes = strlen(serialize($row['data']));
        $ttl = $row['payload_ttl'];
        if ($bytes > PlayerQueuePayloads::MAX_BYTES || ($bytes <= 512 && $ttl !== null)
            || ($bytes > 512 && (!is_numeric($ttl) || !is_finite((float)$ttl) || $ttl <= 0 || $ttl > PlayerQueuePayloads::TTL_SECONDS))) {
            throw new \UnexpectedValueException('Некорректный payload или TTL снимка PlayerQueue.');
        }
        $row['added_at'] = (float)$row['added_at'];
        $row['payload_ttl'] = $ttl === null ? null : (float)$ttl;
        return array_replace(array_fill_keys($fields, null), $row);
    }

    public static function delete(array $data): void
    {
        $row = self::findExistingById((int)($data['id'] ?? 0));
        parent::delete($data);
        PlayerQueuePayloads::release($row ?? $data);
    }

    public static function deleteExpiredPayloads(float $now): void
    {
        foreach (PlayerQueuePayloads::deleteExpired($now) as $expired) {
            $row = self::findExistingById((int)$expired['id']);
            if ($row !== null && $row['data'] === serialize(['_payloadId' => (int)$expired['id']])
                && array_intersect_key($row, $expired) === $expired) self::delete($row);
            self::$logger->warning('Истёк срок ожидания большого события PlayerQueue', $expired);
        }
    }

    public static function getByAccount(int $accountId): ?array
    {
        $Queues = self::findByIndex('account_id', $accountId);
        if (!$Queues) {
            //self::$logger->info("PlayerQueue not Queues findByIndex");
            return null;
        }
        foreach ($Queues as $key => $Queue) {
            if ((int)($Queue['ready'] ?? 1) !== 1) { unset($Queues[$key]); continue; }
            $event = self::decodeEvent($Queue);
            if ($event === null) unset($Queues[$key]);
            else $Queues[$key] = $event;
        }
        if ($Queues === []) return null;

        // Сортировка по priority и added_at
        usort($Queues, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['added_at'] <=> $b['added_at'];
            }
            return $a['priority'] <=> $b['priority'];
        });

        return $Queues;
    }

    public static function popByAccount(int $accountId): ?array
    {
        $Queues = self::getByAccount($accountId);
        if ($Queues === null) return null;

        // Берём первый элемент
        $Event = $Queues[0];
        self::delete($Event);

        return $Event;
    }

    /** Повреждённое событие нельзя превращать в действие с пустыми параметрами. */
    private static function decodeEvent(array $row): ?array
    {
        $reason = null;
        set_error_handler(static function (int $severity, string $message): never {
            throw new \UnexpectedValueException($message);
        }, E_WARNING | E_NOTICE);
        try {
            $data = unserialize($row['data'], ['allowed_classes' => false, 'max_depth' => 64]);
            if (is_array($data) && array_key_exists('_payloadId', $data)) {
                if ($data !== ['_payloadId' => (int)$row['id']]) throw new \UnexpectedValueException('Некорректная ссылка на временный payload.');
                $payload = PlayerQueuePayloads::read($row);
                $data = unserialize($payload, ['allowed_classes' => false, 'max_depth' => 64]);
            }
            if (!is_array($data)) throw new \UnexpectedValueException('Payload события должен быть массивом.');
            // Запрещены также вложенные объекты; __wakeup/__unserialize не вызываются.
            array_walk_recursive($data, static function (mixed $value): void {
                if (is_object($value) || is_resource($value)) {
                    throw new \UnexpectedValueException('Payload события содержит недопустимый объект.');
                }
            });
        } catch (\Throwable $error) {
            $reason = $error->getMessage();
        } finally {
            restore_error_handler();
        }
        if ($reason !== null) {
            self::$logger->error('Повреждённое событие PlayerQueue отклонено', [
                'queueId' => (int)$row['id'], 'accountId' => (int)$row['account_id'],
                'userId' => (int)$row['user_id'], 'planetId' => (int)$row['planet_id'],
                'action' => $row['action'], 'dataBytes' => strlen($row['data']), 'message' => $reason,
            ]);
            self::delete($row);
            return null;
        }
        $row['data'] = $data;
        return $row;
    }
}
