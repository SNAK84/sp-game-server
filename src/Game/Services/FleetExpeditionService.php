<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Repositories\Factors;
use SPGame\Game\Enums\{FleetCommandType as Command, FleetMissionType as Mission};
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{BattleReports, Config, FleetCommands, FleetExpeditions as States, Fleets, FleetsShips, GalaxyOrbits, Messages, UserResources, Vars};
use SPGame\Game\Space\{Coordinate, CoordinateType};
use Swoole\Table;

/** Экспедиция: событие за каждый час, общий отчёт и локальный runtime commit. */
final class FleetExpeditionService
{
	private static ?Table $mailbox = null;
	private static ?\Closure $dispatch = null;
	private static ?\Closure $clock = null;

	public static function level(AccountData $owner): int
	{
		return max(0, (int)($owner['Techs'][Vars::$resource[124] ?? 'expedition_tech'] ?? 0));
	}

	public static function limits(AccountData $owner): array
	{
		$used = 0;
		foreach (Fleets::findByIndex('owner_id', [(int)$owner['User']['id']]) ?? [] as $row) {
			if ((int)$row['status'] !== Fleet::STATUS_IDLE && (int)$row['mission'] === Mission::EXPEDITION->value) ++$used;
		}
		return ['used' => $used] + self::limitsForLevel(self::level($owner));
	}

	/** Те же ограничения для гипотетического уровня без чтения/изменения Fleet. */
	public static function limitsForLevel(int $level): array
	{
		$level = max(0,$level);
		return ['max'=>(int)floor(sqrt($level)), 'maxHours'=>$level];
	}

	public static function duration(mixed $duration, AccountData $owner): int
	{
		$duration ??= 3600;
		if (!is_int($duration) || $duration < 3600 || $duration % 3600 !== 0 || $duration / 3600 > self::level($owner)) {
			throw new \InvalidArgumentException('Время экспедиции: целое число часов от 1 до уровня астрофизики.');
		}
		return $duration;
	}

	public static function reason(Fleet $fleet, AccountData $owner, Coordinate $target, ?Coordinate $origin = null): ?string
	{
		$current = $origin ?? $fleet->getCurrentCoordinate();
		$orbit = GalaxyOrbits::findByIndex('galaxy_system_orbit', [$target->getGalaxy(), $target->getSystem(), $target->getOrbit()]);
		if (
			$target->getType() !== CoordinateType::OBJECT || $target->getObjectType() !== null
			|| $orbit === null || (int)$orbit['type'] !== GalaxyOrbits::EXPEDITION
			|| $current->getGalaxy() !== $target->getGalaxy() || $current->getSystem() !== $target->getSystem()
		) {
			return 'Экспедиция доступна только на экспедиционной орбите своей системы.';
		}
		$limits = self::limits($owner);
		if ($limits['max'] === 0) return 'Для экспедиции нужна астрофизика 1.';
		if ($fleet->getStatus() === Fleet::STATUS_IDLE && $limits['used'] >= $limits['max']) {
			return 'Достигнут лимит экспедиций. Повысьте астрофизику.';
		}
		return null;
	}

	public static function assertAvailable(int $fleetId): void
	{
		if (in_array(States::state($fleetId)['phase'] ?? '', ['prepared', 'combat', 'settled', 'failed'], true)) {
			throw new \LogicException('Флот участвует в экспедиционном бою или применении его результата.');
		}
	}

	public static function begin(array $command, Fleet $fleet, AccountData $owner): array
	{
		$params = FleetControlService::params($command);
		$target = Coordinate::fromArray($params['target']);
		$current = $fleet->getCurrentCoordinate();
		$reason = self::reason($fleet, $owner, $target);
		if (
			$reason !== null || $current->getType() !== CoordinateType::OBJECT
			|| $current->getOrbit() !== $target->getOrbit() || $current->getObjectType() !== null
		) {
			throw new \LogicException($reason ?? 'Экспедиция ещё не достигла служебной орбиты.');
		}
		$duration = self::duration($params['duration'] ?? null, $owner);
		$time = $fleet->getCurrentPositionTime();
		$params['expedition'] = ['startedAt' => $time, 'endsAt' => $time + $duration];
		$after = array_replace($command, [
			'execute_time' => $time + 3600,
			'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
		]);
		$state = [
			'phase' => 'holding',
			'commandId' => (int)$command['id'],
			'ownerId' => $fleet->getOwnerId(),
			'seed' => bin2hex(random_bytes(32)),
			'startedAt' => $time,
			'endsAt' => $time + $duration,
			'coordinate' => $current->toArray(),
			'hourly' => true,
			'events' => [],
			'timeFactor' => 1.0
		];
		self::apply([
			[States::class, $fleet->getId(), States::row($fleet->getId(), $state)],
			[FleetCommands::class, (int)$command['id'], $after]
		]);
		return $after;
	}

	/** Источник случайности фиксируется на прибытии и не попадает в клиентский snapshot. */
	private static function random(array $state, string $label = 'outcome'): \Random\Randomizer
	{
		return new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar(hash_hmac('sha256', $label, $state['seed'], true)));
	}

	/** Nova: шесть событий по 1/9 и три исхода «ничего»; веса задаются в config. */
	public static function choose(\Random\Randomizer $random): string
	{
		$weights = [];
		foreach (['resources', 'credits', 'ships', 'combat', 'lost', 'time', 'nothing'] as $key) {
			$value = Config::getValue('ExpeditionWeight' . ucfirst($key)) ?? ($key === 'nothing' ? '3' : '1');
			if (!ctype_digit((string)$value)) throw new \LogicException('Некорректный вес события экспедиции.');
			$weights[$key] = (int)$value;
		}
		$sum = 0;
		foreach (['resources', 'credits', 'ships', 'combat', 'lost', 'time', 'nothing'] as $key) {
			$weight = $weights[$key] ?? null;
			if (!is_int($weight) || $weight < 0 || $weight > 10000) throw new \LogicException('Некорректные веса экспедиции.');
			$sum += $weight;
		}
		if ($sum === 0) throw new \LogicException('Все события экспедиции отключены.');
		$ticket = $random->getInt(1, $sum);
		foreach ($weights as $key => $weight) {
			$ticket -= $weight;
			if ($ticket <= 0) return $key;
		}
		throw new \LogicException('Не удалось выбрать событие экспедиции.');
	}

	private static function plan(Fleet $fleet, AccountData $owner, array $state): array
	{
		$hour = count($state['events'] ?? []) + 1;
		$random = self::random($state, $hour === 1 ? 'outcome' : 'outcome:' . $hour);
		$event = self::choose($random);
		$plan = [
			'event' => $event,
			'resources' => [901 => 0.0, 902 => 0.0, 903 => 0.0],
			'ships' => [],
			'credits' => 0,
			'lost' => false,
			'timeFactor' => 1.0
		];
		$points = 0.0;
		foreach ($fleet->getShips() as $id => $ship) $points += $ship->getCount()
			* ((Vars::$pricelist[$id][901] ?? 0) + (Vars::$pricelist[$id][902] ?? 0)) * .005;
		$size = $random->getInt(0, 100);
		if ($event === 'resources') {
			$factor = $size > 10 ? $random->getInt(10, 50) : ($size > 0 ? $random->getInt(50, 100) : $random->getInt(100, 200));
			$resource = [903, 902, 902, 901, 901, 901][$random->getInt(0, 5)];
			$divisor = $resource === 901 ? 1 : ($resource === 902 ? 2 : 3);
			$amount = floor(min($fleet->getFreeCapacity(), min($points, (float)(Config::getValue('ExpeditionPointsCap') ?? 2400)) * $factor / $divisor));
			$plan['resources'][$resource] = max(0.0, $amount);
		} elseif ($event === 'credits') {
			$plan['credits'] = $size > 10 ? $random->getInt(100, 300) : ($size > 0 ? $random->getInt(301, 600) : $random->getInt(601, 3000));
		} elseif ($event === 'ships') {
			$factor = $size > 10 ? $random->getInt(10, 50) : ($size > 0 ? $random->getInt(52, 100) : $random->getInt(102, 200));
			$budget = max(10000.0, $factor * min($points, 4500));
			foreach ($fleet->getShips() as $id => $ship) {
				if ($ship->getCount() <= 0 || in_array($id, [208, 209, 214], true) || $ship->getSpeed($owner) <= 0) continue;
				$cost = (Vars::$pricelist[$id][901] ?? 0) + (Vars::$pricelist[$id][902] ?? 0);
				if ($cost <= 0) continue;
				$count = $random->getInt(0, (int)min(PHP_INT_MAX - $ship->getCount(), floor($budget / $cost)));
				if ($count > 0) {
					$plan['ships'][$id] = $count;
					$budget -= $count * $cost;
				}
			}
		} elseif ($event === 'lost') $plan['lost'] = true;
		elseif ($event === 'time') {
			$factor = [2, 2, 2, 2, 2, 2, 2, 3, 3, 5][$random->getInt(0, 9)];
			$plan['timeFactor'] = $random->getInt(0, 100) < 75 ? (float)$factor : 1.0 / $factor;
		} elseif ($event === 'combat') {
			$aliens = $random->getInt(1, 2) === 2;
			$plan['enemy'] = $aliens ? 'aliens' : 'pirates';
			$scale = ($aliens ? [40, 60, 90] : [30, 50, 80])[$size > 10 ? 0 : ($size > 0 ? 1 : 2)];
			$scale = ($scale + $random->getInt(-(int)($scale / 10), (int)($scale / 10))) / 100;
			$attackers = FleetAttackService::fleetGroups($fleet, $owner);
			$defenders = [];
			foreach ($attackers as $group) {
				$count = (int)($aliens ? ceil($group['count'] * $scale) : floor($group['count'] * $scale));
				if ($count <= 0) continue;
				$type = $group['type'];
				$cost = $group['unitCost'];
				$bonus = $aliens ? .3 : -.3;
				$group = array_replace($group, [
					'count' => $count,
					'fleetId' => 0,
					'experience' => 0.0,
					'attack' => (float)(Vars::$CombatCaps[$type]['attack'] ?? 0) * max(1.0, 1 + Factors::getFactor('Attack', $owner) + $bonus),
					'shield' => (float)(Vars::$CombatCaps[$type]['shield'] ?? 0) * max(1.0, 1 + Factors::getFactor('Shield', $owner) + $bonus),
					'hull' => $cost / 10 * max(1.0, 1 + Factors::getFactor('Defensive', $owner) + $bonus)
				]);
				$defenders['npc:' . $type] = $group;
			}
			$plan['battle'] = FleetCombatService::startBattle($attackers, $defenders, Vars::$rapidfire ?? []);
			$plan['battleId'] = bin2hex(random_bytes(16));
			$plan['fleetBefore'] = Fleets::getById($fleet->getId());
			$plan['shipsBefore'] = FleetsShips::findExistingById($fleet->getId());
		}
		return $plan;
	}

	public static function complete(array $command, Fleet $fleet, AccountData $owner): bool
	{
		try {
			return self::resolve($command, $fleet, $owner);
		} catch (\Throwable $error) {
			// Сбой архива/хранилища не должен каждый tick блокировать остальные Fleet events.
			try {
				$current = FleetCommands::getCommand((int)$command['id']);
				if ($current !== null && !(int)$current['executed']) {
					$now = self::$clock === null ? microtime(true) : (self::$clock)();
					FleetControlService::patchCommand((int)$command['id'], [
						'execute_time' => max($now, (float)$current['execute_time']) + 5,
					]);
				}
			} catch (\Throwable $retry) {
				Logger::getInstance()->critical('Не удалось отложить retry экспедиции', [
					'fleetId' => $fleet->getId(),
					'original' => $error->getMessage(),
					'retry' => $retry->getMessage(),
				]);
			}
			Logger::getInstance()->error('Результат экспедиции ожидает повторной обработки', [
				'fleetId' => $fleet->getId(),
				'message' => $error->getMessage(),
			]);
			throw $error;
		}
	}

	private static function resolve(array $command, Fleet $fleet, AccountData $owner): bool
	{
		$current = FleetCommands::getCommand((int)$command['id']);
		if ($current === null || (int)$current['executed'] !== 0) return true;
		if ((float)$current['execute_time'] !== (float)$command['execute_time']) return true;
		$state = States::state($fleet->getId());
		if ($state === null || $state['commandId'] !== (int)$command['id']) throw new \LogicException('Потеряно состояние экспедиции.');
		$now = max((float)$current['execute_time'], $fleet->getCurrentPositionTime());
		$state['events'] ??= [];
		$state['timeFactor'] ??= 1.0;
		// Старый уже рассчитанный исход не перебрасывается после обновления сервера.
		$eventAt = !isset($state['hourly']) && isset($state['plan']) ? $state['endsAt']
			: $state['startedAt'] + (count($state['events']) + 1) * 3600;
		if ($now < $eventAt) {
			// После MySQL reload числовой execute_time может округлиться раньше
			// deadline в JSON checkpoint. Это ожидание, а не сбой результата.
			// Не выдаём находку раньше часа и не сдвигаем логическое время retry-ем.
			FleetControlService::patchCommand((int)$current['id'], ['execute_time' => $eventAt]);
			return true;
		}
		$state['eventAt'] = $eventAt;
		if (!isset($state['hourly']) && $state['phase'] === 'settled' && !empty($state['plan']['credits'])) {
			$row = Fleets::getById($fleet->getId());
			$row['resource_credit'] = (float)($row['resource_credit'] ?? 0) + $state['plan']['credits'];
			$state['hourly'] = true;
			self::apply([[Fleets::class, $fleet->getId(), $row], [States::class, $fleet->getId(), States::row($fleet->getId(), $state)]]);
			$fleet->reloadFromRuntime();
		}
		$state['hourly'] = true;
		if (!isset($state['plan'])) {
			$state['plan'] = self::plan($fleet, $owner, $state);
			$state['phase'] = isset($state['plan']['battle']) ? 'combat' : 'prepared';
			States::store($fleet->getId(), $state);
		}
		if ($state['phase'] === 'failed') return true;
		if ($state['phase'] === 'combat') {
			// После простоя сервера нельзя догонять часы прошлого по одному poll в секунду.
			// Событие результата остаётся логическим, ожидание CPU планируется от wall clock.
			$now = max($now, self::$clock === null ? microtime(true) : (self::$clock)());
			try {
				if (!self::round($state, $fleet, $current, $now)) return true;
			} catch (\Throwable $error) {
				$state['phase'] = 'failed';
				$state['error'] = substr($error->getMessage(), 0, 512);
				States::store($fleet->getId(), $state);
				Logger::getInstance()->error('Экспедиционный бой остановлен без применения потерь', ['fleetId' => $fleet->getId(), 'message' => $error->getMessage()]);
				return true;
			}
		}
		if ($state['phase'] !== 'settled') {
			$state = self::settle($state, $fleet, $owner, $now);
		}
		if (isset($state['report'])) BattleReports::publish($state['plan']['battleId']);
		$result = $state['plan'];
		unset($result['battle'], $result['fleetBefore'], $result['shipsBefore']);
		$result['at'] = $eventAt;
		$state['events'][] = $result;
		if ($result['timeFactor'] !== 1.0) $state['timeFactor'] = $result['timeFactor'];
		if (!$result['lost'] && $eventAt < $state['endsAt']) {
			$state['phase'] = 'holding';
			$state['hourly'] = true;
			unset($state['plan'], $state['report'], $state['job'], $state['attempts']);
			self::apply([
				[States::class, $fleet->getId(), States::row($fleet->getId(), $state)],
				[FleetCommands::class, (int)$current['id'], array_replace($current, ['execute_time' => $eventAt + 3600])]
			]);
			return true;
		}
		$state['phase'] = 'returning';
		$state['endReason'] = $result['lost'] ? 'lost' : 'completed';
		$state['summarySent'] = true;
		unset($state['plan']['battle'], $state['plan']['fleetBefore'], $state['plan']['shipsBefore'], $state['report'], $state['job']);
		$message = Notification::prepareFleetExpeditionSummary($owner, $fleet, $state, $now);
		$changes = [[Messages::class, (int)$message['id'], $message]];
		if ($state['plan']['lost']) {
			foreach (FleetCommands::getFleetCommandChain($fleet->getId()) as $row) $changes[] = [FleetCommands::class, (int)$row['id'], null];
			$changes[] = [FleetsShips::class, $fleet->getId(), null];
			$changes[] = [Fleets::class, $fleet->getId(), null];
			$changes[] = [States::class, $fleet->getId(), null];
		} else {
			$changes[] = [FleetCommands::class, (int)$current['id'], array_replace($current, ['executed' => 1])];
			$changes[] = [States::class, $fleet->getId(), States::row($fleet->getId(), $state)];
		}
		self::apply($changes);
		try {
			Notification::pushMessages($owner);
		} catch (\Throwable $error) {
			Logger::getInstance()->error('Push результата экспедиции отложен', ['message' => $error->getMessage()]);
		}
		if ($state['plan']['lost']) {
			FleetsData::getInstance()->remove($fleet->getId(), false);
			return true;
		}
		$fleet->reloadFromRuntime();
		try {
			FleetCommandsService::activateNextCommand($fleet, $owner);
		} catch (\Throwable $error) {
			$pending = FleetCommands::getNextPendingCommand($fleet->getId());
			if ($pending !== null && FleetCommands::getActiveCommand($fleet->getId()) === null) FleetControlService::pause($pending, 'activation');
			Logger::getInstance()->error('Возврат после экспедиции ожидает повторной активации', ['fleetId' => $fleet->getId(), 'message' => $error->getMessage()]);
		}
		return true;
	}

	private static function settle(array $state, Fleet $fleet, AccountData $owner, float $time): array
	{
		$plan = &$state['plan'];
		$row = Fleets::getById($fleet->getId());
		$ships = FleetsShips::findExistingById($fleet->getId());
		$changes = [];
		if (isset($plan['battle'])) {
			if ($row !== $plan['fleetBefore'] || $ships !== $plan['shipsBefore']) throw new \LogicException('Участник экспедиционного боя изменён вне расчёта.');
			$battle = FleetCombatService::getResult($plan['battle']);
			$inputs = FleetCombatService::experienceInputs($battle);
			$rewards = [];
			foreach ($inputs as $side => $input) $rewards[$side] = FleetExperienceService::calculateRewards(...$input);
			$capacity = 0.0;
			$count = 0;
			$xpGroups = [];
			$losses = ['attacker' => 0.0, 'defender' => 0.0];
			foreach ($battle['attacker']['groups'] as $id => $group) {
				$name = Vars::$resource[$group['type']];
				$ships[$name . '_count'] = $group['after'];
				$ships[$name . '_experience'] = $rewards['attacker']['groups'][$id]['experience'];
				$capacity += $group['after'] * $group['capacity'];
				$count += $group['after'];
				$xpGroups[] = ['count' => $group['after'], 'unitCost' => $group['unitCost'], 'experience' => $ships[$name . '_experience']];
				$losses['attacker'] += ($group['before'] - $group['after']) * $group['unitCost'];
			}
			foreach ($battle['defender']['groups'] as $group) $losses['defender'] += ($group['before'] - $group['after']) * $group['unitCost'];
			$ratio = $fleet->getCapacity() > 0 ? min(1.0, $capacity / $fleet->getCapacity()) : 0.0;
			$row['fuel_current'] *= $ratio;
			$cargo = $fleet->getUsedCapacity();
			$cargoFactor = $cargo > 0 ? min(1.0, max(0.0, $capacity - $row['fuel_current']) / $cargo) : 1.0;
			foreach ($fleet->getResources() as $id => $resource) {
				if ($id !== 921) $row['resource_' . Vars::$resource[$id]] *= $cargoFactor;
			}
			$row['experience'] = FleetExperienceService::fleetAverage($xpGroups);
			$plan['lost'] = $count === 0;
			$plan['outcome'] = $battle['outcome'];
			$plan['rounds'] = count($battle['rounds']);
			$state['report'] = self::battleReport($state, $fleet, $owner, $battle, $rewards, $losses, $time);
			BattleReports::prepare($state['report'], [
				'TargetCoordinate' => self::label($state['coordinate']),
				'Outcome' => $battle['outcome'],
				'ReportVersion' => 1,
				'BattleId' => $plan['battleId']
			]);
		}
		foreach ($plan['resources'] as $id => $amount) $row['resource_' . Vars::$resource[$id]] += $amount;
		$row['resource_credit'] = (float)($row['resource_credit'] ?? 0) + $plan['credits'];
		// Найденные корабли имеют нулевой опыт и собственный запас на путь домой.
		if ($plan['ships'] !== []) {
			$extra = self::foundFuel($fleet, $owner, $plan['ships']);
			$capacity = $fleet->getCapacity();
			foreach ($plan['ships'] as $id => $count) $capacity += $count * $fleet->getShips()[$id]->getCapacity();
			if ($fleet->getUsedCapacity() + $row['fuel_current'] + $extra > $capacity) {
				// Не принимаем находку, которую нельзя безопасно разместить вместе с топливом.
				$plan['ships'] = [];
			} else {
				foreach ($plan['ships'] as $id => $count) $ships[Vars::$resource[$id] . '_count'] += $count;
				$row['fuel_current'] += $extra;
				$row['fuel_total'] = max($row['fuel_total'], $row['fuel_current']);
				$plan['foundFuel'] = $extra;
				$groups = [];
				foreach ($fleet->getShips() as $id => $ship) $groups[] = [
					'count' => $ships[Vars::$resource[$id] . '_count'],
					'experience' => $ship->getExperience(),
					'unitCost' => (Vars::$pricelist[$id][901] ?? 0) + (Vars::$pricelist[$id][902] ?? 0),
				];
				$row['experience'] = FleetExperienceService::fleetAverage($groups);
			}
		}
		$row['current_position_time'] = $time;
		$state['phase'] = 'settled';
		$changes[] = [FleetsShips::class, $fleet->getId(), $ships];
		$changes[] = [Fleets::class, $fleet->getId(), $row];
		if ($plan['timeFactor'] !== 1.0) {
			foreach (FleetCommands::getFleetQueue($fleet->getId()) as $next) {
				if ((int)$next['command_type'] !== Command::MOVE_IN_SYSTEM->value) continue;
				$params = FleetControlService::params($next);
				$params['expeditionTimeFactor'] = $plan['timeFactor'];
				$changes[] = [FleetCommands::class, (int)$next['id'], array_replace($next, ['params' => json_encode($params, JSON_THROW_ON_ERROR)])];
				break;
			}
		}
		$changes[] = [States::class, $fleet->getId(), States::row($fleet->getId(), $state)];
		self::apply($changes);
		$fleet->reloadFromRuntime();
		return $state;
	}

	private static function foundFuel(Fleet $fleet, AccountData $owner, array $found): float
	{
		$extraShips = [];
		foreach ($found as $id => $count) {
			$old = $fleet->getShips()[$id];
			$extraShips[$id] = new \SPGame\Game\Fleet\FleetShip($id, $old->getShipTypeName(), $count, 0, 0, 1, 0);
		}
		$home = \SPGame\Game\Repositories\Planets::findExistingById($fleet->getHomePlanetId());
		$flight = new FleetFlightService();
		$start = $flight->resolvePhysicalPoint($fleet->getCurrentCoordinate(), $fleet->getCurrentPositionTime());
		$homePoint = $flight->resolvePhysicalPoint(Coordinate::object(
			(int)$home['galaxy'],
			(int)$home['system'],
			(int)$home['planet'],
			\SPGame\Game\Space\ObjectType::from((int)$home['planet_type'])
		), $fleet->getCurrentPositionTime());
		$distance = $flight->calculateInSystemGameDistance($start->radius + $homePoint->radius);
		$factor = 10.0;
		foreach (FleetCommands::getFleetQueue($fleet->getId()) as $row) {
			if ((int)$row['command_type'] === Command::MOVE_IN_SYSTEM->value) {
				$factor = (float)(FleetControlService::params($row)['speedFactor'] ?? 10);
				break;
			}
		}
		return $flight->calculateFuelConsumption($fleet, $owner, [$distance], $factor, $extraShips);
	}

	public static function finishReturn(Fleet $fleet): ?array
	{
		$state = States::state($fleet->getId());
		if ($state === null) return null;
		$credits = (float)(($fleet->getResources()[921] ?? null)?->getCount() ?? 0);
		// Совместимость с находками предыдущей одно-событийной реализации.
		if (!isset($state['hourly']) && empty($state['creditsDelivered'])) $credits += (float)($state['plan']['credits'] ?? 0);
		if ($credits > 0 || empty($state['creditsDelivered'])) {
			$changes = [];
			if ($credits > 0) {
				$row = UserResources::findExistingById($fleet->getOwnerId());
				if ($row === null) throw new \LogicException('Не найден счёт владельца экспедиции.');
				$row['credit'] += $credits;
				$changes[] = [UserResources::class, $fleet->getOwnerId(), $row];
			}
			$state['creditsDelivered'] = true;
			$fleetRow = Fleets::getById($fleet->getId());
			$fleetRow['resource_credit'] = 0.0;
			$changes[] = [Fleets::class, $fleet->getId(), $fleetRow];
			$changes[] = [States::class, $fleet->getId(), States::row($fleet->getId(), $state)];
			self::apply($changes);
			$fleet->reloadFromRuntime();
		}
		return ['credits' => $credits];
	}

	/** Вызывается только после успешного commit штатного отзыва. */
	public static function recalled(Fleet $fleet, AccountData $owner, float $time): void
	{
		$state = States::state($fleet->getId());
		if ($state === null || !empty($state['summarySent'])) return;
		$state['phase'] = 'returning';
		$state['endReason'] = 'recalled';
		States::store($fleet->getId(), $state);
		self::sendPendingSummary($fleet, $owner, $time);
	}

	public static function sendPendingSummary(Fleet $fleet, AccountData $owner, float $time): void
	{
		$state = States::state($fleet->getId());
		if ($state === null || !empty($state['summarySent']) || empty($state['events'])) return;
		$message = Notification::prepareFleetExpeditionSummary($owner, $fleet, $state, $time);
		$state['summarySent'] = true;
		self::apply([
			[Messages::class, (int)$message['id'], $message],
			[States::class, $fleet->getId(), States::row($fleet->getId(), $state)]
		]);
		Notification::pushMessages($owner);
	}

	public static function returnFactor(int $fleetId): float
	{
		return (float)(States::state($fleetId)['timeFactor'] ?? 1.0);
	}

	public static function clear(int $fleetId): void
	{
		if (States::state($fleetId) !== null) States::delete(['id' => $fleetId]);
	}

	/** Компенсация только явно перечисленных строк; gameplay SQL-транзакций нет. */
	private static function apply(array $changes): void
	{
		$written = [];
		try {
			foreach ($changes as [$class, $id, $after]) {
				$before = $class::findExistingById($id);
				$written[] = [$class, $id, $before, $after];
				if ($after === null) $class::delete(['id' => $id]);
				elseif ($before === null) $class::add($after);
				else $class::update($after);
				if ($class::findExistingById($id) !== $after) throw new \RuntimeException('Runtime запись экспедиции не подтверждена: ' . $class);
			}
		} catch (\Throwable $error) {
			foreach (array_reverse($written) as [$class, $id, $before, $after]) {
				try {
					if ($before === null) $class::discardUncommittedRow($after ?? ['id' => $id]);
					elseif ($class::findExistingById($id) === null) $class::restoreDeletedRow($before);
					else $class::update($before);
					if ($class::findExistingById($id) !== $before) throw new \RuntimeException('Строка не восстановлена.');
				} catch (\Throwable $rollback) {
					Logger::getInstance()->critical('Rollback экспедиции не выполнен', ['class' => $class, 'id' => $id, 'original' => $error->getMessage(), 'rollback' => $rollback->getMessage()]);
				}
			}
			throw $error;
		}
	}

	public static function initializeWorkers(callable $dispatch, ?callable $clock = null): void
	{
		self::$dispatch = \Closure::fromCallable($dispatch);
		self::$clock = $clock === null ? null : \Closure::fromCallable($clock);
		self::$mailbox = new Table(16);
		self::$mailbox->column('payload', Table::TYPE_STRING, States::MAX_BYTES);
		if (!self::$mailbox->create()) throw new \RuntimeException('Не удалось создать mailbox экспедиций.');
	}

	public static function executeTask(array $task): void
	{
		if (($task['kind'] ?? '') !== 'expedition_round' || !preg_match('/^[a-f0-9]{32}$/D', $task['token'] ?? '')) throw new \InvalidArgumentException('Некорректное задание экспедиционного боя.');
		try {
			$random = new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar(hex2bin($task['seed'])));
			$reply = ['combat' => FleetCombatService::calculateRound($task['combat'], static fn($min, $max) => $random->getInt($min, $max))];
			$encoded = json_encode($reply, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
			if (strlen($encoded) > States::MAX_BYTES) throw new \OverflowException('Результат экспедиционного раунда слишком велик.');
		} catch (\Throwable $error) {
			$encoded = json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
		}
		if (
			self::$mailbox === null || !self::$mailbox->set($task['token'], ['payload' => $encoded])
			|| self::$mailbox->get($task['token'])['payload'] !== $encoded
		) throw new \RuntimeException('Не удалось передать результат экспедиционного раунда.');
	}

	private static function round(array &$state, Fleet $fleet, array $command, float $now): bool
	{
		if (FleetCombatService::isFinished($state['plan']['battle'])) return true;
		if (self::$dispatch === null || self::$mailbox === null) throw new \LogicException('TaskWorkers экспедиции не инициализированы.');
		$job = $state['job'] ?? null;
		if ($job !== null) {
			$reply = self::$mailbox->get($job['token']);
			if ($reply !== false) {
				$reply = json_decode($reply['payload'], true, 512, JSON_THROW_ON_ERROR);
				if (isset($reply['error'])) throw new \RuntimeException($reply['error']);
				if (count($reply['combat']['rounds']) !== $job['round']) throw new \LogicException('Неверный номер экспедиционного раунда.');
				$state['plan']['battle'] = $reply['combat'];
				unset($state['job']);
				States::store($fleet->getId(), $state);
				self::$mailbox->del($job['token']);
				if (FleetCombatService::isFinished($state['plan']['battle'])) return true;
			} elseif ($now >= $job['submittedAt'] + FleetBattleService::JOB_TIMEOUT) {
				self::$mailbox->del($job['token']);
				unset($state['job']);
				$state['attempts'] = ($state['attempts'] ?? 0) + 1;
				if ($state['attempts'] > 3) throw new \RuntimeException('Экспедиционный бой не рассчитан после трёх попыток.');
			}
		}
		if (!isset($state['job'])) {
			// Общий TaskWorker pool не заполняется неограниченным числом экспедиций.
			$tokens = [];
			foreach (States::getAll() ?? [] as $row) {
				$other = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
				if ($other['phase'] === 'combat' && isset($other['job'])) $tokens[$other['job']['token']] = true;
			}
			foreach (self::$mailbox as $token => $_) if (!isset($tokens[$token])) self::$mailbox->del((string)$token);
			if (count($tokens) >= 2) {
				FleetControlService::patchCommand((int)$command['id'], ['execute_time' => $now + 1]);
				return false;
			}
			$round = count($state['plan']['battle']['rounds']) + 1;
			$token = bin2hex(random_bytes(16));
			$state['job'] = ['token' => $token, 'round' => $round, 'submittedAt' => $now];
			States::store($fleet->getId(), $state);
			if (!(self::$dispatch)([
				'kind' => 'expedition_round',
				'token' => $token,
				'combat' => $state['plan']['battle'],
				'seed' => hash_hmac('sha256', count($state['events'] ?? []) . ':' . $round, $state['seed'])
			])) throw new \RuntimeException('Не удалось отправить экспедиционный раунд.');
		}
		self::apply([
			[States::class, $fleet->getId(), States::row($fleet->getId(), $state)],
			[FleetCommands::class, (int)$command['id'], array_replace($command, ['execute_time' => $now + 1])]
		]);
		return false;
	}

	private static function battleReport(array $state, Fleet $fleet, AccountData $owner, array $battle, array $rewards, array $losses, float $time): array
	{
		$enemy = Lang::load($owner['Account']['lang'] ?? 'ru')['notifications']['expedition'][$state['plan']['enemy']];
		$snapshot = [
			'id' => $state['plan']['battleId'],
			'startedAt' => $state['eventAt'] ?? $state['endsAt'],
			'mission' => [
				'spaceTarget' => ['name' => 'Экспедиционная орбита', 'coordinate' => $state['coordinate']],
				'participants' => [
					$fleet->getId() => [
						'side' => 'attacker',
						'fleetId' => $fleet->getId(),
						'ownerId' => $fleet->getOwnerId(),
						'fleetName' => $fleet->getName(),
						'ownerName' => $owner['User']['name'],
						'coordinate' => $fleet->getStartCoordinate()?->toArray()
					],
					0 => ['side' => 'defender', 'fleetId' => 0, 'ownerId' => 0, 'fleetName' => $enemy, 'ownerName' => $enemy, 'coordinate' => $state['coordinate']]
				]
			]
		];
		return BattleReportService::snapshot($snapshot, $battle, [], $rewards, [901 => 0, 902 => 0, 903 => 0], [901 => 0, 902 => 0], $losses, $time);
	}

	public static function label(array $coordinate): string
	{
		return sprintf('[%d:%d:%d]', $coordinate['galaxy'], $coordinate['system'], $coordinate['orbit']);
	}
}
