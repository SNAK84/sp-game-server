<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\{FleetCommandType, FleetMissionType};
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{Builds, Config, DebrisFields, Defenses, Factors, FleetCommands, Fleets,
    FleetsShips, Messages, PlanetBattles, Planets, Resources, PlanetResources, Ships, Users, Vars, BattleReports,
    Queues, FleetAttackGroups};
use SPGame\Game\Space\{Coordinate, CoordinateType};

/** Адаптер ATTACK: прибытие, участники и локальный runtime commit, но не боевой расчёт. */
final class FleetAttackService
{
    private const REPOSITORIES = [
        'ships' => Ships::class, 'defenses' => Defenses::class, 'resources' => PlanetResources::class,
        'fleets' => Fleets::class, 'fleetShips' => FleetsShips::class,
        'commands' => FleetCommands::class, 'debris' => DebrisFields::class, 'messages' => Messages::class,
        'planets' => Planets::class, 'builds' => Builds::class,
        'users' => Users::class, 'queues' => Queues::class, 'attackGroups' => FleetAttackGroups::class,
    ];

    public static function arrive(array $command, Fleet $fleet, AccountData $owner): ?array
    {
        $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
        if ((int)$command['command_type'] === FleetCommandType::ATTACK->value
            && (int)($params['target']['type'] ?? 0) === CoordinateType::FLEET->value) {
            return FleetInterceptionFlightService::arrive($command, $fleet, $owner);
        }
        if ((int)$command['command_type'] === FleetCommandType::GROUP_ATTACK->value && !isset($params['groupRendezvous'])) {
            $time = max($fleet->getCurrentPositionTime(), (float)($params['startedAt'] ?? 0));
            $fleet->setCurrentCoordinate($fleet->getCurrentCoordinate(), $time);
            $fleet->save();
            $params['groupRendezvous'] = true;
            $params['startedAt'] = $time;
            return FleetCommands::activateCommand((int)$command['id'], max($time, (float)$params['groupExecuteAt']), $params);
        }
        $target = FleetCommandsService::getCommandTarget($command);
        $planet = $target === null ? null : self::planetAt($target);
        if ($planet === null || (int)$planet['owner_id'] <= 0 || (int)$planet['owner_id'] === $fleet->getOwnerId()
            || !self::atPlanet($fleet, $planet)) {
            // Цель исчезла/сменила владельца: без списаний и без зависшей команды.
            FleetCommands::markExecuted((int)$command['id']);
            return FleetCommandsService::activateNextCommand($fleet, $owner);
        }
        $planetId = (int)$planet['id'];
        $state = PlanetBattles::state($planetId);
        if ($state === null || $state['phase'] === 'applied') {
            $defender = self::accountFor((int)$planet['owner_id'], $planetId);
            $attackers = self::fleetGroups($fleet, $owner);
            $defenders = [];
            foreach (['Ships' => 'planet', 'Defenses' => 'defense'] as $block => $kind) {
                $list = Vars::$reslist[$kind === 'planet' ? 'fleet' : 'defense'];
                foreach ($list as $type) {
                    $count = (int)($defender[$block][Vars::$resource[$type]] ?? 0);
                    if ($count > 0) $defenders["{$kind}:{$planetId}:{$type}"] = self::group(
                        (int)$type, $count, $kind, $defender, 0, 0.0, $planetId);
                }
            }
            $participant = self::participant($fleet, 'attacker', (int)$command['id']);
            $participants = [$fleet->getId() => $participant];
            $groupId = (int)($params['groupId'] ?? 0);
            if ($groupId > 0) foreach (FleetGroupAttackService::arrivals($groupId) as $memberId => $memberCommand) {
                if ($memberId === $fleet->getId()) continue;
                $member = FleetsData::getInstance()->get($memberId);
                if ($member === null || !self::atPlanet($member, $planet) || $member->getOwnerId() === (int)$planet['owner_id']) continue;
                $memberOwner = self::accountFor($member->getOwnerId());
                $attackers += self::fleetGroups($member, $memberOwner);
                $participants[$memberId] = self::participant($member, 'attacker', (int)$memberCommand['id']);
            }
            foreach (Fleets::findByIndex('owner_id', (string)$planet['owner_id']) ?? [] as $row) {
                if ((int)$row['status'] !== Fleet::STATUS_IDLE
                    || FleetCommands::getActiveCommands((int)$row['id']) !== []) continue;
                $defendingFleet = FleetsData::getInstance()->get((int)$row['id']);
                if ($defendingFleet === null || !self::atPlanet($defendingFleet, $planet)) continue;
                $defenders += self::fleetGroups($defendingFleet, $defender);
                $participants[$defendingFleet->getId()] = self::participant($defendingFleet, 'defender');
            }
            foreach (FleetDefendService::holdingFleets($planetId) as $defendingId) {
                $support = FleetsData::getInstance()->get((int)$defendingId);
                if ($support === null || !FleetDefendService::isHoldingAt($support, $planet)) continue;
                $supportOwner = self::accountFor($support->getOwnerId());
                $hold = FleetCommands::getActiveCommand($support->getId());
                $defenders += self::fleetGroups($support, $supportOwner);
                $participants[$support->getId()] = self::participant($support, 'defender', null, (int)$hold['id']);
            }
            FleetBattleService::begin($planetId, $attackers, $defenders, $fleet->getCurrentPositionTime(),
                Vars::$rapidfire, array_keys($participants), [
                    'planet' => $planet, 'attackerId' => $fleet->getOwnerId(), 'defenderId' => (int)$planet['owner_id'],
                    'defenderName' => (string)($defender['User']['name'] ?? ''),
                    'participants' => $participants, 'waiting' => [],
                    'groupId' => $groupId,
                    'fleetDebrisPercent' => self::percent('FleetDebrisPercent', 30),
                    'defenseDebrisPercent' => self::percent('DefenseDebrisPercent', 0),
                    'moonRules' => MoonFormationService::rules(),
                ]);
            foreach ($participants as $memberId => $member) {
                if ($member['side'] !== 'attacker' || $memberId === $fleet->getId()) continue;
                self::wait($planetId, FleetCommands::getCommand($member['attackCommandId']), FleetsData::getInstance()->get($memberId));
            }
            foreach (FleetDefendService::holdingFleets($planetId) as $defendingId) {
                if (!isset($participants[$defendingId])) continue;
                $support = FleetsData::getInstance()->get((int)$defendingId);
                self::wait($planetId, FleetCommands::getActiveCommand((int)$defendingId), $support);
            }
        } else {
            self::enroll($planetId, $state, $command, $fleet, $owner, true);
        }
        return self::wait($planetId, $command, $fleet);
    }

    /** Дружественное прибытие на воюющую планету не может обойти бой через UNLOAD/STAY. */
    public static function pauseArrival(array $command, Fleet $fleet, AccountData $owner): ?array
    {
        $planet = self::planetAt($fleet->getCurrentCoordinate());
        if ($planet === null) return null;
        $state = PlanetBattles::state((int)$planet['id']);
        if ($state === null || $state['phase'] === 'applied' || !isset($state['mission'])) return null;
        if ($state['phase'] === 'settled' && isset($state['resolution']['engaged'][$fleet->getId()])) return null;
        self::enroll((int)$planet['id'], $state, $command, $fleet, $owner, false);
        return self::wait((int)$planet['id'], $command, $fleet);
    }

    private static function enroll(int $planetId, array $state, array $command, Fleet $fleet, AccountData $owner, bool $attack): void
    {
        if (isset($state['mission']['participants'][$fleet->getId()]) || isset($state['mission']['waiting'][$fleet->getId()])) return;
        $defend = (int)$command['command_type'] === FleetCommandType::DEFEND->value
            && FleetDefendService::isHoldingAt($fleet, $state['mission']['planet']);
        $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
        $sameGroup = (int)($params['groupId'] ?? 0) > 0
            && (int)$params['groupId'] === (int)($state['mission']['groupId'] ?? 0);
        if (!$sameGroup && (int)($params['groupId'] ?? 0) > 0) {
            $group = FleetAttackGroups::findExistingById((int)$params['groupId']);
            $sameGroup = $group !== null && (int)$group['owner_id'] === $state['mission']['attackerId']
                && isset(FleetGroupAttackService::members($group)[$fleet->getId()]);
        }
        $side = ($fleet->getOwnerId() === $state['mission']['defenderId'] || $defend) ? 'defender'
            : ($attack && ($fleet->getOwnerId() === $state['mission']['attackerId'] || $sameGroup) ? 'attacker' : null);
        $nextRound = count($state['combat']['rounds']) + ($state['job'] === null ? 1 : 2);
        if ($side !== null && $state['phase'] === 'running' && $nextRound <= $state['combat']['roundLimit']) {
            $groups = self::fleetGroups($fleet, $owner);
            if ($groups !== []) {
                FleetBattleService::reinforce($planetId, $state['id'], 'command:' . $command['id'], $side,
                    $groups, $fleet->getCurrentPositionTime(), [$fleet->getId()],
                    self::participant($fleet, $side, $attack ? (int)$command['id'] : null,
                        $attack ? null : (int)$command['id']));
                return;
            }
        }
        // Третий владелец не становится союзником автоматически; ждёт отдельного столкновения.
        $state['mission']['waiting'][$fleet->getId()] = ['commandId' => (int)$command['id'], 'ownerId' => $fleet->getOwnerId()];
        $state['fleets'][] = $fleet->getId();
        PlanetBattles::store($planetId, $state);
    }

    private static function wait(int $planetId, array $command, Fleet $fleet): array
    {
        $state = PlanetBattles::state($planetId);
        $current = FleetCommands::getCommand((int)$command['id']);
        $holding = (int)$current['command_type'] === FleetCommandType::DEFEND->value;
        $params = json_decode($current['params'], true, 512, JSON_THROW_ON_ERROR);
        if ((float)$current['execute_time'] > 0.0 && !$holding && !isset($params['groupRendezvous'])) return $current;
        unset($params['groupRendezvous']);
        $params['battleWait'] = ['planetId' => $planetId, 'id' => $state['id']];
        $params['startedAt'] ??= $fleet->getCurrentPositionTime();
        $params['target'] ??= $fleet->getCurrentCoordinate()->toArray();
        if ((float)$current['execute_time'] > 0) {
            // DEFEND уже активен: activateCommand() принимает только pending.
            FleetCommands::update(['id' => (int)$current['id'], 'execute_time' => $holding ? (float)$current['execute_time']
                    : max($fleet->getCurrentPositionTime(), $state['startedAt']) + FleetCombatService::DEFAULT_ROUNDS,
                'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            $active = FleetCommands::getCommand((int)$current['id']);
            if (json_decode($active['params'], true, 512, JSON_THROW_ON_ERROR) !== $params) {
                throw new \RuntimeException('Не удалось приостановить оборону на время боя.');
            }
            return $active;
        }
        $active = FleetCommands::activateCommand((int)$command['id'],
            $holding ? (float)$current['execute_time']
                : max($fleet->getCurrentPositionTime(), $state['startedAt']) + FleetCombatService::DEFAULT_ROUNDS, $params);
        if ($active === null) throw new \RuntimeException('Не удалось приостановить команду на время боя.');
        return $active;
    }

    public static function isBattleWait(array $command): bool
    {
        $params = json_decode($command['params'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        return isset($params['battleWait']);
    }

    public static function mustWait(array $command): bool
    {
        if (\SPGame\Game\Repositories\SpaceBattles::forFleet((int)($command['fleet_id'] ?? 0)) !== null) return true;
        if ((int)$command['command_type'] === FleetCommandType::EXPEDITION->value
            && (\SPGame\Game\Repositories\FleetExpeditions::state((int)$command['fleet_id'])['phase'] ?? '') === 'failed') return true;
        $params = json_decode($command['params'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        if (isset($params['groupRendezvous'])) return !FleetGroupAttackService::ready((int)$params['groupId']);
        if (!isset($params['battleWait'])) return false;
        $wait = $params['battleWait'];
        // Старый ATTACK wait после частичного SQL restore не является новым боем.
        // Не отдаём space key в PlanetBattles и не исполняем его повторно.
        if (($wait['scope'] ?? null) === 'space') return true;
        $state = PlanetBattles::state((int)$wait['planetId']);
        return $state === null || ($state['id'] === $wait['id'] && $state['phase'] !== 'applied');
    }

    /** Nova: до половины запаса, заполнение трюма в пять шагов, без SQL. */
    public static function calculateLoot(array $resources, float $capacity): array
    {
        if (!is_finite($capacity) || $capacity < 0) throw new \InvalidArgumentException('Некорректная вместимость добычи.');
        $loot = [901 => 0.0, 902 => 0.0, 903 => 0.0];
        foreach ([[901, 3], [902, 2], [903, 1], [901, 2], [902, 1]] as [$id, $divisor]) {
            $amount = (float)($resources[$id] ?? 0);
            if (!is_finite($amount) || $amount < 0) throw new \InvalidArgumentException('Некорректный запас для добычи.');
            $take = max(0.0, min($capacity / $divisor, $amount / 2 - $loot[$id]));
            $loot[$id] += $take;
            $capacity = max(0.0, $capacity - $take);
        }
        return $loot;
    }

    private static function settle(int $planetId, array $state, float $now): void
    {
        // Старые незавершённые бои тоже фиксируют правила один раз, до prepare/retry.
        if (!isset($state['mission']['moonRules'])) {
            $state['mission']['moonRules'] = MoonFormationService::rules();
            PlanetBattles::store($planetId, $state);
        }
        if (!isset($state['application'])) {
            $state['application'] = self::prepareResult($planetId, $state, $now);
            PlanetBattles::store($planetId, $state);
        }
        $plan = $state['application'];
        $beforeCount = $afterCount = 0;
        foreach ($plan['changes'] as $change) {
            $current = self::REPOSITORIES[$change['repository']]::findExistingById($change['id']);
            if ($current === $change['before']) ++$beforeCount;
            elseif ($current === $change['after']) ++$afterCount;
            else throw new \LogicException('Состояние участника изменено вне защищённого commit боя.');
        }
        if ($beforeCount > 0 && $afterCount > 0) {
            throw new \LogicException('Обнаружено частично восстановленное сохранение боя; требуется диагностика.');
        }
        $written = [];
        try {
            if ($afterCount === 0) {
                foreach ($plan['changes'] as $change) {
                    $written[] = $change; // add/update может бросить уже после runtime записи.
                    self::writeChange($change, false);
                }
            }
            $state['phase'] = 'settled';
            $state['resolution'] = $plan['resolution'];
            unset($state['application']);
            PlanetBattles::store($planetId, $state);
        } catch (\Throwable $error) {
            $rollbackFailed = false;
            foreach (array_reverse($written) as $change) {
                try { self::writeChange($change, true); }
                catch (\Throwable $rollbackError) {
                    $rollbackFailed = true;
                    Logger::getInstance()->critical('Rollback ATTACK не выполнен', [
                        'repository' => $change['repository'], 'id' => $change['id'], 'message' => $rollbackError->getMessage(),
                    ]);
                }
            }
            // Новые строки после rollback имеют DELETE marker: повтор выделяет НОВЫЕ IDs.
            $original = PlanetBattles::state($planetId);
            if ($original !== null) {
                if ($rollbackFailed || $afterCount > 0) $original['phase'] = 'failed';
                else unset($original['application']);
                PlanetBattles::store($planetId, $original);
            }
            throw $error;
        }
        foreach (array_unique([...array_keys($state['mission']['participants']), ...($plan['resolution']['retargetedFleets'] ?? [])]) as $id) {
            FleetsData::getInstance()->refresh((int)$id);
        }
    }

    private static function prepareResult(int $planetId, array $state, float $now): array
    {
        if ((int)(Planets::findExistingById($planetId)['owner_id'] ?? 0) !== $state['mission']['defenderId']) {
            throw new \LogicException('Владелец цели изменён во время боя.');
        }
        $result = FleetCombatService::getResult($state['combat']);
        $restored = ['defender' => []];
        $random = new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar(
            hash_hmac('sha256', 'defense-repair', $state['seed'], true)));
        foreach ($result['defender']['groups'] as $id => $group) {
            if ($group['kind'] === 'defense') $restored['defender'][$id] = (int)round(
                ($group['before'] - $group['after']) * $random->getInt(56, 84) / 100);
        }
        $inputs = FleetCombatService::experienceInputs($result, $restored);
        $rewards = [];
        foreach ($inputs as $side => $input) $rewards[$side] = FleetExperienceService::calculateRewards(...$input);
        $changes = $engaged = $survivorCapacity = $xpGroups = [];
        $debris = [901 => 0.0, 902 => 0.0];
        $losses = ['attacker' => 0.0, 'defender' => 0.0];
        foreach (['attacker', 'defender'] as $side) {
            foreach ($result[$side]['groups'] as $id => $group) {
                $loss = $group['before'] - $group['after'] - ($restored[$side][$id] ?? 0);
                $losses[$side] += $loss * $group['unitCost'];
                $percent = $state['mission'][$group['kind'] === 'defense' ? 'defenseDebrisPercent' : 'fleetDebrisPercent'];
                foreach ($debris as $resourceId => &$amount) $amount += $loss * $group['price'][$resourceId] * $percent / 100;
                unset($amount);
                $name = Vars::$resource[$group['type']];
                if ($group['kind'] !== 'fleet') {
                    $key = $group['kind'] === 'defense' ? 'defenses' : 'ships';
                    $row = self::changeRow($changes, $key, $planetId);
                    if ((int)($row[$name] ?? 0) < $loss) throw new \LogicException('Исчезли защищённые корабли/оборона планеты.');
                    self::patchRow($changes, $key, $planetId, [$name => (int)($row[$name] ?? 0) - $loss]);
                    continue;
                }
                $fleetId = $group['fleetId'];
                $engaged[$fleetId] = true;
                $row = self::changeRow($changes, 'fleetShips', $fleetId);
                if ((int)($row[$name . '_count'] ?? 0) !== $group['before']
                    || (float)($row[$name . '_experience'] ?? 0) !== $group['experience']) {
                    throw new \LogicException('Защищённый состав/опыт флота изменён во время боя.');
                }
                self::patchRow($changes, 'fleetShips', $fleetId, [$name . '_count' => $group['after'],
                    $name . '_experience' => $rewards[$side]['groups'][$id]['experience']]);
                $survivorCapacity[$fleetId] = ($survivorCapacity[$fleetId] ?? 0) + $group['after'] * $group['capacity'];
                $xpGroups[$fleetId][] = ['count' => $group['after'], 'unitCost' => $group['unitCost'],
                    'experience' => $rewards[$side]['groups'][$id]['experience']];
            }
        }
        $dead = $freeCapacity = [];
        foreach ($engaged as $fleetId => $_) {
            $participant = $state['mission']['participants'][$fleetId];
            $capacity = $survivorCapacity[$fleetId];
            $ratio = $participant['capacity'] > 0 ? min(1.0, $capacity / $participant['capacity']) : 0.0;
            $row = self::changeRow($changes, 'fleets', $fleetId);
            if ((float)$row['fuel_current'] !== $participant['fuel']) throw new \LogicException('Топливо участника изменено вне боя.');
            $fuel = $participant['fuel'] * $ratio;
            $cargoTotal = array_sum($participant['cargo']) - (float)($participant['cargo'][921] ?? 0);
            $cargoFactor = $cargoTotal > 0 ? min(1.0, max(0.0, $capacity - $fuel) / $cargoTotal) : 1.0;
            $patch = ['fuel_current' => $fuel, 'current_position_time' => max($now, (float)$row['current_position_time']),
                'experience' => FleetExperienceService::fleetAverage($xpGroups[$fleetId])];
            foreach ($participant['cargo'] as $id => $amount) {
                $field = 'resource_' . Vars::$resource[$id];
                if ((float)$row[$field] !== $amount) throw new \LogicException('Груз участника изменён вне боя.');
                $patch[$field] = (int)$id === 921 ? $amount : $amount * $cargoFactor;
            }
            self::patchRow($changes, 'fleets', $fleetId, $patch);
            $count = array_sum(array_column($xpGroups[$fleetId], 'count'));
            if ($count === 0) $dead[] = (int)$fleetId;
            elseif ($participant['side'] === 'attacker') $freeCapacity[$fleetId] = max(0.0, $capacity - $fuel - $cargoTotal * $cargoFactor);
            if ($participant['attackCommandId'] !== null) self::patchRow($changes, 'commands', $participant['attackCommandId'],
                ['executed' => 1, 'execute_time' => $now]);
        }
        $resources = self::changeRow($changes, 'resources', $planetId);
        $available = [];
        foreach ([901, 902, 903] as $id) $available[$id] = max(0.0, (float)$resources[Vars::$resource[$id]]);
        $loot = self::calculateLoot($available, $result['outcome'] === 'attacker' ? array_sum($freeCapacity) : 0.0);
        $totalCapacity = array_sum($freeCapacity);
        foreach ($loot as $resourceId => $amount) {
            $name = Vars::$resource[$resourceId];
            self::patchRow($changes, 'resources', $planetId, [$name => $available[$resourceId] - $amount]);
            $remainder = $amount;
            $remainingCapacity = $totalCapacity;
            foreach ($freeCapacity as $fleetId => $capacity) {
                $share = $remainingCapacity > 0 ? min($remainder, $remainder * $capacity / $remainingCapacity) : 0.0;
                $row = self::changeRow($changes, 'fleets', (int)$fleetId);
                self::patchRow($changes, 'fleets', (int)$fleetId, ['resource_' . $name => $row['resource_' . $name] + $share]);
                $remainder = max(0.0, $remainder - $share);
                $remainingCapacity = max(0.0, $remainingCapacity - $capacity);
            }
        }
        $planet = Planets::findExistingById($planetId);
        $formation = MoonFormationService::prepare($planet, $debris, $state['seed'], $now,
            $state['mission']['moonRules'] ?? MoonFormationService::rules());
        $moon = ['chance' => $formation['chance'], 'created' => $formation['created'],
            'reason' => $formation['reason'], 'id' => null, 'name' => null, 'diameter' => null,
            'consumedDebris' => [901 => 0.0, 902 => 0.0]];
        if ($formation['created']) {
            $row = Planets::castRowToSchema($formation['row'], true);
            $moon['id'] = (int)$row['id'];
            $moon['name'] = $row['name'];
            $moon['name_generated'] = (int)$row['name_generated'];
            $moon['diameter'] = (int)$row['size'];
            foreach (['planets' => Planets::class, 'builds' => Builds::class, 'ships' => Ships::class,
                'defenses' => Defenses::class, 'resources' => PlanetResources::class] as $key => $class) {
                if ($class::findExistingById($moon['id']) !== null) throw new \LogicException('ID новой луны уже занят.');
                // В отличие от колонии, луна не получает стартовый запас ресурсов.
                $input = ['id' => $moon['id']];
                if ($key === 'resources') foreach (['metal', 'crystal', 'deuterium', 'energy'] as $name) $input[$name] = 0.0;
                $data = $key === 'planets' ? $row : $class::castRowToSchema($input, true);
                $changes[$key . ':' . $moon['id']] = ['repository' => $key, 'id' => $moon['id'], 'before' => null, 'after' => $data];
            }
        }
        if (array_sum($debris) > 0) {
            $old = DebrisFields::at((int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet']);
            $row = $old ?? DebrisFields::castRowToSchema(['id' => DebrisFields::nextId(),
                'galaxy' => (int)$planet['galaxy'], 'system' => (int)$planet['system'], 'orbit' => (int)$planet['planet']], true);
            foreach ($debris as $id => $amount) $row[Vars::$resource[$id]] = (float)($row[Vars::$resource[$id]] ?? 0) + $amount;
            if ($formation['consumeDebris']) foreach ([901, 902] as $id) {
                $moon['consumedDebris'][$id] = $row[Vars::$resource[$id]];
                $row[Vars::$resource[$id]] = 0.0;
            }
            $row['update_time'] = $now;
            $changes['debris:' . $row['id']] = ['repository' => 'debris', 'id' => $row['id'], 'before' => $old, 'after' => $row];
        }
        $destruction = [];
        $retargetedFleets = [];
        $moonDestroyed = false;
        foreach ($state['mission']['participants'] as $fleetId => $participant) {
            if ($participant['attackCommandId'] === null) continue;
            $action = FleetCommands::getCommand($participant['attackCommandId']);
            if ((int)$action['command_type'] !== FleetCommandType::DESTROY->value) continue;
            $stars = 0;
            foreach ($result['attacker']['groups'] as $group) {
                if ($group['fleetId'] === (int)$fleetId && $group['type'] === FleetDestroyService::DEATH_STAR) $stars += $group['after'];
            }
            $attempt = FleetDestroyService::outcome((int)$planet['size'], $stars,
                $result['outcome'] === 'attacker' && !$moonDestroyed && (int)$planet['planet_type'] === 3,
                hash_hmac('sha256', 'destroy:' . $fleetId, $state['seed']));
            $destruction[] = ['fleetId' => (int)$fleetId] + $attempt;
            $moonDestroyed = $moonDestroyed || $attempt['moonDestroyed'];
            if (!$attempt['fleetDestroyed']) continue;
            $dead[] = (int)$fleetId;
            $patch = ['fuel_current' => 0.0, 'experience' => 0.0];
            foreach ($participant['cargo'] as $resourceId => $_) $patch['resource_' . Vars::$resource[$resourceId]] = 0.0;
            self::patchRow($changes, 'fleets', (int)$fleetId, $patch);
            foreach ($result['attacker']['groups'] as $group) {
                if ($group['fleetId'] !== (int)$fleetId) continue;
                $name = Vars::$resource[$group['type']];
                self::patchRow($changes, 'fleetShips', (int)$fleetId, [$name . '_count' => 0, $name . '_experience' => 0.0]);
                $losses['attacker'] += $group['after'] * $group['unitCost'];
            }
        }
        if ($moonDestroyed) foreach (FleetDestroyService::removalChanges($planet, $now) as $change) {
            $key = $change['repository'];
            $id = $change['id'];
            self::changeRow($changes, $key, $id);
            if (array_key_exists('patch', $change)) self::patchRow($changes, $key, $id, $change['patch']);
            elseif ($change['after'] === null) $changes[$key . ':' . $id]['after'] = null;
            else {
                // Не перетираем уже подготовленные потери/топливо/груз/markers полным старым row.
                $before = $changes[$key . ':' . $id]['before'];
                $patch = array_filter($change['after'], static fn($value, $field) => $value !== $before[$field], ARRAY_FILTER_USE_BOTH);
                self::patchRow($changes, $key, $id, $patch);
            }
            if ($key === 'fleets') $retargetedFleets[] = $id;
        }
        $summary = ['BattleId' => $state['id'], 'ReportVersion' => 1,
            'TargetCoordinate' => sprintf('[%d:%d:%d]', $planet['galaxy'], $planet['system'], $planet['planet']),
            'Rounds' => count($result['rounds']), 'LossesAttacker' => $losses['attacker'], 'LossesDefender' => $losses['defender'],
            'ResourcesLoot' => $loot, 'ResourcesDebris' => $debris,
            'MoonChance' => $moon['chance'],
            'MoonDestroyed' => $moonDestroyed,
            'lang' => ['Outcome' => 'battle.' . $result['outcome'], 'MoonOutcome' => 'moon.' . $moon['reason']]];
        // Архив сначала сохраняется как prepared. SQL failure не оставляет половину gameplay commit.
        BattleReports::prepare(BattleReportService::snapshot($state, $result, $restored, $rewards,
            $loot, $debris, $losses, $now, $moon, $destruction), $summary);
        foreach (self::participantOwners($state) as $ownerId) {
            $row = Notification::prepareFleetBattleReport((int)$ownerId, $summary, $now);
            $changes['messages:' . $row['id']] = ['repository' => 'messages', 'id' => $row['id'], 'before' => null, 'after' => $row];
        }
        // Не храним no-op записи: они мешают диагностике до/после commit.
        $changes = array_filter($changes, static fn($change) => $change['before'] !== $change['after']);
        return ['changes' => array_values($changes), 'resolution' => ['finishedAt' => $now, 'engaged' => $engaged,
            'dead' => array_values(array_unique($dead)), 'loot' => $loot, 'debris' => $debris, 'moon' => $moon,
            'destruction' => $destruction, 'retargetedFleets' => $retargetedFleets, 'outcome' => $result['outcome']]];
    }

    private static function changeRow(array &$changes, string $key, int $id): array
    {
        $index = $key . ':' . $id;
        if (!isset($changes[$index])) {
            $row = self::REPOSITORIES[$key]::findExistingById($id);
            if ($row === null) throw new \LogicException('Отсутствует runtime строка ' . $index);
            $changes[$index] = ['repository' => $key, 'id' => $id, 'before' => $row, 'after' => $row];
        }
        return $changes[$index]['after'];
    }

    private static function patchRow(array &$changes, string $key, int $id, array $patch): void
    {
        self::changeRow($changes, $key, $id);
        $changes[$key . ':' . $id]['after'] = array_replace($changes[$key . ':' . $id]['after'], $patch);
    }

    private static function writeChange(array $change, bool $rollback): void
    {
        $class = self::REPOSITORIES[$change['repository']];
        $row = $change[$rollback ? 'before' : 'after'];
        $current = $class::findExistingById($change['id']);
        if ($rollback && $row !== null && $change['after'] === null) {
            $class::restoreDeletedRow($row);
            return;
        }
        if ($rollback && $row !== null && $current === $row) return;
        if (!$rollback && $change['repository'] === 'planets' && $change['before'] === null) {
            $existing = Planets::findByIndex('galaxy_system_planet_type', [
                $row['galaxy'], $row['system'], $row['planet'], $row['planet_type'],
            ]);
            if ($existing !== null && (int)$existing['id'] !== $change['id']) {
                throw new \LogicException('На орбите уже существует луна.');
            }
        }
        if ($row === null) {
            // add() мог создать индексы/marker даже после set(false).
            $class::discardUncommittedRow($current ?? $change['before'] ?? $change['after']);
        } elseif ($rollback && $current === null) $class::restoreDeletedRow($row);
        elseif ($current === null) $class::add($row);
        else $class::update($row);
        if ($class::findExistingById($change['id']) !== $row) throw new \RuntimeException('Runtime запись боя не подтверждена: ' . $change['repository']);
        if ($row !== null && !in_array($change['id'], $class::getPendingSync()['update'], true)) {
            throw new \RuntimeException('Нет dirty marker записи боя: ' . $change['repository']);
        }
    }

    private static function resume(int $planetId, array $state): void
    {
        $resolution = $state['resolution'];
        foreach ($resolution['dead'] as $fleetId) {
            FleetCommands::clearFleetQueue($fleetId);
            if (FleetsShips::findExistingById($fleetId) !== null) FleetsShips::delete(['id' => $fleetId]);
            if (Fleets::findExistingById($fleetId) !== null) Fleets::delete(['id' => $fleetId]);
            FleetsData::getInstance()->remove($fleetId, false);
        }
        $deferred = $state['mission']['waiting'];
        foreach ($state['mission']['participants'] as $fleetId => $participant) {
            if (!isset($resolution['engaged'][$fleetId])) {
                $id = $participant['attackCommandId'] ?? $participant['waitCommandId'];
                if ($id !== null) $deferred[$fleetId] = ['commandId' => $id];
                continue;
            }
            if (in_array((int)$fleetId, $resolution['dead'], true)) continue;
            $fleet = FleetsData::getInstance()->get((int)$fleetId);
            if ($fleet === null) throw new \LogicException('Потерян выживший флот.');
            $waitId = $participant['waitCommandId'];
            if ($waitId !== null) self::resetWait($waitId, $resolution['finishedAt']);
            FleetDefendService::refreshRate($fleet, self::accountFor($fleet->getOwnerId()));
            if (FleetCommands::getActiveCommands((int)$fleetId) === []
                && FleetCommands::getNextPendingCommand((int)$fleetId) !== null) {
                try {
                    FleetCommandsService::activateNextCommand($fleet, self::accountFor($fleet->getOwnerId()));
                    $fleet->save();
                } catch (\LogicException $error) {
                    $next = FleetCommands::getNextPendingCommand((int)$fleetId);
                    if ($next === null || FleetCommands::getActiveCommands((int)$fleetId) !== []
                        || !in_array(FleetCommandType::from((int)$next['command_type']), [FleetCommandType::MOVE_IN_SYSTEM,
                            FleetCommandType::MOVE_BETWEEN_SYSTEMS, FleetCommandType::MOVE_BETWEEN_GALAXIES], true)) throw $error;
                    // Потеря топливных кораблей может сделать возврат невозможным.
                    // Не выдаём бесплатное топливо и не держим из-за этого чужую планету закрытой.
                    $params = json_decode($next['params'], true, 512, JSON_THROW_ON_ERROR);
                    $params['blockedAfterBattle'] = true;
                    FleetCommands::update(['id' => (int)$next['id'],
                        'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                    Logger::getInstance()->warning('Возврат флота после боя приостановлен', ['fleetId' => $fleetId, 'message' => $error->getMessage()]);
                }
            }
        }
        foreach ($deferred as $waiting) {
            $command = FleetCommands::getCommand((int)$waiting['commandId']);
            if ($command !== null && !$command['executed']) FleetCommands::update([
                'id' => (int)$command['id'], 'execute_time' => $resolution['finishedAt'],
            ]);
        }
        FleetBattleService::releaseAfterCommit($planetId, $state['id']);
    }

    public static function resetWait(int $commandId, float $time): void
    {
        $command = FleetCommands::getCommand($commandId);
        if ($command === null || $command['executed']) return;
        $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
        unset($params['battleWait']);
        if ((int)$command['command_type'] === FleetCommandType::DEFEND->value && isset($params['hold'])) {
            FleetCommands::update(['id' => $commandId, 'execute_time' => max($time, (float)$command['execute_time']),
                'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            return;
        }
        $params['startedAt'] = $time;
        FleetCommands::update(['id' => $commandId, 'execute_time' => 0.0,
            'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    private static function participantOwners(array $state): array
    {
        return array_unique(array_merge([$state['mission']['attackerId'], $state['mission']['defenderId']],
            array_column($state['mission']['participants'], 'ownerId')));
    }

    public static function fleetGroups(Fleet $fleet, AccountData $owner): array
    {
        $groups = [];
        foreach ($fleet->getShips() as $type => $ship) {
            if ($ship->getCount() > 0) $groups["fleet:{$fleet->getId()}:{$type}"] = self::group(
                (int)$type, $ship->getCount(), 'fleet', $owner, $fleet->getId(), $ship->getExperience());
        }
        return $groups;
    }

    private static function group(int $type, int $count, string $kind, AccountData $owner, int $fleetId, float $experience, int $planetId = 0): array
    {
        $price = Vars::$pricelist[$type];
        $cost = (float)($price[901] ?? 0) + (float)($price[902] ?? 0);
        $stats = FleetExperienceService::combatStats([
            'attack' => (float)(Vars::$CombatCaps[$type]['attack'] ?? 0),
            'shield' => (float)(Vars::$CombatCaps[$type]['shield'] ?? 0), 'hull' => $cost / 10,
        ], ['attack' => 1 + Factors::getFactor('Attack', $owner),
            'hull' => 1 + Factors::getFactor('Defensive', $owner), 'shield' => 1 + Factors::getFactor('Shield', $owner)],
            FleetExperienceService::average($count, $experience));
        return $stats + ['type' => $type, 'count' => $count, 'kind' => $kind, 'unitCost' => $cost,
            'experience' => $experience, 'fleetId' => $fleetId, 'planetId' => $planetId,
            'capacity' => (float)(Vars::$attributes[$type]['capacity'] ?? 0),
            'price' => [901 => (float)($price[901] ?? 0), 902 => (float)($price[902] ?? 0)]];
    }

    public static function participant(Fleet $fleet, string $side, ?int $attackCommandId = null, ?int $waitCommandId = null): array
    {
        $cargo = [];
        foreach ($fleet->getResources() as $id => $resource) $cargo[$id] = $resource->getCount();
        return ['fleetId' => $fleet->getId(), 'ownerId' => $fleet->getOwnerId(), 'side' => $side,
            'fleetName' => $fleet->getName(),
            'ownerName' => (string)(Users::findExistingById($fleet->getOwnerId())['name'] ?? ''),
            'coordinate' => ($fleet->getStatus() === Fleet::STATUS_IDLE ? $fleet->getCurrentCoordinate()
                : ($fleet->getStartCoordinate() ?? $fleet->getCurrentCoordinate()))->toArray(),
            'joinedRound' => 1,
            'attackCommandId' => $attackCommandId, 'waitCommandId' => $waitCommandId,
            'capacity' => $fleet->getCapacity(), 'fuel' => $fleet->getFuelCurrent(), 'cargo' => $cargo];
    }

    /** Вызывается игровым циклом; ошибка одного боя не останавливает остальные. */
    public static function process(float $now, ?callable $actualizeTarget = null): array
    {
        $affected = [];
        foreach (PlanetBattles::active() as $planetId => $state) {
            if (!isset($state['mission']) || !in_array($state['phase'], ['finished', 'settled'], true)) continue;
            try {
                if ($state['phase'] === 'finished' && !isset($state['application']) && $actualizeTarget !== null) {
                    $actualizeTarget($planetId, $now);
                }
                if ($state['phase'] === 'finished') self::settle($planetId, $state, $now);
                $state = PlanetBattles::state($planetId);
                if ($state['phase'] === 'settled') {
                    // При SQL failure settled-состояние остаётся для повторной публикации.
                    BattleReports::publish($state['id']);
                    self::resume($planetId, $state);
                }
                $owners = self::participantOwners($state);
                foreach ($state['resolution']['retargetedFleets'] ?? [] as $fleetId) {
                    $row = Fleets::findExistingById($fleetId);
                    if ($row !== null) $owners[] = (int)$row['owner_id'];
                }
                foreach (array_unique($owners) as $ownerId) {
                    $user = Users::findExistingById((int)$ownerId);
                    if ($user) $affected[(int)$user['account_id']] = true;
                }
            } catch (\Throwable $error) {
                $current = PlanetBattles::state($planetId);
                if ($current !== null && $current['phase'] === 'finished'
                    && ($error instanceof \LogicException || $error instanceof \OverflowException)) {
                    $current['phase'] = 'failed';
                    PlanetBattles::store($planetId, $current);
                }
                Logger::getInstance()->error('Завершение ATTACK отложено', ['planetId' => $planetId, 'message' => $error->getMessage()]);
            }
        }
        return array_keys($affected);
    }

    private static function accountFor(int $ownerId, ?int $planetId = null): AccountData
    {
        $user = Users::findExistingById($ownerId);
        if ($user === null) throw new \LogicException('Владелец участника боя недоступен.');
        $account = new AccountData((int)$user['account_id']);
        if ($planetId !== null) $account['WorkPlanet'] = $planetId;
        return $account;
    }

    private static function planetAt(Coordinate $coordinate): ?array
    {
        if ($coordinate->getType() !== CoordinateType::OBJECT || $coordinate->getObjectType() === null) return null;
        return Planets::findByIndex('galaxy_system_planet_type', [$coordinate->getGalaxy(), $coordinate->getSystem(),
            $coordinate->getOrbit(), $coordinate->getObjectType()->value]);
    }

    private static function atPlanet(Fleet $fleet, array $planet): bool
    {
        $c = $fleet->getCurrentCoordinate();
        return $c->getType() === CoordinateType::OBJECT && $c->getGalaxy() === (int)$planet['galaxy']
            && $c->getSystem() === (int)$planet['system'] && $c->getOrbit() === (int)$planet['planet']
            && $c->getObjectType()?->value === (int)$planet['planet_type'];
    }

    private static function percent(string $key, float $default): float
    {
        $value = (float)(Config::getValue($key) ?? $default);
        if (!is_finite($value) || $value < 0 || $value > 100) throw new \LogicException('Некорректный процент ' . $key);
        return $value;
    }
}
