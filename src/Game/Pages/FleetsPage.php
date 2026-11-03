<?php

namespace SPGame\Game\Pages;

use SPGame\Core\Logger;

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Enums\FleetMissionType;

use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\Fleets;
use SPGame\Game\Repositories\FleetsShips;
use SPGame\Game\Repositories\Config;
use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\PlanetBattles;
use SPGame\Game\Repositories\SpaceBattles;
use SPGame\Game\Repositories\PlayerQueue;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Vars;

use SPGame\Game\Services\FleetCommandsService;
use SPGame\Game\Services\FleetBattleService;
use SPGame\Game\Services\FleetAttackService;
use SPGame\Game\Services\FleetCombatService;
use SPGame\Game\Services\FleetFlightService;
use SPGame\Game\Services\FleetControlService;
use SPGame\Game\Services\FleetMissionService;
use SPGame\Game\Services\FleetRendezvousService;
use SPGame\Game\Services\ResourceBaseService;
use SPGame\Game\Services\FleetFunctions;
use SPGame\Game\Services\FleetsServices;
use SPGame\Game\Services\GalaxyGenerator;

use SPGame\Game\Services\AccountData;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;


class FleetsPage extends AbstractPage
{
    public function render(AccountData &$AccountData): array
    {

        $AvailableMissions = null;
        $TravelInfo = null;
        $NavigationSystem = null;
        $Target = $this->Msg->getData('target', []);
        if (!is_array($Target)) {
            $Target = [];
        }

        Logger::getInstance()->info("FleetsPage getAction", [$this->Msg->getAction()]);


        if ($this->Msg->getAction() === 'GetAvailableMissions') {

            $coordinates = $this->Msg->getData('coordinates', []);

            // Coordinate target: OBJECT / POINT / FLEET без client-side lastKnownPosition.
            Logger::getInstance()->info("coordinates", $coordinates);

            $fleet = $AccountData->getFleetsData()->get(
                (int)$this->Msg->getData('fleetId')
            );
            if ($fleet !== null) {
                $forPlan = $this->Msg->getData('forPlan', false) === true;
                if ($forPlan && $this->Msg->getData('planReturn', false) === true) {
                    $coordinates = \SPGame\Game\Services\ResourceBaseService::homeCoordinate($fleet)->toArray();
                }
                $target = FleetTarget::fromCoordinates($coordinates);
                $speedFactor = max(1.0, min(
                    10.0,
                    (float)$this->Msg->getData('speedFactor', 10)
                ));
                $missionType = FleetMissionType::tryFrom(
                    (int)$this->Msg->getData('missionType', 0)
                );
                if ($forPlan) {
                    FleetControlService::assertAction($fleet, $AccountData, 'SetFleetCommands');
                    // Только read-only preview. При commit origin выводится из всего плана заново.
                    $originData = $this->Msg->getData('origin', []);
                    if ($originData === []) $origin = FleetControlService::planOrigin($fleet);
                    elseif ((int)($originData['type'] ?? 0) === CoordinateType::FLEET->value) {
                        try {
                            $origin = FleetRendezvousService::coordinate($fleet->getOwnerId(), (int)($originData['fleetId'] ?? 0), microtime(true), $fleet->getId());
                        } catch (\InvalidArgumentException|\LogicException) {
                            $observed = \SPGame\Game\Services\FleetInterceptionService::observation($fleet->getOwnerId(), (int)($originData['fleetId'] ?? 0), microtime(true));
                            $origin = Coordinate::fromArray($observed['position']);
                        }
                    } else $origin = Coordinate::fromArray($originData);
                    if ($origin->getType() === CoordinateType::FLEET) $origin = $origin->getLastKnownPosition();
                    $service = new FleetMissionService();
                    $AvailableMissions = $service->getAvailableMissions($fleet, $target, $AccountData, $origin);
                    $samePlace = $origin->getType() === $target->getCoordinateType()
                        && $origin->getGalaxy() === $target->getGalaxy() && $origin->getSystem() === $target->getSystem()
                        && ($origin->getType() === CoordinateType::OBJECT
                            ? $origin->getOrbit() === $target->getOrbit() && $origin->getObjectType() === $target->getObjectType()
                                && $origin->getSiteId() === $target->getSiteId()
                            : $origin->getType() === CoordinateType::POINT && $origin->getDistance() === $target->getDistance()
                                && $origin->getAngle() === $target->getDeg());
                    // Только один участок: возврат является отдельным этапом плана, без двойного учёта топлива.
                    $TravelInfo = !empty($AvailableMissions['disabled']) ? null : ($samePlace
                        ? ['fuel' => ['min' => 0.0, 'max' => 0.0], 'distance' => ['min' => 0, 'max' => 0], 'duration' => ['min' => 0, 'max' => 0]]
                        : $service->getTravelInfo($fleet, $target, $AccountData, $speedFactor, null, $origin));
                    if ($TravelInfo !== null && $missionType === FleetMissionType::RESEARCH && in_array($missionType->value, $AvailableMissions['missions'] ?? [], true)) {
                        $TravelInfo['survey'] = \SPGame\Game\Services\FleetSurveyService::estimate($fleet, $AccountData, Coordinate::fromArray($target->toCoordinates()));
                        foreach (['min', 'max'] as $bound) $TravelInfo['fuel'][$bound] += $TravelInfo['survey']['fuel'];
                    }
                } else {
                    $AvailableMissions = $fleet->getAvailableMissions($target, $AccountData);
                    $TravelInfo = $fleet->getTravelInfo(
                        $target,
                        $AccountData,
                        $speedFactor,
                        $missionType
                    );
                    if ($TravelInfo !== null && $missionType === FleetMissionType::DEFEND) {
                        $TravelInfo['defendSupplyPerHour'] = \SPGame\Game\Services\FleetDefendService::fuelRate($fleet, $AccountData) * 3600;
                    }
                }
                if ($TravelInfo !== null && $missionType === FleetMissionType::DEFEND
                    && \SPGame\Game\Services\FleetSurveyService::isResource(Coordinate::fromArray($target->toCoordinates()))) {
                    $TravelInfo = \SPGame\Game\Services\FleetResourceDefendService::travelFuel($TravelInfo,
                        \SPGame\Game\Services\FleetDefendService::fuelRate($fleet,$AccountData),
                        \SPGame\Game\Services\FleetDefendService::duration($this->Msg->source()['data']['duration'] ?? null));
                }
                if ($TravelInfo !== null && $missionType === FleetMissionType::MINING) {
                    $input = $this->Msg->source()['data']['mining'] ?? [];
                    if (!is_array($input)) throw new \InvalidArgumentException('Некорректные настройки добычи.');
                    $estimate = \SPGame\Game\Services\FleetMiningRuntimeService::preview($fleet, $AccountData,
                        Coordinate::fromArray($target->toCoordinates()), $input);
                    foreach (['min', 'max'] as $bound) $TravelInfo['fuel'][$bound] += $estimate['workFuel'];
                    $TravelInfo['mining'] = $estimate;
                }
            }
        }

        if ($this->Msg->getAction() === 'GetSystemNavigationInfo') {
            $NavigationSystem = $this->buildNavigationSystemInfo($AccountData);
        }

        $Planet = &$AccountData['Planet'];

        // Список флотов игрока (активные, летящие, стоящие)
        $FleetList = $this->buildFleetList($AccountData);
        $FlyingFleets = $this->buildFlyingFleetList($AccountData);

        // Список кораблей на планете (в ангаре)
        $PlanetShips = $this->buildPlanetShips($AccountData);

        return [
            'page'          => 'fleet',
            'planet_id'     => $Planet['id'],
            'FleetList'     => $FleetList,
            'ResourceBases' => ResourceBaseService::listFor($AccountData),
            'FlyingFleets'  => $FlyingFleets,
            'FleetLimits'   => FleetsServices::getLimits($AccountData),
            'FleetCapabilities' => \SPGame\Game\Services\FleetControlService::capabilities($AccountData),
            'FleetTargets' => \SPGame\Game\Services\FleetRendezvousService::targets((int)$AccountData['User']['id'], microtime(true)),
            'PlanetShips'   => $PlanetShips,
            'FleetTypes'    => Vars::$reslist['nametype']['fleet'],
            'FleetMissions' => FleetMissionType::list(),
            'AvailableMissions' => $AvailableMissions,
            'TravelInfo'    => $TravelInfo,
            'NavigationSystem' => $NavigationSystem,
            'FleetCommands' => FleetCommandType::list(),
            'Target'        => $Target,
            'DefenseHours' => \SPGame\Game\Services\FleetDefendService::HOURS,
            'MiningRules' => \SPGame\Game\Services\FleetMiningRuntimeService::rules(),
            'ExpeditionLimits' => \SPGame\Game\Services\FleetExpeditionService::limits($AccountData),
            'AttackGroups' => \SPGame\Game\Services\FleetGroupAttackService::listFor((int)$AccountData['User']['id'], microtime(true)),
            'AttackFriends' => array_values(array_filter(\SPGame\Game\Services\FriendsService::list((int)$AccountData['User']['id']),
                static fn($friend) => $friend['accepted'])),
        ];
    }

    /**
     * Собирает список флотов игрока
     */
    private function buildFleetList(AccountData &$AccountData): array
    {
        $fleets = $AccountData->getFleetsData()->getForPlanet(
            (int) $AccountData['User']['id'],
            (int) $AccountData['Planet']['id']
        );
        if (ResourceBaseService::isEnabled()) {
            foreach ($AccountData->getFleetsData()->getForAccount((int)$AccountData['User']['id']) as $fleet) {
                if ($fleet->getHomeSiteId() > 0 && $fleet->getStatus() === Fleet::STATUS_IDLE) $fleets[$fleet->getId()] = $fleet;
            }
        }

        $FleetList = [];
        $planetBattles = PlanetBattles::active();

        foreach ($fleets as $fleet) {
            if ($fleet === null) {
                continue;
            }

            $shipsNormalized = [];

            $countTotal = 0;
            $types = 0;
            $capacityTotal = 0.0;
            $consumptionTotal = 0.0;
            $speedList = [];

            // Перебираем фактический состав доменного Fleet.
            foreach ($fleet->getShips() as $ShipId => $ship) {
                $ShipId = (int) $ShipId;
                //$name = Vars::$resource[$ShipId] ?? (string) $ShipId;

                $shipCount = $ship->getCount();
                if ($shipCount <= 0) {
                    // сохраним нулевой корабль (опционально) — но для клиента лучше не класть нулевые элементы
                    continue;
                }

                // Атрибуты корабля из Vars
                //$attr = Vars::$attributes[$ShipId] ?? [];
                //$shipCapacity = $attr['capacity'] ?? 0.0;

                // Функции расчёта, использующие AccountData (например модификаторы)
                //$shipSpeed = FleetFunctions::GetShipSpeed($ShipId, $AccountData);
                //$shipConsumption = FleetFunctions::GetShipConsumption($ShipId, $AccountData);

                // Нормализованный объект корабля
                $shipsNormalized[$ShipId] = [
                    'id'          => $ShipId,
                    'name'        => $ship->getShipTypeName(),
                    'count'       => $shipCount,
                    'speed'       => $ship->getSpeed($AccountData),

                    'capacity'    => $ship->getCapacity(),
                    'consumption' => $ship->getConsumption($AccountData),

                    'damage'      => $ship->getDamage(),
                    'experience'  => $ship->getExperience(),
                    'morale'      => $ship->getMorale(),
                    'status'      => $ship->getStatus(),
                ];

                // Агрегаты флота
                $countTotal += $shipCount;
                $types++;
                $capacityTotal += $ship->getCapacityAll();
                $consumptionTotal += $ship->getConsumptionAll($AccountData);
                $speedList[] = $ship->getSpeed($AccountData);
            }

            $fleetSpeed = $fleet->getTotalSpeed($AccountData);
            $startCoordinate = $fleet->getStartCoordinate();
            $targetCoordinate = $fleet->getTargetCoordinate();
            $currentCoordinate = $fleet->getCurrentCoordinate();
            // IDLE-защитник ресурсной базы остаётся в FleetList и во время боя.
            $battle = SpaceBattles::forFleet($fleet->getId())['state'] ?? null;
            if ($battle === null) foreach ($planetBattles as $state) {
                if (in_array($fleet->getId(), $state['fleets'], true)) { $battle = $state; break; }
            }
            $fallback = null;
            if ($fleet->getHomeSiteId() > 0) {
                try { $fallback = ResourceBaseService::fallbackCoordinate($fleet)->toArray(); }
                catch (\LogicException|\InvalidArgumentException) { /* Потеря дома не ломает весь snapshot. */ }
            }

            // Сформируем запись для клиента
            $FleetList[$fleet->getId()] = [
                'id'            => $fleet->getId(),
                'name'          => $fleet->getName(),
                'mission'       => $fleet->getMission(),
                'status'        => $fleet->getStatus(),
                'battle'        => $battle === null ? null : ['phase' => $battle['phase'],
                    'round' => count($battle['combat']['rounds'] ?? []) + (($battle['job'] ?? null) === null ? 0 : 1),
                    'rounds_max' => $battle['combat']['roundLimit'] ?? FleetCombatService::DEFAULT_ROUNDS],
                'home_site_id'  => $fleet->getHomeSiteId(),
                'standing'      => ResourceBaseService::standingState($fleet, microtime(true)),
                'fallback'      => $fallback,
                //'is_return'     => $fleet->isReturn() ? 1 : 0,
                'speed'         => $fleetSpeed,
                'count'         => $countTotal,
                'types'         => $types,
                'capacity'      => $capacityTotal,
                'consumption'   => $consumptionTotal,
                'fuel'          => $fleet->getFuelCurrent(),
                'experience'    => $fleet->getExperience(),
                'resources'     => $this->extractResources($fleet),
                'ships'         => $shipsNormalized,
                'start' => $startCoordinate?->toArray(),
                'target' => $targetCoordinate?->toArray(),
                'current' => $currentCoordinate->toArray(),
                'current_position_time' => $fleet->getCurrentPositionTime(),
                'control' => \SPGame\Game\Services\FleetControlService::state($fleet),
                'actions' => \SPGame\Game\Services\FleetControlService::actions($fleet, $AccountData),
            ];
        }

        return $FleetList;
    }

    /**
     * Собирает активные участки полёта всех собственных Fleet.
     *
     * Клиент получает уже материализованные сервером границы текущей команды.
     * Для ожидающих MOVE-команд дополнительно строится read-only прогноз теми
     * же формулами, которыми команда будет рассчитана при активации.
     */
    public function buildFlyingFleetList(AccountData &$AccountData): array
    {
        $ownerId = (int)($AccountData['User']['id'] ?? 0);
        $flyingFleets = [];
        $flightService = new FleetFlightService();

        foreach ($AccountData->getFleetsData()->getForAccount($ownerId) as $fleet) {
            $event = $this->buildFlyingFleetEvent($fleet, $AccountData, $flightService, true);
            if ($event !== null) {
                $event['owner_id'] = $ownerId;
                $event['owner_name'] = (string)($AccountData['User']['name'] ?? '');
                $event['is_own'] = true;
                $event['is_incoming'] = false;
                $event['is_hostile'] = false;
                $flyingFleets[$fleet->getId()] = $event;
            }
        }

        $this->sortFlyingFleetEvents($flyingFleets);

        return $flyingFleets;
    }

    /**
     * События, видимые владельцу аккаунта: собственные Fleet и чужие Fleet,
     * направленные к одному из принадлежащих ему объектов/Fleet.
     */
    public function buildVisibleFlyingFleetList(AccountData &$AccountData): array
    {
        $visible = $this->buildFlyingFleetList($AccountData);
        foreach ($this->buildIncomingFleetList($AccountData) as $fleetId => $event) {
            $visible[$fleetId] = $event;
        }

        $this->sortFlyingFleetEvents($visible);
        return $visible;
    }

    /** Оплаченный снимок фаланги: только текущее плечо, без будущего плана и управления. */
    public function buildPhalanxFleetList(AccountData &$owner, array $planet, int $spyLevel): array
    {
        $result = [];
        $matches = static fn(?array $c): bool => $c !== null && (int)($c['type'] ?? 0) === 1
            && (int)($c['objectType'] ?? 0) === 1 && (int)$c['galaxy'] === (int)$planet['galaxy']
            && (int)$c['system'] === (int)$planet['system'] && (int)$c['orbit'] === (int)$planet['planet'];
        foreach ($owner->getFleetsData()->getForAccount((int)$owner['User']['id']) as $fleet) {
            $command = FleetCommands::getActiveCommand($fleet->getId());
            if ($command === null || !in_array((int)$command['command_type'], [10, 11, 12, FleetCommandType::DEFEND->value], true)) continue;
            $event = $this->buildFlyingFleetEvent($fleet, $owner, new FleetFlightService(), true);
            $leg = $event['route_leg'] ?? null;
            if ($event === null || $leg === null) continue;
            $relocate = $fleet->getMission() === FleetMissionType::RELOCATE->value;
            // Nova: отлёт передислокации не виден; её отменённый возврат также скрыт.
            $visible = $fleet->isReturn() ? (!$relocate && $matches($leg['target'] ?? null))
                : ($matches($leg['target'] ?? null) || (!$relocate && $matches($leg['start'] ?? null)));
            if (!$visible) continue;
            $ships = $event['ships'] ?? [];
            $exact = $spyLevel >= 8 || $fleet->getOwnerId() === (int)$planet['owner_id'];
            if (!$exact) foreach ($ships as &$ship) $ship['count'] = null;
            unset($ship);
            $result[$fleet->getId()] = ['id' => $fleet->getId(), 'name' => $fleet->getName(),
                'ownerName' => (string)$owner['User']['name'], 'mission' => $fleet->getMission(),
                'returning' => $fleet->isReturn(), 'from' => $leg['start'], 'to' => $leg['target'],
                'arrivesAt' => $leg['arrives_at'], 'estimated' => (bool)$leg['estimated'],
                'holdingUntil' => (int)$command['command_type'] === FleetCommandType::DEFEND->value ? (float)$command['execute_time'] : null,
                'shipsCount' => $event['ships_count'], 'ships' => array_values($ships),
                // Nova показывает груз; здесь передаём обычные перевозимые ресурсы, без валют и бака.
                'resources' => array_map(static fn(array $resource): float => (float)$resource['count'],
                    array_intersect_key($event['resources'], array_flip([901, 902, 903])))];
        }
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function buildIncomingFleetList(AccountData &$AccountData): array
    {
        $viewerId = (int)($AccountData['User']['id'] ?? 0);
        if ($viewerId <= 0) {
            return [];
        }

        $candidateRows = FleetsServices::incomingFleetRows($viewerId);

        $events = [];
        $ownerAccounts = [];
        $flightService = new FleetFlightService();
        foreach ($candidateRows as $fleetId => $row) {
            $ownerId = (int)($row['owner_id'] ?? 0);
            if (
                $ownerId <= 0
                || $ownerId === $viewerId
                || (bool)($row['is_return'] ?? false)
                || (int)($row['mission'] ?? 0) <= 0
            ) {
                continue;
            }

            $owner = Users::findById($ownerId);
            $accountId = (int)($owner['account_id'] ?? 0);
            if ($accountId <= 0) {
                Logger::getInstance()->warning('Не найден аккаунт владельца входящего Fleet', [
                    'fleetId' => $fleetId,
                    'ownerId' => $ownerId,
                ]);
                continue;
            }

            try {
                $ownerAccounts[$accountId] ??= new AccountData($accountId);
                $fleet = $ownerAccounts[$accountId]->getFleetsData()->get($fleetId);
                if ($fleet === null || $fleet->isReturn()) {
                    continue;
                }

                $event = $this->buildFlyingFleetEvent(
                    $fleet,
                    $ownerAccounts[$accountId],
                    $flightService,
                    false
                );
                if ($event === null || ($event['route_leg']['direction'] ?? null) === 'recall_pending') {
                    continue;
                }

                $event['owner_id'] = $ownerId;
                $event['owner_name'] = (string)($owner['name'] ?? '');
                $event['is_own'] = false;
                $event['is_incoming'] = true;
                $event['is_hostile'] = $this->isHostileMission((int)$event['mission']);
                $events[$fleetId] = $event;
            } catch (\Throwable $exception) {
                Logger::getInstance()->warning('Не удалось подготовить входящий Fleet для отображения', [
                    'fleetId' => $fleetId,
                    'ownerId' => $ownerId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $events;
    }

    /** @return array<string, mixed>|null */
    private function buildFlyingFleetEvent(
        Fleet $fleet,
        AccountData $ownerAccountData,
        FleetFlightService $flightService,
        bool $includePrivateData
    ): ?array {
        $activeCommand = FleetCommands::getActiveCommand($fleet->getId());
        $activationPending = false;
        if ($activeCommand === null) {
            $pending = FleetCommands::getNextPendingCommand($fleet->getId());
            $pendingParams = $pending === null ? [] : $this->decodeFleetCommandParams($pending['params'] ?? '');
            if ($pending !== null && (!empty($pendingParams['blockedAfterBattle']) || isset($pendingParams['pause'])
                || ($fleet->getStatus() === Fleet::STATUS_MISSION
                    && $this->isMoveCommand(FleetCommandType::from((int)$pending['command_type']))))) {
                $activeCommand = $pending;
                $activationPending = true;
            }
        }
        if ($activeCommand === null) {
            return null;
        }

        try {
            $commandType = FleetCommandType::from((int)$activeCommand['command_type']);
            $target = FleetCommandsService::getCommandTarget($activeCommand);
            if ($target === null && ResourceBaseService::isArrivalCommand($activeCommand)) {
                $target = Coordinate::fromArray($this->decodeFleetCommandParams($activeCommand['params'])['at']);
            }
            if ($target === null) {
                throw new \LogicException('У активной команды отсутствует target.');
            }

            $params = json_decode(
                (string)($activeCommand['params'] ?? ''),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($params)) {
                $params = [];
            }

            $startTime = $fleet->getCurrentPositionTime();
            $blocked = $activationPending || !empty($params['blockedAfterBattle']);
            $endTime = $blocked ? $startTime : (float)$activeCommand['execute_time'];
            if (!$blocked && $commandType === FleetCommandType::MINING && isset($params['mining'])) $endTime = (float)$params['mining']['endsAt'];
            $segmentStart = $fleet->getCurrentCoordinate();
            $segmentEnd = ($blocked || isset($params['battleWait']) || ResourceBaseService::isArrivalCommand($activeCommand)
                || $commandType === FleetCommandType::ATTACK && !empty($params['resourceCapture'])
                || in_array($commandType, [FleetCommandType::LOAD,FleetCommandType::UNLOAD,FleetCommandType::MERGE,FleetCommandType::DEFEND,FleetCommandType::OBSERVE,FleetCommandType::EXPEDITION,FleetCommandType::RESEARCH,FleetCommandType::MINING], true)) ? $segmentStart : $flightService->resolveCommandEndCoordinate(
                $commandType,
                $segmentStart,
                $target,
                $params,
                $startTime,
                $endTime
            );
            // Подписи участка сохраняют identity объектов. POINT-снимки ниже
            // нужны canvas для траектории, а не для названий её концов.
            $segmentOrigin = $segmentStart;
            $segmentTarget = $segmentEnd;
            if (!$blocked && !isset($params['battleWait']) && $commandType === FleetCommandType::MOVE_IN_SYSTEM
                && isset($params['motionStart'], $params['motionEnd'])) {
                $segmentStart = Coordinate::fromArray($params['motionStart']);
                $segmentEnd = Coordinate::fromArray($params['motionEnd']);
            }
            $commands = FleetCommands::getFleetCommandChain($fleet->getId());
            $battle = ($params['battleWait']['scope'] ?? null) === 'space'
                ? SpaceBattles::state((int)$params['battleWait']['key'])
                : (isset($params['battleWait']['planetId']) ? PlanetBattles::state((int)$params['battleWait']['planetId']) : null);
            // Защищающийся флот заморожен на своей MOVE/OBSERVE-команде,
            // а не на ATTACK с battleWait. Состояние боя у него то же самое.
            $battle ??= SpaceBattles::forFleet($fleet->getId())['state'] ?? null;
            $commandProjection = $this->buildFlightCommandProjection(
                $fleet,
                $ownerAccountData,
                $commands,
                $flightService
            );
            if (!empty($params['recallAfterTransition'])) {
                // После текущего перехода прежняя цель и удержание уже не будут
                // выполняться. Не показываем их таймер как оставшийся маршрут.
                $commandProjection['route_leg'] = [
                    'direction' => 'recall_pending', 'start' => $segmentStart->toArray(),
                    'target' => $segmentEnd->toArray(), 'started_at' => $startTime,
                    'arrives_at' => $endTime, 'estimated' => false,
                    'active_segment' => 1, 'segments_total' => 1, 'segments' => [],
                ];
                $commandProjection['commands'] = array_values(array_filter($commandProjection['commands'],
                    static fn(array $row): bool => $row['status'] !== 'pending'));
                $commandProjection['mission_end_time'] = null;
                $commandProjection['mission_end_estimated'] = true;
            }
            if (!empty($params['fuelStop'])) {
                $commandProjection['route_leg'] = [
                    'direction' => 'fuel_stop', 'start' => $segmentStart->toArray(), 'target' => $segmentEnd->toArray(),
                    'started_at' => $startTime, 'arrives_at' => $endTime, 'estimated' => false,
                    'active_segment' => 1, 'segments_total' => 1,
                    'segments' => [['id' => (int)$activeCommand['id'], 'status' => 'active',
                        'started_at' => $startTime, 'ends_at' => $endTime, 'fuel_cost' => (float)$params['fuelCost']]],
                ];
            }

            $event = [
                'id' => $fleet->getId(),
                'name' => $includePrivateData ? $fleet->getName() : '',
                'mission' => $fleet->getMission(),
                'status' => $fleet->getStatus(),
                'is_return' => $fleet->isReturn(),
                'fuel_stop' => !empty($params['fuelStop']),
                'route_leg' => $includePrivateData
                    ? $commandProjection['route_leg']
                    : $this->redactIncomingRouteLeg($commandProjection['route_leg']),
                'command' => [
                    'id' => (int)$activeCommand['id'],
                    'type' => $commandType->value,
                    'key' => $commandType->name,
                    'lang' => $commandType->getLangKey(),
                ],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'segment_start' => $this->serializeProjectionTarget($segmentStart, true),
                'segment_end' => $this->serializeProjectionTarget($segmentEnd, true),
                'segment_origin' => $this->serializeProjectionTarget($segmentOrigin),
                'segment_target' => $this->serializeProjectionTarget($segmentTarget),
                'mission_start' => $this->serializeProjectionTarget($fleet->getStartCoordinate(), true),
                'mission_target' => $this->serializeProjectionTarget($fleet->getTargetCoordinate(), true),
                'blocked_reason' => $blocked
                    ? (match ($params['pause'] ?? null) {
                        'fuel' => 'Недостаточно топлива. После заправки нажмите «Продолжить».',
                        'hold' => 'Удержание на свободных координатах',
                        'manual' => 'Остановлен игроком',
                        'target' => 'Цель недоступна или изменила маршрут',
                        'battle' => $battle !== null ? 'Участвует в бою' : 'После боя ожидает команды на продолжение',
                        default => !empty($params['blockedAfterBattle']) ? 'Возврат после боя приостановлен' : 'Маршрут приостановлен: следующая команда не активирована',
                    })
                    : null,
                // Только публичное состояние; seed, участники и боевые snapshots клиенту не передаются.
                'battle' => $battle === null ? null : ['phase' => $battle['phase'],
                    'round' => count($battle['combat']['rounds'] ?? []) + (($battle['job'] ?? null) === null ? 0 : 1),
                    'rounds_max' => $battle['combat']['roundLimit'] ?? FleetCombatService::DEFAULT_ROUNDS],
            ];

            if (!$includePrivateData) {
                return $event;
            }
            $event['control'] = \SPGame\Game\Services\FleetControlService::state($fleet);
            $event['actions'] = \SPGame\Game\Services\FleetControlService::actions($fleet, $ownerAccountData);
            $event['sensors'] = \SPGame\Game\Services\FleetSensorService::state($fleet);
            $event['route_progress'] = $commandProjection['route_progress'];

            $shipsCount = 0;
            $shipTypes = 0;
            $ships = [];
            foreach ($fleet->getShips() as $shipId => $ship) {
                $count = $ship->getCount();
                if ($count <= 0) {
                    continue;
                }
                $ships[(int)$shipId] = [
                    'id' => (int)$shipId,
                    'name' => $ship->getShipTypeName(),
                    'count' => $count,
                ];
                $shipsCount += $count;
                $shipTypes++;
            }

            $canRecall = false;
            try {
                FleetCommandsService::assertRecall($fleet, $ownerAccountData);
                $canRecall = true;
            } catch (\InvalidArgumentException|\LogicException) {
                // Ограничения кнопки совпадают с серверной проверкой команды.
            }
            $event += [
                'ships_count' => $shipsCount,
                'ship_types' => $shipTypes,
                'ships' => $ships,
                'resources' => $this->extractResources($fleet),
                'fuel' => $fleet->getFuelCurrent(),
                'standing' => ResourceBaseService::standingState($fleet, microtime(true)),
                'mining' => \SPGame\Game\Services\FleetMiningRuntimeService::state($fleet),
                'can_recall' => $canRecall,
                'can_recall_defend' => $canRecall && $commandType === FleetCommandType::DEFEND,
                'recall_pending' => !empty($params['recallAfterTransition']) || !empty($params['recalled']),
                'commands_left' => count(array_filter($commandProjection['commands'],
                    static fn(array $row): bool => $row['status'] !== 'completed')),
                'commands_total' => count($commandProjection['commands']),
                'commands' => $commandProjection['commands'],
                'mission_end_time' => $blocked ? null : $commandProjection['mission_end_time'],
                'mission_end_estimated' => $commandProjection['mission_end_estimated'],
            ];
            if ($commandType === FleetCommandType::EXPEDITION) {
                $expedition = \SPGame\Game\Repositories\FleetExpeditions::state($fleet->getId());
                if (($expedition['phase'] ?? '') === 'combat') {
                    $event['battle'] = ['round'=>count($expedition['plan']['battle']['rounds']),
                        'rounds_max'=>$expedition['plan']['battle']['roundLimit']];
                } elseif (($expedition['phase'] ?? '') === 'failed') {
                    $event['blocked_reason'] = 'Экспедиционный бой остановлен; требуется проверка сервера.';
                }
            }

            return $event;
        } catch (\Throwable $exception) {
            Logger::getInstance()->warning('Не удалось подготовить Fleet для таблицы полётов', [
                'fleetId' => $fleet->getId(),
                'commandId' => (int)($activeCommand['id'] ?? 0),
                'message' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /** @param array<string, mixed>|null $routeLeg */
    private function redactIncomingRouteLeg(?array $routeLeg): ?array
    {
        if ($routeLeg === null) {
            return null;
        }

        return array_intersect_key($routeLeg, array_flip([
            'direction',
            'start',
            'target',
            'started_at',
            'arrives_at',
            'estimated',
        ]));
    }

    private function isHostileMission(int $missionId): bool
    {
        return in_array($missionId, [
            FleetMissionType::ATTACK->value,
            FleetMissionType::GROUP_ATTACK->value,
            FleetMissionType::DESTROY->value,
            FleetMissionType::SPY->value,
            FleetMissionType::CAPTURE->value,
        ], true);
    }

    /** @param array<int, array<string, mixed>> $events */
    private function sortFlyingFleetEvents(array &$events): void
    {
        uasort($events, static function (array $left, array $right): int {
            $leftTime = (float)($left['route_leg']['arrives_at'] ?? $left['end_time'] ?? 0.0);
            $rightTime = (float)($right['route_leg']['arrives_at'] ?? $right['end_time'] ?? 0.0);
            $timeOrder = $leftTime <=> $rightTime;
            return $timeOrder !== 0 ? $timeOrder : ($left['id'] <=> $right['id']);
        });
    }

    /**
     * Построить клиентское представление оставшейся цепочки FleetCommand.
     *
     * ACTIVE-команда имеет authoritative execute_time. Время ожидающих MOVE
     * является прогнозом: runtime очередь не изменяется и материализованные
     * координаты в FleetCommands не записываются.
     *
     * @param array<int, array<string, mixed>> $commands
     * @return array{
     *     commands: array<int, array<string, mixed>>,
     *     route_leg: ?array<string, mixed>,
     *     route_progress: array<string, mixed>,
     *     mission_end_time: ?float,
     *     mission_end_estimated: bool
     * }
     */
    private function buildFlightCommandProjection(
        Fleet $fleet,
        AccountData $AccountData,
        array $commands,
        FleetFlightService $flightService
    ): array {
        $cursor = $fleet->getCurrentCoordinate();
        $cursorTime = $fleet->getCurrentPositionTime();
        $fleetSpeed = $fleet->getTotalSpeed($AccountData);
        $scheduleAvailable = true;
        $missionEndEstimated = false;
        $projection = [];
        $commandCreateTimes = [];
        $commandStepIds = [];

        $commands = array_values($commands);
        for ($position = 0; $position < count($commands); ++$position) {
            $command = $commands[$position];
            $type = FleetCommandType::from((int)$command['command_type']);
            $params = $this->decodeFleetCommandParams($command['params'] ?? '');
            if (!empty($params['cancelled'])) continue;
            $target = $this->getProjectionCommandTarget($params);
            $completed = (bool)$command['executed'];
            $active = !$completed && (float)$command['execute_time'] > 0.0;
            $commandTime = null;
            $commandStartTime = $this->getProjectionStartedAt($params);
            $startTimeEstimated = false;
            $timeEstimated = false;
            $commandCreateTimes[(int)$command['id']] = (float)($command['create_time'] ?? 0.0);
            $commandStepIds[(int)$command['id']] = $params['planStepId'] ?? null;
            if (!$completed && isset($params['pause'])) $scheduleAvailable = false;

            if ($completed) {
                $executedAt = (float)$command['execute_time'];
                $commandTime = $executedAt > 0.0 ? $executedAt : null;
            } elseif ($scheduleAvailable) {
                try {
                    $homeSiteId = (int)($params['homeSiteId'] ?? 0);
                    if (!$active && $homeSiteId > 0 && $homeSiteId !== $fleet->getHomeSiteId()) {
                        // activateNextCommand заменит только неявный хвост возврата.
                        // Считаем тот же путь из будущей точки, не меняя runtime/очередь.
                        $home = ResourceBaseService::homeCoordinate($fleet);
                        $replacement = [];
                        foreach (\SPGame\Game\Services\FleetMissionBuilder::buildReturn($cursor, $home) as $index => $next) {
                            $replacement[] = array_replace($command, [
                                // Отрицательные ключи принадлежат только read-модели,
                                // а не ID настоящих команд или входным данным редактора.
                                'id' => -((int)$command['id'] + $index),
                                'command_type' => $next['type']->value,
                                'execute_time' => 0.0,
                                'params' => json_encode($next['params'] + [
                                    'returnLeg' => true, 'speedFactor' => $this->getProjectionSpeedFactor($params),
                                ], JSON_THROW_ON_ERROR),
                            ]);
                        }
                        array_splice($commands, $position, count($commands) - $position, $replacement);
                        --$position;
                        continue;
                    }
                    if ($active) {
                        if (isset($params['battleWait'])) {
                            $commandStartTime ??= $cursorTime;
                            $commandTime = max($cursorTime, (float)$command['execute_time']);
                            $cursorTime = $commandTime;
                            $timeEstimated = true;
                            $missionEndEstimated = true;
                        } elseif ($type === FleetCommandType::EXPEDITION) {
                            $commandStartTime = (float)($params['expedition']['startedAt'] ?? $cursorTime);
                            $commandTime = max((float)($params['expedition']['endsAt'] ?? 0),(float)$command['execute_time']);
                            $cursorTime = $commandTime;
                            $missionEndEstimated = true;
                        } elseif ($type === FleetCommandType::MINING) {
                            $commandStartTime = (float)($params['mining']['startedAt'] ?? $cursorTime);
                            $commandTime = (float)($params['mining']['endsAt'] ?? $command['execute_time']);
                            $cursorTime = $commandTime;
                            $scheduleAvailable = false; // Трюм, запас или бак могут закончить работу раньше срока.
                            $timeEstimated = $missionEndEstimated = true;
                        } elseif (in_array($type, [FleetCommandType::DEFEND,FleetCommandType::OBSERVE,FleetCommandType::RESEARCH], true) && isset($params['hold'])) {
                            $commandStartTime ??= (float)$params['hold']['startedAt'];
                            $commandTime = (float)$command['execute_time'];
                            $cursorTime = $commandTime;
                            if ($type === FleetCommandType::OBSERVE) $scheduleAvailable = false;
                        } elseif (\SPGame\Game\Services\FleetResourceDefendService::isCommand($command) && !isset($params['hold'])) {
                            // Прибытие к ресурсному объекту запускает DEFEND отдельным due-событием.
                            $commandStartTime ??= $cursorTime;
                            $commandTime = $cursorTime + \SPGame\Game\Services\FleetDefendService::duration($params['duration'] ?? null);
                            $cursorTime = $commandTime;
                            $timeEstimated = $missionEndEstimated = true;
                        } elseif (ResourceBaseService::isArrivalCommand($command)
                            || $type === FleetCommandType::ATTACK && !empty($params['resourceCapture'])
                            || $type === FleetCommandType::UNLOAD && !empty($params['deferredMiningTransfer'])
                            || in_array($type, [FleetCommandType::LOAD, FleetCommandType::MERGE, FleetCommandType::RESEARCH], true)) {
                            // Промежуточный LOAD — отдельное due-событие без перемещения.
                            $commandStartTime ??= $cursorTime;
                            $commandTime = (float)$command['execute_time'];
                            $cursorTime = $commandTime;
                        } else {
                            if (!$this->isMoveCommand($type) || $target === null) {
                                throw new \LogicException('ACTIVE-команда не содержит корректную MOVE-цель.');
                            }
                            $commandStartTime ??= $cursorTime;
                            $commandTime = (float)$command['execute_time'];
                            $cursor = $flightService->resolveCommandEndCoordinate(
                                $type, $cursor, $target, $params, $cursorTime, $commandTime
                            );
                            $cursorTime = $commandTime;
                        }
                    } elseif ($this->isMoveCommand($type)) {
                        if ($target === null) {
                            throw new \LogicException('Ожидающая MOVE-команда не содержит target.');
                        }

                        $commandStartTime = $cursorTime;
                        $startTimeEstimated = true;
                        $params = $flightService->materializeCommandCoordinates(
                            $type,
                            $cursor,
                            $target,
                            $params,
                            $cursorTime,
                            randomizeTransitionAngle: false
                        );
                        $durationTarget = isset($params['resolvedTarget'])
                            && is_array($params['resolvedTarget'])
                                ? Coordinate::fromArray($params['resolvedTarget'])
                                : $target;
                        $duration = $flightService->calculateCommandDuration(
                            $type,
                            $cursor,
                            $durationTarget,
                            $cursorTime,
                            $fleetSpeed,
                            $this->getProjectionSpeedFactor($params),
                            $params['recallFlight'] ?? null
                        );
                        if (isset($params['groupExecuteAt']) && empty($params['returnLeg'])) {
                            $schedule = \SPGame\Game\Services\FleetGroupAttackService::schedule($type, $cursor,
                                $target, $params, $cursorTime, $fleetSpeed, (float)$params['groupExecuteAt'], (float)$params['groupSpeedCap']);
                            $duration = $schedule['duration'];
                        }
                        $commandTime = $cursorTime + $duration * (float)($params['expeditionTimeFactor'] ?? 1);
                        $cursor = $flightService->resolveCommandEndCoordinate(
                            $type,
                            $cursor,
                            $target,
                            $params,
                            $cursorTime,
                            $commandTime
                        );
                        $cursorTime = $commandTime;
                        $timeEstimated = true;
                        $missionEndEstimated = true;
                    } elseif (in_array($type, [FleetCommandType::ATTACK, FleetCommandType::GROUP_ATTACK, FleetCommandType::DESTROY], true)) {
                        // Только прогноз для UI: бой может закончиться раньше,
                        // задержаться или уничтожить флот. Runtime не меняется.
                        $commandStartTime = $cursorTime;
                        $startTimeEstimated = true;
                        $cursorTime += FleetCombatService::DEFAULT_ROUNDS * FleetBattleService::ROUND_SECONDS;
                        $commandTime = $cursorTime;
                        $timeEstimated = true;
                        $missionEndEstimated = true;
                    } elseif ($type === FleetCommandType::DEFEND) {
                        $commandStartTime = $cursorTime;
                        $commandTime = $cursorTime + \SPGame\Game\Services\FleetDefendService::duration($params['duration'] ?? null);
                        $cursorTime = $commandTime;
                        $timeEstimated = true;
                        $missionEndEstimated = true;
                    } elseif ($type === FleetCommandType::RESEARCH && $target !== null) {
                        $commandStartTime = $cursorTime;
                        $commandTime = $cursorTime + \SPGame\Game\Services\FleetSurveyService::estimate($fleet, $AccountData, $target)['duration'];
                        $cursorTime = $commandTime;
                        $timeEstimated = $missionEndEstimated = true;
                    } elseif ($type === FleetCommandType::EXPEDITION) {
                        $commandStartTime = $cursorTime;
                        $commandTime = $cursorTime + (float)($params['duration'] ?? 3600);
                        $cursorTime = $commandTime;
                        $timeEstimated = true; $missionEndEstimated = true;
                    } elseif ($this->isImmediateProjectionCommand($type)) {
                        $commandTime = $cursorTime;
                        $timeEstimated = true;
                        if ($type === FleetCommandType::STAY && $target !== null) {
                            $cursor = $target;
                        }
                    } else {
                        // Длительность остальных боевых, экспедиционных и WAIT-команд
                        // пока не является частью execution contract.
                        $scheduleAvailable = false;
                    }
                } catch (\Throwable $exception) {
                    $scheduleAvailable = false;
                    Logger::getInstance()->warning('Не удалось рассчитать прогноз FleetCommand', [
                        'fleetId' => $fleet->getId(),
                        'commandId' => (int)($command['id'] ?? 0),
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            // После исчерпания исходная MOVE остаётся pending: дальнейшего
            // расписания ещё нет, нельзя обещать прибытие/выгрузку в эту секунду.
            if ($active && !empty($params['fuelStop'])) $scheduleAvailable = false;
            $projection[] = [
                'id' => (int)$command['id'],
                'position' => count($projection) + 1,
                'type' => $type->value,
                'key' => $type->name,
                'lang' => $type->getLangKey(),
                'status' => $completed ? 'completed' : (isset($params['pause']) ? 'paused' : ($active ? 'active' : 'pending')),
                'execute_time' => (float)$command['execute_time'],
                'started_time' => $commandStartTime,
                'start_time_estimated' => $startTimeEstimated,
                'estimated_time' => $commandTime,
                'time_estimated' => $timeEstimated,
                'return_leg' => (bool)($params['returnLeg'] ?? false),
                'fuel_cost' => $this->getProjectionFuelCost($params),
                'resource_percent' => isset($params['percent']) ? (float)$params['percent'] : null,
                'plan_error' => $params['planError'] ?? null,
                'target' => $this->serializeProjectionTarget($target),
            ];
        }

        $routeLeg = $this->buildRouteLegProjection(
            $fleet,
            $projection,
            $commandCreateTimes,
            $commandStepIds
        );
        // Удержание — отдельная фаза, а не уже завершённое плечо MOVE.
        // Один authoritative таймер используют и «Флот», и обзор получателя.
        foreach ($commands as $command) {
            if ((int)$command['command_type'] === FleetCommandType::LOAD->value && !(int)$command['executed']) {
                $params = $this->decodeFleetCommandParams($command['params'] ?? '');
                if (isset($params['loading']) && empty($params['cancelled'])
                    && ((float)$command['execute_time'] > 0 || isset($params['pause']))) {
                    $routeLeg = ['direction' => 'loading',
                        'start' => $fleet->getCurrentCoordinate()->toArray(),
                        'target' => $fleet->getCurrentCoordinate()->toArray(),
                        'started_at' => $this->getProjectionStartedAt($params) ?? $fleet->getCurrentPositionTime(),
                        'arrives_at' => !isset($params['pause']) && (float)$command['execute_time'] > 0
                            ? (float)$command['execute_time'] : null,
                        'estimated' => false, 'active_segment' => 1, 'segments_total' => 1, 'segments' => []];
                    break;
                }
            }
            if ((int)$command['command_type'] === FleetCommandType::MINING->value
                && !(int)$command['executed'] && (float)$command['execute_time'] > 0) {
                $params = $this->decodeFleetCommandParams($command['params']);
                if (($params['mining']['phase'] ?? null) === 'working') {
                    $routeLeg = ['direction'=>'mining', 'start'=>$fleet->getCurrentCoordinate()->toArray(),
                        'target'=>$fleet->getCurrentCoordinate()->toArray(),
                        'started_at'=>(float)$params['mining']['startedAt'],
                        'arrives_at'=>(float)$params['mining']['endsAt'],
                        'estimated'=>true, 'active_segment'=>1, 'segments_total'=>1, 'segments'=>[]];
                    break;
                }
            }
            if ((int)$command['command_type'] === FleetCommandType::EXPEDITION->value
                && !(int)$command['executed'] && (float)$command['execute_time'] > 0) {
                $params = $this->decodeFleetCommandParams($command['params']);
                $routeLeg = ['direction'=>'expedition','start'=>$fleet->getCurrentCoordinate()->toArray(),
                    'target'=>$fleet->getCurrentCoordinate()->toArray(),
                    'started_at'=>(float)($params['expedition']['startedAt'] ?? $fleet->getCurrentPositionTime()),
                    'arrives_at'=>(float)($params['expedition']['endsAt'] ?? $command['execute_time']),
                    'estimated'=>false,'active_segment'=>1,'segments_total'=>1,'segments'=>[]];
                break;
            }
            if (!in_array((int)$command['command_type'], [FleetCommandType::DEFEND->value,FleetCommandType::OBSERVE->value,FleetCommandType::RESEARCH->value], true)
                || (int)$command['executed'] || (float)$command['execute_time'] <= 0) continue;
            $params = $this->decodeFleetCommandParams($command['params'] ?? '');
            if (!isset($params['hold']) || isset($params['battleWait'])) continue;
            $routeLeg = [
                'direction' => 'holding',
                'start' => $fleet->getStartCoordinate()?->toArray(),
                'target' => $fleet->getTargetCoordinate()?->toArray(),
                'started_at' => (float)$params['hold']['startedAt'],
                'arrives_at' => (float)$command['execute_time'],
                'estimated' => false,
                'active_segment' => 1,
                'segments_total' => 1,
                'segments' => [],
            ];
            break;
        }

        return [
            'commands' => $projection,
            'route_leg' => $routeLeg,
            'route_progress' => FleetControlService::routeProgress($fleet, $commands),
            'mission_end_time' => $scheduleAvailable ? $cursorTime : null,
            'mission_end_estimated' => $missionEndEstimated,
        ];
    }

    /** @param array<string, mixed> $params */
    private function getProjectionStartedAt(array $params): ?float
    {
        $startedAt = $params['startedAt'] ?? null;
        if (!is_numeric($startedAt)) {
            return null;
        }

        $startedAt = (float)$startedAt;
        return is_finite($startedAt) && $startedAt > 0.0 ? $startedAt : null;
    }

    /** @param array<string, mixed> $params */
    private function getProjectionFuelCost(array $params): ?float
    {
        $fuelCost = $params['fuelCost'] ?? null;
        if (!is_numeric($fuelCost)) {
            return null;
        }

        $fuelCost = (float)$fuelCost;
        return is_finite($fuelCost) && $fuelCost >= 0.0 ? $fuelCost : null;
    }

    /**
     * Собрать единую временную шкалу текущего плеча маршрута.
     *
     * Отдельные MOVE-команды остаются execution source of truth. Эта проекция
     * только объединяет их до ближайшей остановки, не склеивая следующие этапы.
     *
     * @param array<int, array<string, mixed>> $projection
     * @param array<int, float> $commandCreateTimes
     * @param array<int, string|null> $commandStepIds
     * @return array<string, mixed>|null
     */
    private function buildRouteLegProjection(
        Fleet $fleet,
        array $projection,
        array $commandCreateTimes,
        array $commandStepIds = []
    ): ?array {
        $isReturn = $fleet->isReturn();
        $legs = [];
        $split = true;
        $currentPosition = null;
        $origin = $this->serializeProjectionTarget($fleet->getStartCoordinate());
        foreach ($projection as $command) {
            if ($currentPosition === null && $command['status'] !== 'completed') {
                $currentPosition = (int)$command['position'];
            }
            if (!$this->isMoveCommand(FleetCommandType::from((int)$command['type']))) {
                $split = true;
                continue;
            }
            $stepId = $commandStepIds[(int)$command['id']] ?? null;
            $last = array_key_last($legs);
            if ($split || $legs[$last]['step_id'] !== $stepId
                || $legs[$last]['target'] != $command['target']
                || $legs[$last]['return_leg'] !== (bool)$command['return_leg']) {
                $legs[] = ['start' => $origin, 'target' => $command['target'],
                    'step_id' => $stepId, 'return_leg' => (bool)$command['return_leg'], 'commands' => []];
                $last = array_key_last($legs);
                $origin = $command['target'];
            }
            $legs[$last]['commands'][] = $command;
            $split = false;
        }
        $currentLeg = null;
        foreach ($legs as $leg) {
            if ($leg['return_leg'] !== $isReturn) continue;
            // Во время действия оставляем завершённое плечо к этому объекту,
            // а не показываем ещё не начавшийся перелёт после него.
            if ($currentLeg !== null && $leg['commands'][0]['position'] > ($currentPosition ?? PHP_INT_MAX)) break;
            $currentLeg = $leg;
        }
        if ($currentLeg === null) {
            return null;
        }
        $moveCommands = $currentLeg['commands'];

        $firstMoveId = (int)$moveCommands[0]['id'];
        $previousMoveEnd = null;
        foreach ($projection as $command) {
            if ((int)$command['id'] === $firstMoveId) {
                break;
            }
            if (
                $this->isMoveCommand(FleetCommandType::from((int)$command['type']))
                && is_numeric($command['estimated_time'])
                && (float)$command['estimated_time'] > 0.0
            ) {
                $previousMoveEnd = (float)$command['estimated_time'];
            }
        }

        $segments = [];
        $previousEnd = $previousMoveEnd;
        $activeSegment = null;
        $estimated = false;
        foreach ($moveCommands as $index => $command) {
            $startedAt = is_numeric($command['started_time'])
                ? (float)$command['started_time']
                : null;
            $startEstimated = (bool)$command['start_time_estimated'];
            if ($startedAt === null || $startedAt <= 0.0) {
                $startedAt = $previousEnd;
                if ($startedAt === null || $startedAt <= 0.0) {
                    $createdAt = $commandCreateTimes[(int)$command['id']] ?? 0.0;
                    $startedAt = $createdAt > 0.0 ? $createdAt : null;
                }
                $startEstimated = true;
            }

            $endsAt = is_numeric($command['estimated_time'])
                ? (float)$command['estimated_time']
                : null;
            if ($endsAt !== null && $endsAt <= 0.0) {
                $endsAt = null;
            }

            $segmentEstimated = $startEstimated || (bool)$command['time_estimated'];
            $estimated = $estimated || $segmentEstimated;
            if ($command['status'] === 'active') {
                $activeSegment = $index + 1;
            }

            $segments[] = [
                'id' => (int)$command['id'],
                'position' => (int)$command['position'],
                'type' => (int)$command['type'],
                'key' => (string)$command['key'],
                'lang' => (string)$command['lang'],
                'status' => (string)$command['status'],
                'started_at' => $startedAt,
                'ends_at' => $endsAt,
                'fuel_cost' => $command['fuel_cost'] ?? null,
                'estimated' => $segmentEstimated,
            ];
            if ($endsAt !== null) {
                $previousEnd = $endsAt;
            }
        }

        return [
            'direction' => $isReturn ? 'return' : 'outbound',
            'start' => $isReturn
                ? $this->serializeProjectionTarget($fleet->getTargetCoordinate()) : $currentLeg['start'],
            'target' => $currentLeg['target'],
            'started_at' => $segments[0]['started_at'],
            'arrives_at' => $segments[array_key_last($segments)]['ends_at'],
            'estimated' => $estimated,
            'active_segment' => $activeSegment,
            'segments_total' => count($segments),
            'segments' => $segments,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeFleetCommandParams(string|array $params): array
    {
        if (is_array($params)) {
            return $params;
        }
        if ($params === '') {
            return [];
        }

        $decoded = json_decode($params, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $params */
    private function getProjectionCommandTarget(array $params): ?Coordinate
    {
        $coordinate = $params['target'] ?? $params['at'] ?? null;
        return is_array($coordinate) ? Coordinate::fromArray($coordinate) : null;
    }

    /** @return array<string, mixed>|null */
    private function serializeProjectionTarget(?Coordinate $target, bool $preserveFleetSnapshot = false): ?array
    {
        if ($target === null) {
            return null;
        }
        if ($target->getType() === CoordinateType::FLEET && !$preserveFleetSnapshot) {
            return [
                'type' => CoordinateType::FLEET->value,
                'fleetId' => $target->getFleetId(),
            ];
        }

        $data = $target->toArray();
        if (\SPGame\Game\Services\FleetSurveyService::isResource($target)) {
            $data['sector'] = (int)\SPGame\Game\Services\FleetSurveyService::site($target)['sector'];
        }
        return $data;
    }

    private function isMoveCommand(FleetCommandType $type): bool
    {
        return in_array($type, [
            FleetCommandType::MOVE_IN_SYSTEM,
            FleetCommandType::MOVE_BETWEEN_SYSTEMS,
            FleetCommandType::MOVE_BETWEEN_GALAXIES,
        ], true);
    }

    private function isImmediateProjectionCommand(FleetCommandType $type): bool
    {
        return in_array($type, [
            FleetCommandType::LOAD,
            FleetCommandType::UNLOAD,
            FleetCommandType::STAY,
            FleetCommandType::SPY,
            FleetCommandType::RECYCLE,
            FleetCommandType::COLONIZE,
            FleetCommandType::MERGE,
            FleetCommandType::CAPTURE,
        ], true);
    }

    /** @param array<string, mixed> $params */
    private function getProjectionSpeedFactor(array $params): float
    {
        $speedFactor = $params['speedFactor'] ?? Config::getValue('FleetSpeedFactor') ?? 1.0;
        if (!is_numeric($speedFactor)) {
            throw new \LogicException('Некорректный speedFactor в FleetCommand.');
        }

        $speedFactor = (float)$speedFactor;
        if (!is_finite($speedFactor) || $speedFactor < 1.0 || $speedFactor > 10.0) {
            throw new \LogicException('speedFactor FleetCommand должен быть в диапазоне 1..10.');
        }

        return $speedFactor;
    }

    /**
     * Собирает список кораблей, находящихся на планете (ангар)
     */
    private function buildPlanetShips(AccountData &$AccountData): array
    {
        $Ships = &$AccountData['Ships'];
        $PlanetFleet = [];

        foreach (Vars::$reslist['fleet'] as $ShipId) {
            $count = $Ships[Vars::$resource[$ShipId]] ?? 0;

            if (Vars::$attributes[$ShipId]['speed'] > 0)
                $PlanetFleet[$ShipId] = [
                    'id'            => $ShipId,
                    'name'          => Vars::$resource[$ShipId],
                    'count'         => $count,
                    'capacity'      => Vars::$attributes[$ShipId]['capacity'],
                    'speed'         => FleetFunctions::GetShipSpeed($ShipId, $AccountData),
                    'consumption'   => FleetFunctions::GetShipConsumption($ShipId, $AccountData),
                    'structure'     => Vars::$attributes[$ShipId]['cost'][901] + Vars::$attributes[$ShipId]['cost'][902],
                    'attack'        => Vars::$CombatCaps[$ShipId]['attack'],
                    'shield'        => Vars::$CombatCaps[$ShipId]['shield'],
                ];
        }

        return $PlanetFleet;
    }

    /**
     * Извлекает ресурсы из строки флота
     */
    private function extractResources(Fleet $fleet): array
    {
        $resources = [];

        foreach ($fleet->getResources() as $resource) {
            $resources[$resource->getID()] = [
                'id' => $resource->getID(),
                'name' => $resource->getName(),
                'count' => $resource->getCount(),
            ];
        }

        return $resources;
    }

    /**
     * Ограничения навигации для выбранной системы.
     * Граница системы намеренно не передаётся клиенту как доступная цель.
     */
    private function buildNavigationSystemInfo(AccountData $accountData): array
    {
        $galaxy = (int)$this->Msg->getData('galaxy', 0);
        $system = (int)$this->Msg->getData('system', 0);
        $maxGalaxy = (int)Config::getValue('MaxGalaxy');
        $maxSystem = (int)Config::getValue('MaxSystem');

        if (
            $galaxy < 1
            || $system < 1
            || ($maxGalaxy > 0 && $galaxy > $maxGalaxy)
            || ($maxSystem > 0 && $system > $maxSystem)
        ) {
            return [
                'valid' => false,
                'galaxy' => $galaxy,
                'system' => $system,
                'message' => 'Система недоступна.',
            ];
        }

        $systemData = Galaxy::getSystem($galaxy, $system);
        if (!$systemData) {
            return [
                'valid' => false,
                'galaxy' => $galaxy,
                'system' => $system,
                'message' => 'Система не найдена.',
            ];
        }

        $expeditionOrbit = null;
        GalaxyGenerator::ensureResourceSitesForSystem($systemData);
        $resourceSites = array_map(static fn(array $site): array =>
            \SPGame\Game\Services\FleetSurveyService::coordinate($site)->toArray()
                + ['label' => \SPGame\Game\Services\FleetSurveyService::label($site)],
            \SPGame\Game\Repositories\OrbitalResourceSites::findByIndex('galaxy_system', [$galaxy, $system]) ?? []);
        $fleetId = (int)$this->Msg->getData('fleetId', 0);
        $fleet = $fleetId > 0 ? $accountData->getFleetsData()->get($fleetId) : null;
        $ownerId = (int)($accountData['User']['id'] ?? 0);

        if ($fleet !== null && $fleet->getOwnerId() === $ownerId) {
            $current = $fleet->getCurrentCoordinate();
            if ($current->getType() === CoordinateType::FLEET) {
                $current = $current->getLastKnownPosition();
            }

            if (
                $this->Msg->getData('forPlan', false) === true
                || ($current !== null
                    && $current->getGalaxy() === $galaxy
                    && $current->getSystem() === $system)
            ) {
                $expedition = GalaxyGenerator::getSpecialOrbit(
                    $galaxy,
                    $system,
                    GalaxyOrbits::EXPEDITION
                );
                $expeditionOrbit = (int)$expedition['orbit'];
            }
        }

        $pointBounds = FleetMissionService::pointDistanceBounds($galaxy, $system);
        if ($pointBounds === null) return ['valid' => false, 'galaxy' => $galaxy, 'system' => $system,
            'message' => 'Границы системы недоступны.'];
        return [
            'valid' => true,
            'galaxy' => $galaxy,
            'system' => $system,
            'minDistance' => $pointBounds['min'],
            'maxDistance' => $pointBounds['max'],
            'resourceSites' => $resourceSites,
            'debrisPoints' => array_column(\SPGame\Game\Repositories\SpaceDebrisFields::publicInSystem($galaxy, $system), 'coordinate'),
            'minOrbit' => 1,
            'maxOrbit' => (int)$systemData['max_orbits'],
            'expeditionOrbit' => $expeditionOrbit,
        ];
    }

}
