<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\PlayerQueue;
use SPGame\Game\Repositories\Messages;
use SPGame\Game\Repositories\MessageType;
use SPGame\Game\Repositories\Vars;
use SPGame\Core\Logger;

use SPGame\Game\Services\Lang;

class Notification
{
    // PlayerQueue хранит сериализованный payload в 512 байтах.
    public const MESSAGE_BATCH_LIMIT = 10;

    /** Имя фиксируется при создании сообщения, а не восстанавливается при чтении. */
    public static function fleetName(int $fleetId, ?string $name = null): string
    {
        $name ??= \SPGame\Game\Repositories\Fleets::findExistingById($fleetId)['name'] ?? '';
        return trim($name) !== '' ? $name : 'Флот #' . $fleetId;
    }

    public static function prepareFleetFuelStop(\SPGame\Game\Fleet\Fleet $fleet, array $command,
        string $episode, float $time, bool $cargoRefuel = false): array
    {
        $coordinate = $fleet->getCurrentCoordinate();
        $place = sprintf('[%d:%d]', $coordinate->getGalaxy(), $coordinate->getSystem());
        if ($coordinate->getOrbit() !== null) {
            $place = sprintf('[%d:%d:%d]', $coordinate->getGalaxy(), $coordinate->getSystem(), $coordinate->getOrbit());
            $place .= $coordinate->getObjectType() === \SPGame\Game\Space\ObjectType::MOON ? ' Луна' : '';
        } elseif ($coordinate->getDistance() !== null) {
            $place .= sprintf(' · расстояние %.3f, угол %.3f°', $coordinate->getDistance(), $coordinate->getAngle());
        }
        $target = FleetControlService::params($command)['target'] ?? [];
        $targetLabel = isset($target['fleetId']) ? 'Флот #' . (int)$target['fleetId']
            : (isset($target['orbit']) ? sprintf('[%d:%d:%d]', $target['galaxy'], $target['system'], $target['orbit'])
                . ((int)($target['objectType'] ?? 0) === 3 ? ' Луна' : '')
                : (isset($target['distance']) ? sprintf('[%d:%d] · расстояние %.3f, угол %.3f°',
                    $target['galaxy'], $target['system'], $target['distance'], $target['angle']) : '—'));
        $data = ['FleetId' => $fleet->getId(), 'FleetName' => self::fleetName($fleet->getId(), $fleet->getName()),
            'FuelEpisode' => $episode, 'Position' => $place, 'TargetCoordinate' => $targetLabel,
            'ResourcesCargoFuel' => [903 => (float)(($fleet->getResources()[903] ?? null)?->getCount() ?? 0)],
            'lang' => ['Command' => 'fleet_command.' . strtolower(\SPGame\Game\Enums\FleetCommandType::from((int)$command['command_type'])->name),
                'CargoRefuelAdvice' => 'fuelAdvice.' . ($cargoRefuel ? 'available' : 'locked')]];
        return Messages::castRowToSchema(['to_id' => $fleet->getOwnerId(), 'type' => Messages::Type::Transport->value,
            'from' => 'from.Transport', 'subject' => 'subject.Transport.FuelStop', 'sample' => 'FleetFuelStopped',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'time' => $time], true);
    }

    public static function pushMessages(AccountData $owner): void
    {
        PlayerQueue::addQueue((int)$owner['Account']['id'],(int)$owner['User']['id'],(int)$owner['Planet']['id'],PlayerQueue::ActionMessagesNew);
    }

    public static function sendMiningResult(AccountData $owner, \SPGame\Game\Fleet\Fleet $fleet, array $command): void
    {
        $run = FleetControlService::params($command)['mining'];
        $reason = ['duration' => 'заданное время истекло', 'cargo' => 'трюм заполнен', 'fuel' => 'достигнут резерв топлива',
            'stock' => 'запас объекта исчерпан', 'reserve' => 'известный резерв исчерпан', 'recall' => 'флот отозван',
            'battle' => 'работа прервана боем', 'control' => 'контроль объекта утрачен'][$run['reason']] ?? $run['reason'];
        self::miningNotice($owner, $fleet, $command, 'FleetMiningResult', [
            'TargetCoordinate' => FleetSurveyService::label(\SPGame\Game\Repositories\OrbitalResourceSites::findExistingById((int)$run['siteId'])),
            'Reason' => $reason, 'WorkMinutes' => round(max(0, $run['endedAt'] - $run['startedAt']) / 60, 2),
            'Resources' => $run['extracted'], 'FuelSpent' => round($run['fuelSpent'], 3),
            'ResourcesFuelOnboard' => [903 => $fleet->getFuelCurrent()],
        ], (float)$run['endedAt'], (string)$run['startedAt']);
    }

    public static function sendMiningRejected(AccountData $owner, \SPGame\Game\Fleet\Fleet $fleet, array $command, string $reason): void
    {
        self::miningNotice($owner, $fleet, $command, 'FleetMiningRejected', ['Reason' => $reason],
            $fleet->getCurrentPositionTime(), (string)$fleet->getCurrentPositionTime());
    }

    /** Отчёт сохраняется раньше удаления MINING; retry/restart не дублируют его. */
    private static function miningNotice(AccountData $owner, \SPGame\Game\Fleet\Fleet $fleet, array $command,
        string $sample, array $data, float $time, string $episode): void
    {
        $key = hash('sha256', $sample . ':' . $fleet->getId() . ':' . $command['id'] . ':' . $episode);
        $message = null;
        foreach (Messages::findByIndex('to', [$fleet->getOwnerId()]) ?? [] as $row) {
            if ($row['sample'] === $sample && (json_decode($row['data'], true)['MiningEpisode'] ?? null) === $key) { $message = $row; break; }
        }
        if ($message === null) {
            $data += ['FleetId' => $fleet->getId(), 'FleetName' => self::fleetName($fleet->getId(), $fleet->getName()), 'MiningEpisode' => $key];
            $id = Messages::add(['to_id' => $fleet->getOwnerId(), 'type' => Messages::Type::Transport->value,
                'from' => 'from.Transport', 'subject' => 'subject.Transport.Mining', 'sample' => $sample,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'time' => $time]);
            $message = Messages::findExistingById((int)$id) ?? throw new \RuntimeException('Не записан отчёт добычи.');
        }
        Messages::syncToDatabase();
        if (Messages::checkpointDurableRow((int)$message['id']) === null) throw new \RuntimeException('Не сохранён отчёт добычи в MySQL.');
        self::pushMessages($owner);
    }

    public static function prepareFleetExpeditionResult(AccountData $owner, \SPGame\Game\Fleet\Fleet $fleet,
        array $result,array $coordinate,float $time): array
    {
        $resources = $result['resources'];
        if ($result['credits'] > 0) $resources[921] = $result['credits'];
        $ships = [];
        $names = Lang::load($owner['Account']['lang'] ?? 'ru')['tech'] ?? [];
        foreach ($result['ships'] as $id=>$count) $ships[] = ($names[$id] ?? Vars::$resource[$id]) . ': ' . $count;
        $data = ['FleetId'=>$fleet->getId(),'FleetName'=>self::fleetName($fleet->getId(), $fleet->getName()),'TargetCoordinate'=>FleetExpeditionService::label($coordinate),
            'lang'=>['Outcome'=>'expedition.'.($result['enemy'] ?? $result['event'])],
            'ResourcesFound'=>array_filter($resources,static fn($amount)=>$amount>0),'ShipsFound'=>$ships===[]?'—':implode(', ',$ships),
            'TimeFactor'=>$result['timeFactor'],'FoundFuel'=>$result['foundFuel'] ?? 0,'BattleOutcome'=>''];
        if (isset($result['battleId'])) $data += ['BattleId'=>$result['battleId'],'ReportVersion'=>1];
        if (isset($result['outcome'])) {
            unset($data['BattleOutcome']);
            $data['lang']['BattleOutcome'] = 'battle.'.$result['outcome'];
        }
        $data['lang']['FleetState'] = 'expedition.'.($result['lost'] ? 'destroyed' : 'returning');
        $sample = match ($result['event']) {
            'resources','credits' => 'FleetExpeditionResources',
            'ships' => 'FleetExpeditionShips',
            'time' => 'FleetExpeditionTime',
            default => 'FleetExpeditionResult',
        };
        return Messages::castRowToSchema(['to_id'=>(int)$owner['User']['id'],'type'=>Messages::Type::Expeditions->value,
            'from'=>'from.Expeditions','subject'=>'subject.Expeditions.Success','sample'=>$sample,
            'data'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'time'=>$time],true);
    }

    /** Один сводный отчёт; часовые находки до этого остаются только игровым состоянием. */
    public static function prepareFleetExpeditionSummary(AccountData $owner, \SPGame\Game\Fleet\Fleet $fleet,
        array $state,float $time): array
    {
        $resources = [901=>0.0,902=>0.0,903=>0.0,921=>0.0]; $ships=[]; $counts=[]; $battles=[];
        $lang = Lang::load($owner['Account']['lang'] ?? 'ru');
        foreach ($state['events'] as $event) {
            $counts[$event['event']] = ($counts[$event['event']] ?? 0) + 1;
            foreach ($event['resources'] as $id=>$amount) $resources[$id] += $amount;
            $resources[921] += $event['credits'];
            foreach ($event['ships'] as $id=>$count) $ships[$id] = ($ships[$id] ?? 0) + $count;
            if (isset($event['battleId'])) $battles[] = $event['battleId'];
        }
        $events=[]; $names=[]; $onboard=[];
        foreach ($counts as $event=>$count) $events[] = ($lang['notifications']['expeditionEvents'][$event] ?? $event).': '.$count;
        foreach ($ships as $id=>$count) $names[] = ($lang['tech'][$id] ?? Vars::$resource[$id]).': '.$count;
        if (($state['endReason'] ?? '') !== 'lost') foreach ($fleet->getResources() as $id=>$resource) $onboard[$id] = $resource->getCount();
        $data = ['FleetId'=>$fleet->getId(),'FleetName'=>self::fleetName($fleet->getId(), $fleet->getName()),'TargetCoordinate'=>FleetExpeditionService::label($state['coordinate']),
            'EventsCount'=>count($state['events']),'HoursPlanned'=>(int)(($state['endsAt']-$state['startedAt'])/3600),
            'EventsSummary'=>implode('; ',$events),'EventCounts'=>$counts,
            'ResourcesFound'=>array_filter($resources,static fn($amount)=>$amount>0),
            'ResourcesOnboard'=>array_filter($onboard,static fn($amount)=>$amount>0),
            'ShipsFound'=>$names===[]?'—':implode(', ',$names),'TimeFactor'=>$state['timeFactor'],
            'lang'=>['FleetState'=>'expedition.'.match($state['endReason'] ?? 'completed') {
                'lost'=>'destroyed','recalled'=>'recalled',default=>'returning',
            }], 'BattleIds'=>array_values(array_unique($battles)),'ReportVersion'=>1];
        if (count($battles)===1) $data['BattleId']=$battles[0];
        $encoded = json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($encoded)>8192) throw new \OverflowException('Сводный отчёт экспедиции не помещается в сообщение.');
        return Messages::castRowToSchema(['to_id'=>(int)$owner['User']['id'],'type'=>Messages::Type::Expeditions->value,
            'from'=>'from.Expeditions','subject'=>'subject.Expeditions.Success','sample'=>'FleetExpeditionSummary',
            'data'=>$encoded,'time'=>$time],true);
    }

    public static function sendFleetExpeditionReturned(AccountData $owner, \SPGame\Game\Fleet\Fleet $fleet,array $notification,float $time): void
    {
        $resources = $notification['unloadedResources'];
        $credits = (float)($notification['expeditionResult']['credits'] ?? 0);
        if ($credits > 0) $resources[921] = $credits;
        self::sendSystem($owner,Messages::Type::Expeditions->value,'from.Expeditions','subject.Transport.Return','FleetExpeditionReturned',
            ['FleetId'=>$fleet->getId(),'PlanetName'=>self::planetName($notification['planet'], $owner),
                'PlanetCoordinate'=>self::planetCoordinate($notification['planet']),'Resources'=>$resources,
                'ResourcesFuelReturned'=>[903=>(float)($notification['unloadedFuel'] ?? 0)]],$time);
    }

    /** Заявки и ответы — обычные игровые сообщения, как в Nova. */
    public static function sendFriendshipChange(int $actorId, int $recipientId, string $event, string $text): void
    {
        $actor = \SPGame\Game\Repositories\Users::findById($actorId);
        $recipient = \SPGame\Game\Repositories\Users::findById($recipientId);
        if ($actor === null || $recipient === null) return;
        $account = new AccountData((int)$recipient['account_id']);
        self::sendSystem($account, Messages::Type::Players->value, 'from.Friends', 'subject.Friends.' . $event,
            'FriendshipChange', ['PlayerName' => $actor['name'], 'RequestText' => $event === 'request' ? $text : '',
                'lang' => ['State' => 'friends.' . $event]]);
    }

    /** Подготовить штатную строку сообщения для общего runtime commit результата боя. */
    public static function prepareFleetBattleReport(int $ownerId, array $summary, float $time): array
    {
        $data = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($data) > 8192) throw new \OverflowException('Боевой доклад не помещается в сообщение.');
        return Messages::castRowToSchema(['to_id' => $ownerId, 'type' => Messages::Type::Fights->value,
            'from' => 'from.Fights', 'subject' => 'subject.Fights.BattleReport',
            'sample' => 'FleetBattleReport', 'data' => $data, 'time' => $time], true);
    }

    public static function sendGroupAttackInvitation(array $group, ?int $recipientId = null, ?int $actorId = null): void
    {
        $users = \SPGame\Game\Repositories\Users::class;
        $owner = $users::findExistingById($actorId ?? (int)$group['owner_id']);
        $target = \SPGame\Game\Repositories\Planets::findExistingById((int)$group['target_id']);
        foreach (json_decode($group['users'], true, 512, JSON_THROW_ON_ERROR) as $userId) {
            if ($userId === (int)$group['owner_id']) continue;
            if ($recipientId !== null && $recipientId !== $userId) continue;
            $user = $users::findExistingById($userId);
            if ($user === null) continue;
            $row = Messages::castRowToSchema(['to_id' => $userId, 'type' => Messages::Type::Fights->value,
                'from' => 'from.Fights', 'subject' => 'subject.Fights.GroupInvitation', 'sample' => 'FleetGroupInvitation',
                'data' => json_encode(['GroupId' => (int)$group['id'], 'PlayerName' => (string)$owner['name'],
                    'TargetCoordinate' => self::planetCoordinate($target),
                    'ArrivalTime' => date('d.m.Y H:i:s', (int)$group['execute_time'])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'time' => microtime(true)], true);
            Messages::add($row);
            if (Messages::findExistingById((int)$row['id']) !== $row) throw new \RuntimeException('Приглашение не сохранено.');
            PlayerQueue::addQueue((int)$user['account_id'], $userId, (int)$user['current_planet'], PlayerQueue::ActionMessagesNew);
        }
    }

    /**
     * Отправить системное сообщение
     */
    public static function sendSystem(AccountData $AccountData, int $Type, string $From, string $Subject, string $Sample, array $Data, float $Time = null): void
    {
        $Time = $Time ?? microtime(true);

        if (isset($Data['FleetId'])) {
            $Data['FleetName'] = self::fleetName((int)$Data['FleetId'], $Data['FleetName'] ?? null);
        }

        $userId = $AccountData['User']['id'] ?? 0;
        if (!$userId) {
            Logger::getInstance()->error("Notification::system() — не удалось определить ID пользователя");
            return;
        }

        $Message = [
            'to_id' => $userId,
            'type'  => $Type,
            'from' => $From,
            'subject' => $Subject,
            'sample' => $Sample,
            'data' => json_encode($Data),
            'time' => $Time
        ];

        Messages::add($Message);

        PlayerQueue::addQueue($AccountData['Account']['id'], $userId, $AccountData['Planet']['id'], PlayerQueue::ActionMessagesNew);
    }

    /**
     * Уведомление от строительства
     */
    public static function sendBuildBuyable(AccountData $AccountData, array $cost, string $QueueAction, int $Element, float $Time = null): void
    {
        $Time = $Time ?? microtime(true);

        $Resources = [];
        foreach ($cost as $resid => $count) {
            $Resources[$resid] = $AccountData['Resources'][$resid]['count'];
        }

        $Data = [
            'lang' => [
                'PlanetType' => ($AccountData['Planet']['planet_type'] === 1) ? 'TypePlanet' : 'TypeMoon',
                'queueProcessGen' => 'queueProcessGen.' . $QueueAction,
                'ElementId' => "Element." . $Element,
            ],
            'PlanetName' => self::planetName($AccountData['Planet'], $AccountData),
            'PlanetGalaxy' => $AccountData['Planet']['galaxy'],
            'PlanetSystem' => $AccountData['Planet']['system'],
            'PlanetPlanet' => $AccountData['Planet']['planet'],
            'Resources' => $Resources,
            'ResourcesCost' => $cost,
        ];

        $MessageSample = 'BuildBuyable';

        $MessageType = Messages::Type::Build->value;

        //$LangSubject = Lang::get($AccountData['Account']['lang'], "notifications")['subjects'];
        $MessageSubject = "";
        $MessageFrom = '';
        switch (QueuesServices::QueueType($Element)) {
            case QueuesServices::BUILDS:
                $MessageSubject = 'subject.Build.BuildBuildingCant';
                $MessageFrom = 'from.Build.BuildBuilding';
                break;
            case QueuesServices::TECHS:
                $MessageSubject = 'subject.Build.BuildTechCant';
                $MessageFrom = 'from.Build.BuildTech';
                break;
            case QueuesServices::HANGARS:
                $MessageSubject = 'subject.Build.BuildHangarCant';
                $MessageFrom = 'from.Build.BuildHangar';
                break;
        }

        self::sendSystem($AccountData, $MessageType, $MessageFrom, $MessageSubject, $MessageSample, $Data, $Time);
    }

    /**
     * Уведомить об успешной доставке ресурсов транспортным Fleet.
     *
     * Владелец Fleet получает подтверждение доставки. Владелец целевой
     * планеты получает отдельное сообщение только если это другой игрок.
     */
    public static function sendFleetTransportArrival(
        AccountData $OwnerAccountData,
        AccountData $TargetAccountData,
        int $FleetId,
        array|\SPGame\Game\Space\Coordinate $SourcePlanet,
        array $TargetPlanet,
        array $Resources,
        float $Time = null
    ): void {
        $Time = $Time ?? microtime(true);
        $Data = [
            'FleetId' => $FleetId,
            'SourcePlanetName' => is_array($SourcePlanet) ? self::planetName($SourcePlanet, $OwnerAccountData) : '',
            'SourceCoordinate' => is_array($SourcePlanet) ? self::planetCoordinate($SourcePlanet)
                : self::transportSourceLabel($SourcePlanet),
            'TargetPlanetName' => self::planetName($TargetPlanet, $OwnerAccountData),
            'TargetCoordinate' => self::planetCoordinate($TargetPlanet),
            'Resources' => $Resources,
        ];

        self::sendSystem(
            $OwnerAccountData,
            Messages::Type::Transport->value,
            'from.Transport',
            'subject.Transport.Arrival',
            'FleetTransportArrivalOwner',
            $Data,
            $Time
        );

        if ((int)$TargetAccountData['User']['id'] === (int)$OwnerAccountData['User']['id']) {
            return;
        }

        // Каждый получатель видит имя на своём языке; исходные строки не меняются.
        $Data['SourcePlanetName'] = is_array($SourcePlanet) ? self::planetName($SourcePlanet, $TargetAccountData) : '';
        $Data['TargetPlanetName'] = self::planetName($TargetPlanet, $TargetAccountData);
        self::sendSystem(
            $TargetAccountData,
            Messages::Type::Transport->value,
            'from.Transport',
            'subject.Transport.Arrival',
            'FleetTransportArrivalTarget',
            $Data,
            $Time
        );
    }

    /** У ресурсной базы нет UNLOAD: не называем оставшийся на борту груз доставленным в склад. */
    public static function sendFleetResourceBaseArrival(AccountData $owner, \SPGame\Game\Fleet\Fleet $fleet,
        \SPGame\Game\Space\Coordinate $coordinate, float $time): void
    {
        $resources = [];
        foreach ([901, 902, 903] as $id) $resources[$id] = (float)(($fleet->getResources()[$id] ?? null)?->getCount() ?? 0);
        self::sendSystem($owner, Messages::Type::Transport->value, 'from.Transport',
            'subject.Transport.ResourceBaseArrival', 'FleetResourceBaseArrival', [
                'FleetId' => $fleet->getId(), 'FleetName' => $fleet->getName(),
                'BaseCoordinate' => FleetSurveyService::label(FleetSurveyService::site($coordinate)),
                'Resources' => $resources, 'ResourcesFuelOnboard' => [903 => $fleet->getFuelCurrent()],
            ], $time);
    }

    public static function sendResourceBaseChange(AccountData $owner, int $fleetId,
        \SPGame\Game\Space\Coordinate $at, string $reason, float $time): void
    {
        $fleet = FleetsData::getInstance()->get($fleetId);
        if ($fleet === null) return;
        try { $home = FleetExpeditionService::label(ResourceBaseService::homeCoordinate($fleet)->toArray()); }
        catch (\LogicException|\InvalidArgumentException) { $home = 'недоступна'; }
        if (FleetControlService::state($fleet)['reason'] === 'fuel') {
            $reason .= ' Топлива на перелёт недостаточно: флот стоит на месте и ждёт дозаправки.';
        }
        self::sendSystem($owner, Messages::Type::Transport->value, 'from.Transport',
            'subject.Transport.ResourceBaseChanged', 'FleetResourceBaseChanged', [
                'FleetId' => $fleetId, 'Reason' => $reason,
                'BaseCoordinate' => FleetSurveyService::label(FleetSurveyService::site($at)),
                'HomeCoordinate' => $home, 'ResourcesFuelOnboard' => [903 => $fleet->getFuelCurrent()],
            ], $time);
    }

    public static function sendResourceCaptureFailed(AccountData $owner, \SPGame\Game\Fleet\Fleet $fleet, string $reason, float $time): void
    {
        self::sendSystem($owner, Messages::Type::Transport->value, 'from.Transport',
            'subject.Transport.ResourceBaseChanged', 'FleetResourceCaptureFailed', [
                'FleetId'=>$fleet->getId(), 'FleetName'=>$fleet->getName(), 'Reason'=>$reason,
            ], $time);
    }

    /**
     * Уведомить владельца о полном завершении возврата Fleet.
     */
    public static function sendFleetReturned(
        AccountData $AccountData,
        int $FleetId,
        array $Planet,
        float $Time = null,
        ?float $UnloadedFuel = null,
        array $UnloadedResources = []
    ): void {
        $resources = array_filter($UnloadedResources, static fn($amount) => $amount > 0);
        $sample = $resources !== []
            ? ($UnloadedFuel === null ? 'FleetReturnedWithCargo' : 'FleetReturnedWithCargoAndFuel')
            : ($UnloadedFuel === null ? 'FleetReturned' : 'FleetReturnedWithFuel');
        self::sendSystem(
            $AccountData,
            Messages::Type::Transport->value,
            'from.Transport',
            'subject.Transport.Return',
            $sample,
            [
                'FleetId' => $FleetId,
                'PlanetName' => self::planetName($Planet, $AccountData),
                'PlanetCoordinate' => self::planetCoordinate($Planet),
            ] + ($resources === [] ? [] : ['Resources' => $resources])
                + ($UnloadedFuel === null ? [] : ['ResourcesFuelReturned' => [903 => $UnloadedFuel]]),
            $Time
        );
    }

    /**
     * Уведомить владельца об успешной смене объекта базирования Fleet.
     */
    public static function sendFleetRelocated(
        AccountData $AccountData,
        int $FleetId,
        array $Planet,
        float $Time = null,
        ?float $UnloadedFuel = null
    ): void {
        self::sendSystem(
            $AccountData,
            Messages::Type::Transport->value,
            'from.Transport',
            'subject.Transport.Relocate',
            $UnloadedFuel === null ? 'FleetRelocated' : 'FleetRelocatedWithFuel',
            [
                'FleetId' => $FleetId,
                'PlanetName' => self::planetName($Planet, $AccountData),
                'PlanetCoordinate' => self::planetCoordinate($Planet),
            ] + ($UnloadedFuel === null ? [] : ['ResourcesFuelReturned' => [903 => $UnloadedFuel]]),
            $Time
        );
    }

    /** Существующий message/push pipeline, без отдельного протокола колонизации. */
    public static function sendFleetColonizationResult(AccountData $owner, int $fleetId, array $result, float $time): void
    {
        $coordinate = $result['coordinate'];
        $data = ['FleetId' => $fleetId, 'FleetName' => self::fleetName($fleetId, $result['fleetName'] ?? null), 'TargetCoordinate' => sprintf('[%d:%d:%d]',
            $coordinate['galaxy'], $coordinate['system'], $coordinate['orbit'])];
        if ($result['success']) {
            $data += ['PlanetName' => self::planetName($result['planet'], $owner), 'Resources' => $result['resources'],
                'Fuel' => $result['depositedFuel']];
        } else {
            $data['Reason'] = $result['reason'];
        }
        self::sendSystem($owner, Messages::Type::System->value, 'from.Colonization',
            $result['success'] ? 'subject.Colonization.Success' : 'subject.Colonization.Failed',
            $result['success'] ? 'FleetColonizationSuccess' : 'FleetColonizationFailed', $data, $time);
    }

    /** Оба участника получают уведомление через существующий message/push pipeline. */
    public static function sendFleetDefense(AccountData $owner, ?AccountData $host, int $fleetId,
        array $planet, string $state, float $time): void
    {
        foreach ([$owner, $host] as $account) {
            if ($account === null) continue;
            self::sendSystem($account, Messages::Type::Transport->value, 'from.Transport',
                'subject.Transport.Defense', 'FleetDefense', ['FleetId' => $fleetId,
                    'TargetPlanetName' => self::planetName($planet, $account),
                    'TargetCoordinate' => self::planetCoordinate($planet), 'State' => $state], $time);
        }
    }

    /** Только известное место отправления, без запроса живой позиции целевого флота. */
    private static function transportSourceLabel(\SPGame\Game\Space\Coordinate $source): string
    {
        if (FleetSurveyService::isResource($source)) return FleetSurveyService::label(FleetSurveyService::site($source));
        if ($source->getFleetId() !== null) return 'Флот #' . $source->getFleetId();
        if ($source->getOrbit() !== null) return sprintf('[%d:%d:%d]', $source->getGalaxy(), $source->getSystem(), $source->getOrbit());
        return sprintf('[%d:%d] · расстояние %.3f, угол %.3f°', $source->getGalaxy(), $source->getSystem(), $source->getDistance(), $source->getAngle());
    }

    /** Имя снимка фиксируется на языке получателя, без изменения runtime-строки. */
    private static function planetName(array|\ArrayAccess $planet, AccountData $account): string
    {
        return CelestialNameGenerator::planetDisplayName([
            'name' => (string)($planet['name'] ?? ''),
            'name_generated' => (bool)($planet['name_generated'] ?? false),
        ], (string)($account['Account']['lang'] ?? 'ru'));
    }

    private static function planetCoordinate(array $Planet): string
    {
        return sprintf(
            '[%d:%d:%d]',
            (int)($Planet['galaxy'] ?? 0),
            (int)($Planet['system'] ?? 0),
            (int)($Planet['planet'] ?? 0)
        );
    }

    /** Разведданные получает только отправитель; цель — отдельное уведомление. */
    public static function sendFleetSpyResult(AccountData $owner, int $fleetId, array $result, float $time): void
    {
        if ($result['unavailable'] ?? false) {
            $coordinate = $result['coordinate'];
            self::sendSystem($owner, Messages::Type::Spy->value, 'from.Spy', 'subject.Spy.Failed',
                'FleetSpyUnavailable', ['FleetId' => $fleetId, 'FleetName' => self::fleetName($fleetId, $result['fleetName'] ?? null), 'TargetCoordinate' => sprintf('[%d:%d:%d]',
                    $coordinate['galaxy'], $coordinate['system'], $coordinate['orbit'])], $time);
            return;
        }
        $site = $result['targetSite'] ?? null;
        $planet = $result['targetPlanet'] ?? null;
        $target = $result['targetAccountData'];
        $data = [
            'FleetId' => $fleetId,
            'FleetName' => self::fleetName($fleetId, $result['fleetName'] ?? null),
            'PlayerName' => (string)$owner['User']['name'],
            'TargetPlanetName' => $site === null ? self::planetName($planet, $owner) : (($site['name'] ?? '') ?: 'Ресурсная база'),
            'TargetCoordinate' => $site === null ? self::planetCoordinate($planet) : FleetSurveyService::label($site),
            'lang' => ['Outcome' => $result['report']['destroyed'] ? 'spy.Destroyed' : 'spy.Returning'],
        ];
        self::sendSystem($owner, Messages::Type::Spy->value, 'from.Spy', 'subject.Spy.Report',
            'FleetSpyReport', $data + ['SpyReport' => $result['report']], $time);
        if ($site === null) $data['TargetPlanetName'] = self::planetName($planet, $target);
        self::sendSystem($target, Messages::Type::Spy->value, 'from.Spy', 'subject.Spy.Detected',
            'FleetSpyDetected', $data, $time);
    }

    public static function sendFleetSpyReturned(AccountData $owner, int $fleetId, array $planet, float $time,
        ?float $unloadedFuel = null, array $unloadedResources = []): void
    {
        $resources = array_filter($unloadedResources, static fn($amount) => $amount > 0);
        $sample = $resources !== []
            ? ($unloadedFuel === null ? 'FleetReturnedWithCargo' : 'FleetReturnedWithCargoAndFuel')
            : ($unloadedFuel === null ? 'FleetReturned' : 'FleetReturnedWithFuel');
        self::sendSystem($owner, Messages::Type::Spy->value, 'from.Spy', 'subject.Spy.Return',
            $sample,
            ['FleetId' => $fleetId, 'PlanetName' => self::planetName($planet, $owner),
                'PlanetCoordinate' => self::planetCoordinate($planet)]
                + ($resources === [] ? [] : ['Resources' => $resources])
                + ($unloadedFuel === null ? [] : ['ResourcesFuelReturned' => [903 => $unloadedFuel]]), $time);
    }

    /** Отчёт о фактически собранных ресурсах, в том числе о пустом поле при прилёте. */
    public static function sendFleetRecycleResult(AccountData $owner, int $fleetId, array $result, float $time): void
    {
        $coordinate = $result['coordinate'];
        self::sendSystem($owner, Messages::Type::Transport->value, 'from.Transport', 'subject.Transport.Recycle',
            'FleetRecycleCollected', [
                'FleetId' => $fleetId,
                'TargetCoordinate' => isset($coordinate['orbit'])
                    ? sprintf('[%d:%d:%d]', $coordinate['galaxy'], $coordinate['system'], $coordinate['orbit'])
                    : sprintf('[%d:%d] %.2f / %.2f°', $coordinate['galaxy'], $coordinate['system'], $coordinate['distance'], $coordinate['angle']),
                'Resources' => $result['resources'],
            ], $time);
    }

    /** Существующий ActualData/PlayerQueue, только зрители затронутой карты. */
    public static function refreshGalaxySystem(int $galaxy, int $system): void
    {
        foreach (\SPGame\Game\Repositories\Accounts::getOnline() as $account) {
            if ($account['mode'] !== 'galaxy') continue;
            $view = \SPGame\Core\Connect::getGalaxyView((int)$account['frame']);
            if (($view['galaxy'] ?? 0) !== $galaxy || ($view['system'] ?? 0) !== $system) continue;
            try {
                foreach (PlayerQueue::getByAccount((int)$account['id']) ?? [] as $event) {
                    if ($event['action'] === PlayerQueue::SendActualeData) continue 2;
                }
                $user = \SPGame\Game\Repositories\Users::findByAccount((int)$account['id']);
                if ($user !== null) PlayerQueue::addQueue((int)$account['id'], (int)$user['id'],
                    (int)$user['current_planet'], PlayerQueue::SendActualeData);
            } catch (\Throwable $error) {
                Logger::getInstance()->error('Обновление карты обломков не отправлено',
                    ['accountId' => $account['id'], 'message' => $error->getMessage()]);
            }
        }
    }

    public static function NewMessages(int $userId): int
    {
        $count = Messages::findByIndex("to_read", [$userId, 0]) ?? [];

        return count($count);
    }

    public static function getAllMessages(int $userId): ?array
    {
        $Messages = Messages::findByIndex("to", [$userId]);

        if (!$Messages) {
            return null;
        }

        // Сортируем по времени (от новых к старым)
        usort($Messages, static function ($a, $b) {
            // time хранится как float (microtime)
            return $b['time'] <=> $a['time'];
        });

        return $Messages;
    }

    /**
     * Получить все сообщения пользователя указанного типа
     *
     * @param int $userId ID пользователя
     * @param MessageType $Type Тип сообщений (enum)
     * @return array|null
     */
    public static function getTypeMessages(int $userId, MessageType $Type): ?array
    {
        $Messages = Messages::findByIndex("to_type", [$userId, $Type->value]);

        if (!$Messages) {
            return null;
        }

        // Сортируем по времени (от новых к старым)
        usort($Messages, static function ($a, $b) {
            // time хранится как float (microtime)
            return $b['time'] <=> $a['time'];
        });

        return $Messages;
    }

    public static function setReadMessages(int $userId, ?array $ReadId): void
    {
        if (!$ReadId) return;

        foreach ($ReadId as $id) {
            $Message = Messages::findById($id);
            if (!$Message || $Message['to_id'] !== $userId)
                continue;

            $Message['read'] = 1;
            Messages::update($Message);
        }
        //Logger::getInstance()->info("setReadMessages $userId", [$ReadId]);
    }

    /** Ограниченная пачка ID, без неявного приведения bool/float/объектов. */
    public static function normalizeMessageIds(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::MESSAGE_BATCH_LIMIT) {
            throw new \InvalidArgumentException('Некорректный список сообщений.');
        }
        $result = [];
        foreach ($ids as $id) {
            if (!(is_int($id) || (is_string($id) && ctype_digit($id)))
                || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \InvalidArgumentException('Некорректный список сообщений.');
            }
            $result[] = (int)$id;
        }
        return array_values(array_unique($result));
    }

    /** Проверяем владельца повторно при исполнении события, а не только при ACK. */
    public static function deleteMessages(int $userId, array $ids): int
    {
        $deleted = 0;
        foreach (self::normalizeMessageIds($ids) as $id) {
            $message = Messages::findById($id);
            if (!$message || (int)$message['to_id'] !== $userId) {
                continue;
            }
            Messages::delete($message);
            $deleted++;
        }
        return $deleted;
    }
}
