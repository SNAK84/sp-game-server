<?php

namespace SPGame\Core;

use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Services\CabinetWorldService;
use SPGame\Game\Services\FleetMiningCheckpointService;
use SPGame\Game\Services\ProtocolReadService;
use SPGame\Game\Services\ProtocolCommandService;
use SPGame\Game\Repositories\ProtocolCommands;
use SPGame\Game\Repositories\{ProtocolResources, ProtocolSubscriptions, MiningCheckpoints, Users};
use SPGame\Game\Services\{ProtocolOperations, ProtocolResourceService};

/** Поэтапный v2: negotiation/auth, ресурс планет и первая команда через PlayerQueue. */
final class GameProtocolV2
{
    private static string $epoch = '';

    public static function initialize(): void
    {
        global $saver;
        self::$epoch = bin2hex(random_bytes(16));
        ProtocolCommands::init($saver);
        ProtocolResources::init();
        ProtocolSubscriptions::init();
    }

    public static function epoch(): string { return self::$epoch; }

    private static function commandPacket(int $fd, string $requestId, array $status): array
    {
        return self::packet($fd, $status['state'] === 'accepted' ? 'commandAccepted' : 'commandResult', $requestId,
            ['name' => $status['name'] ?? 'planet.rename', 'data' => $status]);
    }

    public static function sendCommandResult(int $fd, string $requestId, array $status): void
    {
        WSocket::getInstance()?->Send($fd, self::commandPacket($fd, $requestId, $status));
    }

    private static function packet(int $fd, string $type, ?string $requestId, array $extra = []): array
    {
        return ['v' => 2, 'type' => $type, 'requestId' => $requestId,
            'worldId' => CabinetWorldService::worldId(), 'connectionId' => Connect::getConnectionId($fd),
            'serverEpoch' => self::$epoch, 'serverTime' => microtime(true)] + $extra;
    }

    public static function error(int $fd, ?string $requestId, string $code, string $message, bool $fatal = false, bool $retryable = false): array
    {
        return self::packet($fd, 'error', $requestId, ['error' => ['code' => $code, 'message' => $message,
            'fatal' => $fatal, 'retryable' => $retryable]]);
    }

    public static function sessionEnded(int $fd, ?string $requestId = null, bool $loggedOut = false): array
    {
        return self::packet($fd, 'session', $requestId, ['name' => $loggedOut ? 'session.loggedOut' : 'session.ended',
            'data' => ['state' => $loggedOut ? 'loggedOut' : 'ended',
                'message' => 'Игровая сессия завершена. Выберите мир в кабинете.']]);
    }

    /** Схемы в docs/protocol-v2. Никакого fallback v2 → v1 при ошибке. */
    public static function handle(int $fd, string $raw, WSocket $socket): array
    {
        $requestId = null;
        try {
            $message = json_decode($raw, false, 24, JSON_THROW_ON_ERROR);
            if (!$message instanceof \stdClass) return self::error($fd, null, 'protocol.invalid_packet', 'Нужен JSON-объект.');
            if (is_string($message->requestId ?? null) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $message->requestId)) $requestId = $message->requestId;
            if (($message->v ?? null) !== 2) return self::error($fd, $requestId, 'protocol.unsupported_version', 'Поддерживается v2.', true);
            if ($requestId === null) return self::error($fd, null, 'protocol.invalid_request_id', 'Нужен UUID requestId.');
            if (!is_string($message->type ?? null)) return self::error($fd, $requestId, 'protocol.invalid_packet', 'Не указан тип пакета.');
            $allowed = ['v', 'type', 'requestId', 'worldId', 'name', 'context', 'data', 'serverEpoch', 'issuedAt', 'subscriptionId'];
            if (array_diff(array_keys((array)$message), $allowed)) return self::error($fd, $requestId, 'protocol.unknown_field', 'Неизвестное поле пакета.');
            if (property_exists($message, 'data') && !$message->data instanceof \stdClass) return self::error($fd, $requestId, 'protocol.invalid_packet', 'data должен быть объектом.');
            if (property_exists($message, 'context') && !$message->context instanceof \stdClass) return self::error($fd, $requestId, 'protocol.invalid_packet', 'context должен быть объектом.');
            $fields = match ($message->type) {
                'hello', 'auth' => ['v', 'type', 'requestId', 'worldId', 'data'],
                'ping', 'logout' => ['v', 'type', 'requestId', 'worldId'],
                'query' => ['v', 'type', 'requestId', 'worldId', 'name', 'context', 'data'],
                'command' => ['v', 'type', 'requestId', 'worldId', 'name', 'context', 'data', 'serverEpoch', 'issuedAt'],
                'subscribe' => ['v', 'type', 'requestId', 'worldId', 'name', 'context', 'data', 'subscriptionId'],
                'unsubscribe' => ['v', 'type', 'requestId', 'worldId', 'subscriptionId'],
                default => [],
            };
            if ($fields === []) return self::error($fd, $requestId, 'protocol.unsupported_type', 'Этот тип пакета ещё не поддерживается.');
            if (array_diff(array_keys((array)$message), $fields)) return self::error($fd, $requestId, 'protocol.unknown_field', 'Поле не поддерживается этим типом пакета.');
            if (!CabinetWorldService::enabled()) return self::error($fd, $requestId, 'auth.cabinet_required', 'v2 требует входа через кабинет.', true);

            if ($message->type === 'hello') {
                if (Connect::getProtocolVersion($fd) !== 0) return self::error($fd, $requestId, 'protocol.already_negotiated', 'Версия уже выбрана.', true);
                $versions = $message->data->versions ?? null;
                if (!is_array($versions) || !array_is_list($versions) || array_filter($versions, static fn($v) => !is_int($v) || $v < 1)
                    || array_diff(array_keys((array)($message->data ?? new \stdClass())), ['versions']) || !in_array(2, $versions, true)) {
                    return self::error($fd, $requestId, 'protocol.unsupported_version', 'Нет общей версии протокола.', true);
                }
                if (property_exists($message, 'worldId') && $message->worldId !== CabinetWorldService::worldId()) return self::error($fd, $requestId, 'world.mismatch', 'Другой игровой мир.', true);
                Connect::setProtocolVersion($fd, 2);
                return self::packet($fd, 'hello', $requestId, ['data' => ['version' => 2,
                    'capabilities' => ['authentication' => 'cabinet.gameToken', 'queries' => [...array_keys(ProtocolOperations::QUERIES), 'command.status.get'],
                        'commands' => array_keys(ProtocolOperations::COMMANDS), 'subscriptions' => ProtocolOperations::SUBSCRIPTIONS, 'replay' => false,
                        'snapshotOrdering' => 'resource.revision', 'idType' => 'decimal-string']]]);
            }
            if (Connect::getProtocolVersion($fd) !== 2) return self::error($fd, $requestId, 'protocol.hello_required', 'Сначала согласуйте версию.', true);
            if (($message->worldId ?? null) !== CabinetWorldService::worldId()) return self::error($fd, $requestId, 'world.mismatch', 'Разрешение относится к другому миру.');

            if ($message->type === 'auth') {
                if (array_diff(array_keys((array)($message->data ?? new \stdClass())), ['gameToken'])) return self::error($fd, $requestId, 'protocol.unknown_field', 'Нужен только gameToken кабинета.');
                if (Connect::getRawAccount($fd)) return self::error($fd, $requestId, 'auth.already_authenticated', 'Сессия уже авторизована.');
                if (!is_string($message->data->gameToken ?? null) || !preg_match('/^[a-f0-9]{128}$/D', $message->data->gameToken)) {
                    return self::error($fd, $requestId, 'auth.invalid_grant', 'Неверный формат игрового разрешения.', true);
                }
                $identity = CabinetWorldService::authorize($message->data->gameToken, $fd, $socket);
                Connect::setCabinetSessionId($fd, $identity['sessionId']);
                return self::packet($fd, 'authResult', $requestId, ['data' => ['state' => 'authenticated',
                    'accountId' => $identity['accountId'], 'playerId' => (string)$identity['playerId'], 'sessionId' => $identity['sessionId']]]);
            }

            $account = Accounts::findExistingById(Connect::getRawAccount($fd) ?? 0);
            if (!$account || (int)$account['frame'] !== $fd || Connect::getCabinetSessionId($fd) === '') {
                return self::error($fd, $requestId, 'auth.required', 'Сначала авторизуйте игровое соединение.');
            }
            // Не используем client playerId и не обходим текущую проверку кабинета.
            CabinetWorldService::authorize($account['token'], $fd, $socket);
            if ($message->type === 'ping') return self::packet($fd, 'pong', $requestId);
            if ($message->type === 'logout') {
                CabinetWorldService::revoke($account['token']);
                Connect::setAccount($fd, null);
                $account['frame'] = 0;
                Accounts::update($account);
                return self::sessionEnded($fd, $requestId, true);
            }
            if (in_array($message->type, ['subscribe', 'unsubscribe'], true)) {
                $id = $message->subscriptionId ?? null;
                if (!is_string($id) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $id)) throw new \DomainException('subscription.invalid_input');
                if ($message->type === 'unsubscribe') {
                    ProtocolSubscriptions::remove(Connect::getConnectionId($fd), $id);
                    return self::packet($fd, 'unsubscribed', $requestId, ['subscriptionId' => $id]);
                }
                if (!in_array($message->name ?? null, ProtocolOperations::SUBSCRIPTIONS, true)) throw new \DomainException('subscription.unsupported');
                return MiningCheckpoints::worldLocked(static function () use ($fd, $account, $message, $id, $requestId): array {
                    $snapshot = self::snapshot((int)$account['id'], $message->name, (array)($message->context ?? new \stdClass()), (array)($message->data ?? new \stdClass()));
                    ProtocolSubscriptions::put($fd, (int)$account['id'], Connect::getConnectionId($fd), $id, $message->name,
                        ['context' => (array)($message->context ?? new \stdClass()), 'data' => (array)($message->data ?? new \stdClass())]);
                    ProtocolSubscriptions::delivered(Connect::getConnectionId($fd), $id, $snapshot['revision']);
                    return self::packet($fd, 'subscribed', $requestId, ['subscriptionId' => $id, 'name' => $message->name, 'data' => $snapshot]);
                }, true);
            }
            if ($message->type === 'command') {
                try {
                    $status = MiningCheckpoints::worldLocked(fn() => ProtocolCommandService::submit((int)$account['id'], $message, self::$epoch), true);
                    return self::commandPacket($fd, $requestId, $status);
                } catch (\DomainException $error) {
                    $text = match ($error->getMessage()) {
                        'command.id_conflict' => 'Этот requestId уже использован для другого намерения.',
                        'command.expired' => 'Истёк срок нового запроса. Проверьте состояние перед новой командой.',
                        'command.stale_epoch' => 'Сервер перезапущен. Прочитайте состояние заново.',
                        'command.unknown' => 'Команда не поддерживается.',
                        default => 'Некорректные параметры команды.',
                    };
                    return self::error($fd, $requestId, $error->getMessage(), $text);
                }
            }
            if ($message->type === 'query' && ($message->name ?? null) === 'command.status.get') {
                $data = (array)($message->data ?? new \stdClass());
                if ((array)($message->context ?? new \stdClass()) !== [] || array_keys($data) !== ['commandId']
                    || !is_string($data['commandId']) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $data['commandId'])) {
                    return self::error($fd, $requestId, 'query.invalid_input', 'Нужен только UUID commandId.');
                }
                return self::packet($fd, 'queryResult', $requestId, ['name' => 'command.status.get',
                    'data' => MiningCheckpoints::worldLocked(
                        fn() => ProtocolCommandService::lookup((int)$account['id'], $data['commandId'], self::$epoch), true)]);
            }
            if ($message->type !== 'query' || !is_string($message->name ?? null)) throw new \DomainException('query.unknown');
            $snapshot = MiningCheckpoints::worldLocked(fn() => self::snapshot((int)$account['id'], $message->name,
                (array)($message->context ?? new \stdClass()), (array)($message->data ?? new \stdClass())), true);
            return self::packet($fd, 'queryResult', $requestId, ['name' => $message->name, 'data' => $snapshot]);
        } catch (\JsonException) {
            return self::error($fd, $requestId, 'protocol.invalid_json', 'Некорректный JSON.');
        } catch (\OutOfBoundsException) {
            return self::error($fd, $requestId, 'resource.unavailable', 'Объект недоступен.');
        } catch (\DomainException $error) {
            return self::error($fd, $requestId, $error->getMessage(), 'Операция или её параметры не поддерживаются.');
        } catch (\InvalidArgumentException $error) {
            return self::error($fd, $requestId, 'query.unavailable', $error->getMessage());
        } catch (\Throwable $error) {
            if ($error->getMessage() === 'session_replaced') {
                Connect::setAccount($fd, null);
                return self::sessionEnded($fd, $requestId);
            }
            Logger::getInstance()->warning('Запрос протокола v2 не выполнен', ['exception' => $error::class,
                'file' => $error->getFile(), 'line' => $error->getLine()]);
            return self::error($fd, $requestId, 'service.unavailable', 'Не удалось выполнить запрос. Повторите позже.', false, true);
        }
    }

    private static function snapshot(int $account, string $name, array $context, array $data): array
    {
        FleetMiningCheckpointService::recoverPending();
        ProtocolResourceService::account($account, $context);
        if ($name === 'player.status.get') $context = []; // Глобальное состояние, не снимок произвольной выбранной базы.
        $planets = ProtocolReadService::planets($account, $context['planetId'] ?? null);
        $context = ['planetId' => $planets['selectedPlanetId']] + $context;
        $value = ProtocolResourceService::value($account, $name, $context, $data);
        $identity = hash('sha256', json_encode(ProtocolCommandService::canonical([$account, $name,
            $name === 'player.status.get' ? [] : $context, $data]), JSON_THROW_ON_ERROR));
        return ['resource' => substr($name, 0, -4), 'full' => true, 'revision' => ProtocolResources::revision($identity, $value),
            'observedAt' => microtime(true), 'context' => ['playerId' => $planets['playerId']] + $context,
            'parameters' => (object)$data, 'value' => $value];
    }

    /** Тот же authority/EventLoop; это не новый транспорт или клиентский polling. */
    public const PUSH_INTERVAL_MS = 50;
    private static float $nextPushMetricsAt = 0.0;

    public static function flushPending(): void
    {
        $started = hrtime(true);
        $limit = max(1, min(256, Environment::getInt('WS_PUSH_MAX_PER_PASS', 64)));
        $budget = max(1, min(25, Environment::getInt('WS_PUSH_BUDGET_MS', 10))) * 1000000;
        // Не повторяем сбойную подписку несколько раз за один проход.
        $available = min($limit, ProtocolSubscriptions::pendingCount());
        $attempts = 0;
        for (; $attempts < $available && hrtime(true) - $started < $budget; ++$attempts) {
            $row = ProtocolSubscriptions::nextPending();
            if ($row === null) continue;
            $fd = $row['fd'];
            $account = $row['account'];
            $connection = $row['connection'];
            // Нельзя доставить старую очередь новому клиенту с переиспользованным fd.
            if (Connect::getConnectionId($fd) !== $connection || Connect::getProtocolVersion($fd) !== 2
                || Connect::getRawAccount($fd) !== $account) {
                ProtocolSubscriptions::remove($connection);
                continue;
            }
            if ($row['retryAt'] > microtime(true)) continue;
            // Проверка кабинета могла временно устареть: запрещаем push,
            // но не теряем подписку после восстановления связи с кабинетом.
            if (Connect::getAccount($fd) !== $account) {
                ProtocolSubscriptions::retryLater($connection, $row['id']);
                continue;
            }
            RuntimeMetrics::increment('protocol.pushAttempts');
            try {
                MiningCheckpoints::worldLocked(static function () use ($fd, $account, $connection, $row): void {
                    $parameters = json_decode($row['parameters'], true, 24, JSON_THROW_ON_ERROR);
                    $snapshot = self::snapshot($account, $row['name'], $parameters['context'], $parameters['data']);
                    if ($snapshot['revision'] !== $row['revision']) {
                        $sent = WSocket::getInstance()?->Send($fd, self::packet($fd, 'resource', null,
                            ['subscriptionId' => $row['id'], 'name' => $row['name'], 'data' => $snapshot]));
                        if (!$sent) {
                            ProtocolSubscriptions::retryLater($connection, $row['id']);
                            return;
                        }
                        RuntimeMetrics::increment('protocol.pushSent');
                    }
                    ProtocolSubscriptions::delivered($connection, $row['id'], $snapshot['revision']);
                }, true);
            } catch (\Throwable $error) {
                if ($error->getCode() === MiningCheckpoints::LOCK_BUSY) break;
                ProtocolSubscriptions::retryLater($connection, $row['id']);
                Logger::getInstance()->warning('Обновление подписки v2 отложено',
                    ['exception' => $error::class, 'file' => $error->getFile(), 'line' => $error->getLine()]);
            }
        }
        $pending = ProtocolSubscriptions::pendingCount();
        RuntimeMetrics::gauge('protocol.pushPending', $pending);
        RuntimeMetrics::gauge('protocol.pushLastBatch', $attempts);
        if ($pending > 0 && ($attempts >= $limit || hrtime(true) - $started >= $budget)) {
            RuntimeMetrics::increment('protocol.pushBudgetHits');
        }
        if (microtime(true) >= self::$nextPushMetricsAt) {
            self::$nextPushMetricsAt = microtime(true) + 1.0;
            RuntimeMetrics::gauge('protocol.pushOldestSeconds', ProtocolSubscriptions::oldestPendingSeconds());
            RuntimeMetrics::gauge('protocol.pushMaxPerPass', $limit);
            RuntimeMetrics::gauge('protocol.pushBudgetMs', $budget / 1000000);
        }
        if ($available > 0) RuntimeMetrics::observe('protocol.push', (hrtime(true) - $started) / 1000000);
    }
}
