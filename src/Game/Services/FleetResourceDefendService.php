<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{FleetCommands, Friends, ResourceBases, SpaceBattles, Users, Vars};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/** Ресурсная DEFEND: дом не меняется, топливо только из бортового бака. */
final class FleetResourceDefendService
{
    public static function isCommand(?array $command): bool
    {
        if ($command === null || (int)($command['command_type'] ?? 0) !== Command::DEFEND->value) return false;
        $target = FleetControlService::params($command)['target'] ?? [];
        return ($target['type'] ?? null) === CoordinateType::OBJECT->value && (int)($target['siteId'] ?? 0) > 0
            && in_array($target['objectType'] ?? null, [ObjectType::GAS_GIANT->value,ObjectType::ASTEROID_SECTOR->value], true);
    }

    public static function holds(?int $siteId = null): array
    {
        $result = [];
        foreach (FleetCommands::getActiveDefenseCommands() as $command) {
            if (!self::isCommand($command)) continue;
            $params = FleetControlService::params($command);
            if (!isset($params['hold']['siteId']) || isset($params['pause'])
                || ($siteId !== null && (int)$params['hold']['siteId'] !== $siteId)) continue;
            $result[] = ['command'=>$command,'params'=>$params];
        }
        usort($result, static fn($a,$b) => $a['command']['id'] <=> $b['command']['id']);
        return $result;
    }

    public static function reason(Fleet $fleet, Coordinate $target, bool $checkCapacity = true, bool $allowBattle = false): ?string
    {
        if (!ResourceBaseService::isEnabled()) return 'Ресурсные базы ещё не включены.';
        $site = FleetSurveyService::site($target);
        if ($target->toArray() !== FleetSurveyService::coordinate($site)->toArray()) return 'Выберите ресурсный объект без ручного угла.';
        $base = ResourceBases::findExistingById((int)$site['id']);
        $hostId = (int)($base['owner_id'] ?? 0);
        if ($hostId <= 0 || $hostId === $fleet->getOwnerId()) return 'Оборона предназначена для ресурсной базы друга.';
        if (!Friends::areFriends($fleet->getOwnerId(),$hostId)) return 'Оборонять можно только подтверждённого друга.';
        $battle = ResourceBaseService::battleAt((int)$site['id']);
        if (!$allowBattle && $battle !== null) return 'Ресурсный объект участвует в бою.';
        $host = self::owner($hostId);
        if ($checkCapacity) {
            $guards = array_fill_keys(array_map(static fn($h) => (int)$h['command']['fleet_id'], self::holds((int)$site['id'])), true);
            foreach (self::battleGuardIds($battle['state'] ?? [], $hostId) as $id) $guards[$id] = true;
            unset($guards[$fleet->getId()]);
            $used = count($guards);
            $limit = ResourceBaseService::friendlyGuardLimit((int)$host['Techs'][Vars::$resource[134]]);
            if ($used >= $limit) return 'На ресурсной базе нет мест для дружественной охраны. Повысьте Флотовую логистику владельца.';
        }
        return null;
    }

    /** Замороженные и pending гости сохраняют место охраны до результата боя. */
    public static function battleGuardIds(array $state, int $hostId): array
    {
        if ($hostId <= 0 || (int)($state['mission']['resourceSite']['ownerId'] ?? 0) !== $hostId
            || (int)($state['mission']['defenderId'] ?? 0) !== $hostId) return [];
        $ids = [];
        foreach ($state['mission']['participants'] ?? [] as $participant) {
            if (($participant['side'] ?? null) === 'defender' && (int)($participant['ownerId'] ?? 0) > 0
                && (int)$participant['ownerId'] !== $hostId && !empty($participant['resourceDefenseCommandId'])
                && (int)($participant['fleetId'] ?? 0) > 0) $ids[(int)$participant['fleetId']] = true;
        }
        $result = array_keys($ids); sort($result, SORT_NUMERIC); return $result;
    }

    /** Чистая добавка к бюджету перелётов, одинаковая для preview и send. */
    public static function travelFuel(array $travel, float $rate, int $duration): array
    {
        FleetDefendService::duration($duration);
        if (!is_finite($rate) || $rate < 0) throw new \InvalidArgumentException('Некорректный расход ресурсной обороны.');
        $work = ceil($rate * $duration);
        foreach (['min','max'] as $bound) $travel['fuel'][$bound] += $work;
        return $travel + ['resourceDefense'=>true,'defendSupplyPerHour'=>$rate * 3600,'defendWorkFuel'=>$work];
    }

    public static function holdEnd(float $until, float $fuel, array $clock): float
    {
        foreach ([$until,$fuel,$clock['time']??null,$clock['rate']??null,$clock['reserve']??null] as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) throw new \InvalidArgumentException('Некорректные часы ресурсной обороны.');
        }
        if ($fuel < 0 || $clock['time'] <= 0 || $until < $clock['time'] || $clock['rate'] < 0 || $clock['reserve'] < 0) {
            throw new \InvalidArgumentException('Некорректный резерв ресурсной обороны.');
        }
        return min($until, $clock['rate'] > 0 ? $clock['time'] + max(0,$fuel-$clock['reserve'])/$clock['rate'] : INF);
    }

    private static function clock(Fleet $fleet, AccountData $owner, Coordinate $target, float $time, float $speed): void
    {
        ResourceBaseService::beginStanding($fleet,$owner,$time,$speed,$target);
        $clock = $fleet->getBaseFuelState(); $clock['rate'] = FleetDefendService::fuelRate($fleet,$owner);
        $fleet->setBaseFuelState($clock);
    }

    public static function arrive(array $command, Fleet $fleet, AccountData $owner): ?array
    {
        $params = FleetControlService::params($command); $at = Coordinate::fromArray($params['target']);
        $time = $fleet->getCurrentPositionTime(); $base = ResourceBases::findExistingById((int)$at->getSiteId());
        $battle = ResourceBaseService::battleAt((int)$at->getSiteId());
        $hostId = (int)($base['owner_id'] ?? 0);
        $reinforcement = $battle !== null && FleetSpaceBattleService::canReinforceResourceDefense($battle['state'],
            $fleet->getOwnerId(), $hostId, $at->toArray(), Friends::areFriends($fleet->getOwnerId(),$hostId));
        $reason = self::reason($fleet,$at,true,$reinforcement);
        if ((int)($params['targetOwnerId'] ?? 0) !== (int)($base['owner_id'] ?? 0)) $reason = 'Владелец ресурсной базы изменился.';
        if ($fleet->getCurrentCoordinate()->toArray() !== $at->toArray()) throw new \LogicException('Флот ещё не прибыл к ресурсной базе.');
        if ($reason === null && $reinforcement) {
            // Проверка бюджета не меняет Fleet до общего commit admission.
            $preview = new Fleet($fleet->getId());
            self::clock($preview,$owner,$at,$time,(float)($params['speedFactor'] ?? 10));
            $clock = $preview->getBaseFuelState();
            if ($preview->getFuelCurrent() <= $clock['reserve'] + .000001) $reason = 'Достигнут топливный резерв возвращения.';
            else {
                $params['hold'] = ['siteId'=>(int)$at->getSiteId(),'ownerId'=>$hostId,'startedAt'=>$time,
                    'until'=>$time+FleetDefendService::duration($params['duration'] ?? null),'rate'=>$clock['rate']];
                $result = FleetSpaceBattleService::reinforceResourceDefense($command,$fleet,$owner,$battle,$params);
                self::notify($owner,$fleet,$at,$hostId,'Охрана прибыла подкреплением в общий бой. Расход бака начнётся после боя.',$time);
                return $result;
            }
        }
        $notice = '';
        $result = FleetControlService::commit([$fleet], static function () use ($command,$fleet,$owner,$params,$at,$time,$base,$reason,&$notice): ?array {
            if ($reason === null) {
                self::clock($fleet,$owner,$at,$time,(float)($params['speedFactor'] ?? 10));
                $clock = $fleet->getBaseFuelState();
                if ($fleet->getFuelCurrent() <= $clock['reserve'] + .000001) $reason = 'Достигнут топливный резерв возвращения.';
            }
            if ($reason !== null) {
                $fleet->setBaseFuelState(null); FleetCommands::markExecuted((int)$command['id']);
                $next = FleetCommandsService::activateNextCommand($fleet,$owner); $fleet->save();
                $notice = $reason.' Флот возвращается.';
                return $next;
            }
            $duration = FleetDefendService::duration($params['duration'] ?? null);
            $params['hold'] = ['siteId'=>(int)$at->getSiteId(),'ownerId'=>(int)$base['owner_id'],
                'startedAt'=>$time,'until'=>$time+$duration,'rate'=>$clock['rate']];
            FleetControlService::patchCommand((int)$command['id'],['execute_time'=>self::holdEnd($time+$duration,$fleet->getFuelCurrent(),$clock),
                'params'=>json_encode($params,JSON_THROW_ON_ERROR)]);
            $fleet->save();
            $notice = 'Начато удержание для обороны. Расходуется бортовой бак; резерв возвращения защищён.';
            return FleetCommands::getCommand((int)$command['id']);
        });
        self::notify($owner,$fleet,$at,(int)($base['owner_id'] ?? 0),$notice,$time);
        return $result;
    }

    public static function complete(array $command, Fleet $fleet, AccountData $owner): bool
    {
        $params = FleetControlService::params($command); $at = Coordinate::fromArray($params['target']);
        $time = max((float)$command['execute_time'],$fleet->getCurrentPositionTime(),(float)($fleet->getBaseFuelState()['time'] ?? 0));
        FleetControlService::commit([$fleet], static function () use ($command,$fleet,$owner,$time): void {
            ResourceBaseService::settleStanding($fleet,$time); $fleet->setBaseFuelState(null);
            $fleet->setCurrentCoordinate($fleet->getCurrentCoordinate(),$time);
            FleetCommands::markExecuted((int)$command['id']); FleetCommandsService::activateNextCommand($fleet,$owner); $fleet->save();
        });
        self::notify($owner,$fleet,$at,(int)$params['hold']['ownerId'],!empty($params['recalled'])
            ? 'Охрана отозвана владельцем. Флот возвращается.' : 'Охрана завершена: срок, условия или топливный резерв. Флот возвращается.',$time);
        return true;
    }

    public static function canInterrupt(?array $command, array $target, int $hostId): bool
    {
        if (!self::isCommand($command) || !empty($command['executed']) || (float)$command['execute_time'] <= 0) return false;
        $p = FleetControlService::params($command); $h = $p['hold'] ?? [];
        foreach ([$command['execute_time'],$h['startedAt']??null,$h['until']??null,$h['rate']??null] as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) return false;
        }
        return !isset($p['pause']) && ($p['target'] ?? null) === $target && ($h['siteId'] ?? null) === ($target['siteId'] ?? null)
            && (int)($h['ownerId'] ?? 0) === $hostId && (float)($h['startedAt'] ?? 0) > 0
            && (float)$h['rate'] >= 0 && (float)$h['until'] > (float)$h['startedAt']
            && (float)($h['until'] ?? 0) >= (float)$command['execute_time'];
    }

    /** Только preview результата боя; меняет переданный preview Fleet, не repositories. */
    public static function restore(array $command, Fleet $preview, AccountData $owner, Coordinate $at, float $time): ?array
    {
        $params = FleetControlService::params($command);
        if (!self::isCommand($command) || ($params['pause'] ?? null) !== 'battle') throw new \LogicException('Нет замороженной ресурсной обороны.');
        $base = ResourceBases::findExistingById((int)$at->getSiteId());
        if ((int)($base['owner_id'] ?? 0) !== (int)($params['hold']['ownerId'] ?? 0)
            || (float)$params['hold']['until'] <= $time || self::reason($preview,$at,true,true) !== null) return null;
        self::clock($preview,$owner,$at,$time,(float)($params['speedFactor'] ?? 10)); $clock = $preview->getBaseFuelState();
        if ($preview->getFuelCurrent() <= $clock['reserve'] + .000001) { $preview->setBaseFuelState(null); return null; }
        unset($params['pause']); $params['hold']['rate'] = $clock['rate'];
        return ['execute_time'=>self::holdEnd((float)$params['hold']['until'],$preview->getFuelCurrent(),$clock),
            'params'=>json_encode($params,JSON_THROW_ON_ERROR)];
    }

    /** Существующий мировой проход DEFEND; отдельного timer нет. */
    public static function reschedule(float $now): void
    {
        $occupied = [];
        foreach (self::holds() as $item) try {
            $command = $item['command']; $params = $item['params']; $id = (int)$command['fleet_id'];
            if (SpaceBattles::forFleet($id) !== null) continue;
            $fleet = FleetsData::getInstance()->get($id); if ($fleet === null) continue;
            $at = Coordinate::fromArray($params['target']); $siteId = (int)$at->getSiteId();
            $base = ResourceBases::findExistingById($siteId); $hostId = (int)($base['owner_id'] ?? 0);
            $invalid = $hostId !== (int)$params['hold']['ownerId'] || self::reason($fleet,$at,false) !== null;
            if (!$invalid) {
                $host = self::owner($hostId); $occupied[$siteId] = ($occupied[$siteId] ?? 0) + 1;
                $invalid = $occupied[$siteId] > ResourceBaseService::friendlyGuardLimit((int)$host['Techs'][Vars::$resource[134]]);
            }
            if ($invalid && (float)$command['execute_time'] > $now) FleetControlService::patchCommand((int)$command['id'],['execute_time'=>$now]);
        } catch (\Throwable $error) { Logger::getInstance()->error('Проверка ресурсной охраны отложена',['message'=>$error->getMessage()]); }
    }

    private static function owner(int $id): AccountData
    {
        $user = Users::findExistingById($id) ?? throw new \LogicException('Владелец ресурсной охраны не найден.');
        return new AccountData((int)$user['account_id']);
    }

    public static function returnAfterBattle(Fleet $fleet, AccountData $owner, array $command, float $time): void
    {
        $params = FleetControlService::params($command);
        FleetCommandsService::returnInterruptedResourceDefense($fleet,$owner,$command,$time);
        self::notify($owner,$fleet,Coordinate::fromArray($params['target']),(int)$params['hold']['ownerId'],
            'После боя срок, условия или резерв охраны недоступны. Флот возвращается.',$time);
    }

    private static function notify(AccountData $owner, Fleet $fleet, Coordinate $at, int $hostId, string $text, float $time): void
    {
        try {
            $site = FleetSurveyService::site($at);
            Notification::sendFleetDefense($owner,$hostId > 0 ? self::owner($hostId) : null,$fleet->getId(),
                ['name'=>$at->getObjectType() === ObjectType::GAS_GIANT ? 'Газовый гигант' : 'Пояс · сектор '.$site['sector'],
                    'galaxy'=>$at->getGalaxy(),'system'=>$at->getSystem(),'planet'=>$at->getOrbit()],$text,$time);
        } catch (\Throwable $error) { Logger::getInstance()->error('Не отправлено сообщение ресурсной охраны',['fleetId'=>$fleet->getId(),'message'=>$error->getMessage()]); }
    }
}
