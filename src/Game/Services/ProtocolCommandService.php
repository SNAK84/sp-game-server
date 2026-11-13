<?php

namespace SPGame\Game\Services;

use SPGame\Core\{Connect, GameProtocolV2, Logger, Message};
use SPGame\Game\Repositories\{Accounts, Fleets, PlayerQueue, ProtocolCommands, Users};

/** Все игровые команды v2 публикуются в существующую PlayerQueue. */
final class ProtocolCommandService
{
    public static function submit(int $accountId, \stdClass $message, string $epoch): array
    {
        $context = (array)($message->context ?? new \stdClass());
        $data = (array)($message->data ?? new \stdClass());
        $name = $message->name ?? '';
        $definition = ProtocolOperations::definition($name, true);
        ProtocolOperations::validateCommand($name, $data);
        if (array_keys($context) !== ['planetId']
            || !is_string($context['planetId']) || !preg_match('/^[1-9][0-9]{0,19}$/D', $context['planetId'])
            || !is_string($message->serverEpoch ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $message->serverEpoch)
            || !is_numeric($message->issuedAt ?? null) || !is_int($message->issuedAt) && !is_float($message->issuedAt)) {
            throw new \DomainException('command.invalid_input');
        }
        $user = Users::findByIndex('account', $accountId) ?? throw new \OutOfBoundsException('Игрок недоступен.');
        $player = (int)$user['id'];
        $world = CabinetWorldService::worldId();
        $fingerprint = hash('sha256', json_encode(self::canonical([$name, $context, $data,
            (float)$message->issuedAt, $message->serverEpoch]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $row = ProtocolCommands::find($world, $player, $message->requestId);
        if ($row !== null) return self::duplicate($row, $fingerprint, $epoch);
        if ($message->serverEpoch !== $epoch) throw new \DomainException('command.stale_epoch');
        $age = microtime(true) - $message->issuedAt;
        if ($age > ProtocolCommands::MAX_AGE || $age < -30) throw new \DomainException('command.expired');
        // Проверка прав до публикации; повторяется при исполнении. Не меняет выбранную планету.
        $account = ProtocolResourceService::account($accountId, $context);
        foreach (['FleetId', 'fleetId', 'fromFleetId', 'toFleetId'] as $key) if (isset($data[$key]) && $data[$key] !== '0') {
            $fleet = Fleets::findExistingById((int)$data[$key]);
            if (!$fleet || (int)$fleet['owner_id'] !== $player) throw new \OutOfBoundsException('Флот недоступен.');
        }
        [$row, $created] = ProtocolCommands::reserve($world, $player, $message->requestId, $fingerprint, $epoch, $name);
        if (!$created) return self::duplicate($row, $fingerprint, $epoch);
        $publishing = false;
        try {
            $internal = ProtocolOperations::ids($data, false);
            if ($name === 'planet.select') {
                $prepared = [['accountId' => $accountId, 'userId' => $player, 'planetId' => (int)$context['planetId'],
                    'action' => PlayerQueue::ActionSelectPlanet, 'data' => [], 'priority' => 1]];
            } elseif (str_starts_with($name, 'friend.') || str_starts_with($name, 'combat.report.')) {
                $prepared = [['accountId' => $accountId, 'userId' => $player, 'planetId' => (int)$context['planetId'],
                    'action' => PlayerQueue::ActionProtocolImmediate, 'data' => ['name' => $name, 'input' => $internal], 'priority' => 1]];
            } else {
                if ($name === 'planet.rename') $internal = ['Name' => PlanetRenameService::validate($data['name'] ?? null)];
                $input = new Message(['mode' => $definition[0], 'action' => $definition[1], 'data' => $internal]);
                $response = new Message();
                $prepared = PlayerQueue::capture(static function () use ($input, &$account, $accountId, $response): void {
                    PageActionService::handle($input, $account, $accountId, $response);
                });
                if ($response->getStatus() === 'error') {
                    return self::status(ProtocolCommands::finish($row, 'rejected', ['error' => $response->source()['error']]), $epoch);
                }
                if (count($prepared) !== 1) throw new \InvalidArgumentException('Команда не сформировала игрового действия.');
            }
            $event = $prepared[0];
            $publishing = true;
            $queueId = PlayerQueue::addQueue($event['accountId'], $event['userId'], $event['planetId'], $event['action'],
                $event['data'] + ['_protocolV2' => ['requestId' => $message->requestId, 'claim' => $row['claim'], 'name' => $name]], $event['priority']);
            Logger::getInstance()->info('Команда v2 опубликована', ['requestId' => $message->requestId, 'playerId' => $player, 'queueId' => $queueId]);
        } catch (\InvalidArgumentException|\LogicException $error) {
            if ($publishing) {
                ProtocolCommands::finish($row, 'unknown', ['reason' => 'publication_unconfirmed']);
                throw $error;
            }
            return self::status(ProtocolCommands::finish($row, 'rejected', ['error' => ['code' => 'command.unavailable', 'message' => $error->getMessage()]]), $epoch);
        } catch (\Throwable $error) {
            // Публикация могла оборваться после частичного действия. Повтор не ставим.
            ProtocolCommands::finish($row, 'unknown', ['reason' => 'publication_unconfirmed']);
            throw $error;
        }
        return self::status($row, $epoch);
    }

    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value) && !$value instanceof \stdClass) return $value;
        $value = (array)$value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return array_map([self::class, 'canonical'], $value);
    }

    private static function duplicate(array $row, string $fingerprint, string $epoch): array
    {
        if (!hash_equals($row['fingerprint'], $fingerprint)) throw new \DomainException('command.id_conflict');
        return self::status(ProtocolCommands::reconcilePending($row), $epoch);
    }

    private static function status(array $row, string $epoch): array
    {
        $current = hash_equals($row['epoch'], $epoch);
        return ['commandId' => $row['request_id'], 'name' => $row['operation'], 'state' => $current ? $row['state'] : 'unknown',
            'createdAt' => (float)$row['created_at'], 'expiresAt' => (float)$row['expires_at'],
            'outcomeDurability' => 'runtime',
            'outcome' => $current ? (object)json_decode($row['outcome'], false, 16, JSON_THROW_ON_ERROR)
                : (object)['reason' => 'runtime_changed']];
    }

    public static function lookup(int $accountId, string $requestId, string $epoch): array
    {
        $user = Users::findByIndex('account', $accountId) ?? throw new \OutOfBoundsException('Игрок недоступен.');
        $row = ProtocolCommands::find(CabinetWorldService::worldId(), (int)$user['id'], $requestId);
        if ($row !== null) $row = ProtocolCommands::reconcilePending($row);
        return $row !== null ? self::status($row, $epoch)
            : ['commandId' => strtolower($requestId), 'state' => 'unknown', 'outcomeDurability' => 'runtime',
                'outcome' => (object)['reason' => 'not_found']];
    }

    public static function beforeEvent(array $event): bool
    {
        if (!isset($event['data']['_protocolV2'])) return true;
        $meta = $event['data']['_protocolV2'];
        $row = ProtocolCommands::find(CabinetWorldService::worldId(), (int)$event['user_id'], $meta['requestId']);
        return $row !== null && hash_equals($row['claim'], $meta['claim']) && $row['state'] === 'accepted';
    }

    /** После AccountData::save; экономическое завершение стройки/полёта — другое событие. */
    public static function finishEvent(array $event, string $state, ?string $error = null): void
    {
        if (!isset($event['data']['_protocolV2'])) return;
        $meta = $event['data']['_protocolV2'];
        $row = ProtocolCommands::find(CabinetWorldService::worldId(), (int)$event['user_id'], $meta['requestId']);
        if ($row === null || !hash_equals($row['claim'], $meta['claim'])) return;
        $outcome = $state === 'applied' ? ['result' => (object)[]] : ($state === 'rejected'
            ? ['error' => ['code' => 'command.unavailable', 'message' => $error ?? 'Действие недоступно.']]
            : ['reason' => 'execution_unconfirmed']);
        if ($state === 'applied' && $event['action'] === PlayerQueue::ActionRenamePlanet) {
            $outcome['result'] = ['planetId' => (string)$event['planet_id'], 'name' => $event['data']['Name']];
        }
        $row = ProtocolCommands::finish($row, $state, $outcome);
        $account = Accounts::findExistingById((int)$event['account_id']);
        $fd = (int)($account['frame'] ?? 0);
        if ($fd > 0 && Connect::getProtocolVersion($fd) === 2
            && Connect::getRawAccount($fd) === (int)$event['account_id']) {
            GameProtocolV2::sendCommandResult($fd, $meta['requestId'], self::status($row, GameProtocolV2::epoch()));
        }
    }
}
