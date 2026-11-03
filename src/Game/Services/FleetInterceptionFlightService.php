<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{Accounts, FleetCommands, Fleets, Messages, SpaceBattles, Users};
use SPGame\Game\Space\{Coordinate, CoordinateType};

/** Исполнение перехвата; прогноз наблюдения и боевой калькулятор остаются отдельными. */
final class FleetInterceptionFlightService
{
    public static function prepareMove(array $command, Fleet $fleet, AccountData $owner, Coordinate $start, float $time): array
    {
        $params = FleetControlService::params($command);
        $id = (int)($params['target']['fleetId'] ?? 0);
        $policy = FleetInterceptionService::policy($owner, $params['interception']['policy'] ?? null);
        $observation = FleetInterceptionService::targetObservation($fleet, $id, $time, $start);
        $meeting = FleetInterceptionService::meeting($observation, $start, $time,
            $fleet->getTotalSpeed($owner), (float)($params['speedFactor'] ?? 10));
        $params['target'] = $meeting['target']->toArray();
        $params['interception'] = ['policy' => $policy, 'signature' => $observation['signature'], 'observedAt' => $observation['observedAt']];
        unset($params['interceptionStop'], $params['interceptNotified'], $params['planError']);
        self::encode($params);
        return $meeting + ['params' => $params];
    }

    /** До активации/после прибытия: нет нового боя, нет скрытого преследования. */
    public static function pauseBefore(array $command, Fleet $fleet, string $reason, string $message): void
    {
        $params = FleetControlService::params($command);
        $params['pause'] = 'target';
        $params['planError'] = $message;
        $params['interceptionEpisode'] = (int)($params['interceptionEpisode'] ?? 0) + 1;
        $params['interceptionStop'] = $reason;
        self::write($fleet, $command, $params, 0.0);
    }

    public static function arrive(array $command, Fleet $fleet, AccountData $owner): ?array
    {
        $current = FleetCommands::getCommand((int)$command['id']);
        if ($current === null || (int)$current['executed'] !== 0) return null;
        $time = $fleet->getCurrentPositionTime();
        $params = FleetControlService::params($current);
        $id = (int)($params['target']['fleetId'] ?? 0);
        try {
            FleetInterceptionService::targetObservation($fleet, $id, $time);
            $target = FleetsData::getInstance()->get($id);
            if ($target === null || FleetRendezvousService::distance($fleet->getCurrentCoordinate(),
                FleetRendezvousService::position($target, $time), $time) > .05) {
                throw new \InvalidArgumentException('В рассчитанной точке встречи доступная цель не найдена.');
            }
        } catch (\InvalidArgumentException|\LogicException $error) {
            self::pauseBefore($current, $fleet, 'target_missing', $error->getMessage());
            return null;
        }
        $user = Users::findExistingById($target->getOwnerId());
        if ($user === null) throw new \LogicException('Владелец цели перехвата недоступен.');
        // Ошибки commit/боевого адаптера НЕ превращаем в успешную потерю контакта.
        return FleetSpaceBattleService::engage($current, $fleet, $target, $owner,
            new AccountData((int)$user['account_id']), $time);
    }

    /** Обычный проход EventLoop: обновление только от настоящего нового наблюдения. */
    public static function process(float $time): array
    {
        $affected = [];
        foreach (FleetCommands::findByIndex('command_type', (string)Command::MOVE_IN_SYSTEM->value) ?? [] as $command) {
            if ((int)$command['executed'] || (float)$command['execute_time'] <= $time) continue;
            $id = (int)$command['fleet_id'];
            try {
                $params = FleetControlService::params($command);
                if (!isset($params['interception']) || isset($params['pause']) || !empty($params['returnLeg'])) continue;
                if (SpaceBattles::forFleet($id) !== null) continue;
                $fleet = FleetsData::getInstance()->get($id);
                if ($fleet === null || $time < $fleet->getCurrentPositionTime()) continue;
                $user = Users::findExistingById($fleet->getOwnerId());
                if ($user === null) throw new \LogicException('Владелец перехватчика недоступен.');
                $owner = new AccountData((int)$user['account_id']);
                try { $observed = FleetInterceptionService::targetObservation($fleet, (int)$params['target']['fleetId'], $time); }
                catch (\InvalidArgumentException|\LogicException) {
                    if (($params['interception']['policy'] ?? 'last_position') === 'stop') {
                        self::interrupt($command, $fleet, $owner, $time, null);
                        $affected[(int)$user['account_id']] = true;
                    }
                    continue; // По умолчанию летим по сохранённой прямой без live-коррекции.
                }
                if (($params['interception']['signature'] ?? null) === $observed['signature']) continue;
                self::interrupt($command, $fleet, $owner, $time, $observed);
                $affected[(int)$user['account_id']] = true;
            } catch (\Throwable $error) {
                Logger::getInstance()->error('Обновление перехвата отложено', ['fleetId' => $id, 'message' => $error->getMessage()]);
            }
        }
        return array_keys($affected);
    }

    private static function interrupt(array $command, Fleet $fleet, AccountData $owner, float $time, ?array $observation): void
    {
        $flight = new FleetFlightService();
        $params = FleetControlService::params($command);
        $point = $flight->calculateCurrentCoordinate($fleet, $command, $time);
        $fuel = FleetTransferService::physicalFuel($fleet, $time);
        $fraction = max(0.0, min(1.0, ($time - $fleet->getCurrentPositionTime())
            / max(.000001, (float)$command['execute_time'] - $fleet->getCurrentPositionTime())));
        $weight = (float)($params['progressWeight'] ?? 1);
        $done = (float)($params['progressDone'] ?? 0);
        $params['progressDone'] = min($weight, $done + (float)($params['progressSpan'] ?? $weight - $done) * $fraction);
        foreach (['motionStart', 'motionEnd', 'resolvedTarget', 'startedAt', 'fuelCost', 'fuelStop', 'recallFlight', 'progressSpan'] as $key) unset($params[$key]);
        $until = 0.0;
        if ($observation === null) {
            $params['pause'] = 'target';
            $params['planError'] = 'Контакт потерян. Перехват остановлен согласно выбранному поведению.';
            $params['interceptionStop'] = 'contact_lost';
            $params['interceptionEpisode'] = (int)($params['interceptionEpisode'] ?? 0) + 1;
        } else {
            $meeting = FleetInterceptionService::meeting($observation, $point, $time,
                $fleet->getTotalSpeed($owner), (float)($params['speedFactor'] ?? 10));
            $target = $meeting['target'];
            $params['target'] = $target->toArray();
            $params['interception'] = ['policy' => $params['interception']['policy'],
                'signature' => $observation['signature'], 'observedAt' => $observation['observedAt']];
            $until = $time + $meeting['duration'];
            $params = $flight->captureCommandMotion($point, $target, $params, $time, $until);
            $cost = $flight->calculateFuelConsumption($fleet, $owner,
                [$flight->calculateCommandGameDistance(Command::MOVE_IN_SYSTEM, $point, $target, $params, $time, $until)],
                (float)($params['speedFactor'] ?? 10));
            if ($cost > $fuel + .000001) {
                $until = 0.0; $params['pause'] = 'fuel';
                $params['fuelEpisode'] = (int)($params['fuelEpisode'] ?? 0) + 1;
                unset($params['motionStart'], $params['motionEnd']);
            } else {
                $fuel = max(0.0, $fuel - $cost);
                $params['fuelCost'] = $cost; $params['startedAt'] = $time;
            }
        }
        self::write($fleet, $command, $params, $until, static function () use ($fleet, $point, $time, $fuel): void {
            $fleet->setCurrentCoordinate($point, $time);
            $fleet->setFuelCurrent(min($fleet->getFuelTotal(), $fuel));
            $fleet->save();
        });
    }

    private static function encode(array $params): string
    {
        $json = json_encode($params, JSON_THROW_ON_ERROR);
        if (strlen($json) > 2048) throw new \OverflowException('Параметры перехвата превышают runtime лимит команды.');
        return $json;
    }

    /** Локальная компенсация также чинит attempted-index при B006, не меняя общий commit. */
    private static function write(Fleet $fleet, array $command, array $params, float $until, ?callable $operation = null): void
    {
        $encoded = self::encode($params); // До первой мутации.
        $after = array_replace($command, ['params' => $encoded, 'execute_time' => $until]);
        $beforeFleet = Fleets::getById($fleet->getId());
        try {
            FleetControlService::commit([$fleet], static function () use ($command, $encoded, $until, $operation): void {
                if ($operation !== null) $operation();
                FleetControlService::patchCommand((int)$command['id'], ['params' => $encoded, 'execute_time' => $until]);
            });
            if (!in_array((int)$command['id'], FleetCommands::getPendingSync()['update'], true)
                || (Fleets::getById($fleet->getId()) !== $beforeFleet
                    && !in_array($fleet->getId(), Fleets::getPendingSync()['update'], true))) {
                throw new \RuntimeException('Не сохранены dirty markers перехвата.');
            }
        } catch (\Throwable $original) {
            try {
                FleetCommands::discardUncommittedRow($after);
                FleetCommands::restoreDeletedRow($command);
                $attemptedFleet = array_replace($beforeFleet, ['current_coordinate_type' => CoordinateType::POINT->value,
                    'current_orbit' => 0, 'current_object_type' => 0]);
                Fleets::discardUncommittedRow($attemptedFleet);
                Fleets::restoreDeletedRow($beforeFleet);
                if (FleetCommands::getCommand((int)$command['id']) !== $command || Fleets::getById($fleet->getId()) !== $beforeFleet) {
                    throw new \RuntimeException('Не восстановлены строки перехвата.');
                }
                $fleet->reloadFromRuntime();
            } catch (\Throwable $rollback) {
                Logger::getInstance()->critical('Rollback участка перехвата не выполнен', ['fleetId' => $fleet->getId(),
                    'originalMessage' => $original->getMessage(), 'message' => $rollback->getMessage()]);
                try {
                    $blocked = $params; $blocked['pause'] = 'target';
                    $blocked['planError'] = 'Неполный rollback перехвата: требуется диагностика.';
                    FleetControlService::patchCommand((int)$command['id'], ['params' => self::encode($blocked), 'execute_time' => 0.0]);
                } catch (\Throwable $lockError) {
                    Logger::getInstance()->critical('Не заблокирован участок после отказа rollback',
                        ['fleetId' => $fleet->getId(), 'originalMessage' => $original->getMessage(), 'message' => $lockError->getMessage()]);
                }
            }
            throw $original;
        }
    }

    /** После commit: обычные Messages/PlayerQueue; повтор не дублирует сообщение. */
    public static function notifyStops(float $time): void
    {
        foreach (FleetCommands::getAll() ?? [] as $command) {
            if ((int)$command['executed'] || (float)$command['execute_time'] > 0) continue;
            try {
                $params = FleetControlService::params($command);
                if (!isset($params['interceptionStop'], $params['pause'])) continue;
                $episode = $command['id'] . ':' . (int)($params['interceptionEpisode'] ?? 0);
                if (($params['interceptNotified'] ?? null) === $episode) continue;
                $fleet = FleetsData::getInstance()->get((int)$command['fleet_id']);
                if ($fleet === null || FleetCommands::getActiveCommand($fleet->getId()) !== null
                    || (int)(FleetCommands::getNextPendingCommand($fleet->getId())['id'] ?? 0) !== (int)$command['id']) continue;
                $user = Users::findExistingById($fleet->getOwnerId());
                if ($user === null || Accounts::findExistingById((int)$user['account_id']) === null) continue;
                $owner = new AccountData((int)$user['account_id']);
                $row = Notification::prepareFleetFuelStop($fleet, $command, $episode, $time);
                $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                unset($data['lang'], $data['ResourcesCargoFuel']);
                $data['InterceptionEpisode'] = $episode;
                $data['Reason'] = $params['planError'];
                $row = array_replace($row, ['subject' => 'subject.Transport.InterceptionStop', 'sample' => 'FleetInterceptionStopped',
                    'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                $existing = false;
                foreach (Messages::findByIndex('to', (string)$fleet->getOwnerId()) ?? [] as $old) {
                    if ($old['sample'] === $row['sample'] && (json_decode($old['data'], true, 512, JSON_THROW_ON_ERROR)['InterceptionEpisode'] ?? null) === $episode) { $existing = true; break; }
                }
                if (!$existing) {
                    $id = Messages::add($row);
                    if ((Messages::findExistingById((int)$id)['data'] ?? null) !== $row['data']) throw new \RuntimeException('Не сохранено сообщение перехвата.');
                }
                Notification::pushMessages($owner);
                $params['interceptNotified'] = $episode;
                FleetControlService::patchCommand((int)$command['id'], ['params' => self::encode($params)]);
            } catch (\Throwable $error) {
                Logger::getInstance()->error('Сообщение о потере цели отложено', ['commandId' => $command['id'], 'message' => $error->getMessage()]);
            }
        }
    }
}
