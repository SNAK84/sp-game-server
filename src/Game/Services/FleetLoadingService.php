<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{FleetCommands, Planets};
use SPGame\Game\Space\{Coordinate, CoordinateType, ObjectType};

/** Только промежуточный LOAD. Начальная погрузка с домашней базы не меняется. */
final class FleetLoadingService
{
    public static function settings(array $input): array
    {
        $amount = static function (mixed $value): float {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > 1e15) {
                throw new \InvalidArgumentException('Количество погрузки должно быть конечным неотрицательным числом до 10¹⁵.');
            }
            return (float)$value;
        };
        $resources = $input['resources'] ?? [];
        if (!is_array($resources) || array_diff(array_keys($resources), [901, 902, 903]) !== []) {
            throw new \InvalidArgumentException('Погрузить можно только металл, кристалл и дейтерий.');
        }
        $fill = $input['fillResource'] ?? null;
        $shortage = $input['shortage'] ?? 'wait';
        if (!in_array($fill, [null, 901, 902, 903], true) || !in_array($shortage, ['wait', 'partial'], true)) {
            throw new \InvalidArgumentException('Некорректный режим промежуточной погрузки.');
        }
        return ['fuel' => $amount($input['fuel'] ?? 0),
            'resources' => array_combine([901, 902, 903], array_map(static fn($id) => $amount($resources[$id] ?? 0), [901, 902, 903])),
            'fillResource' => $fill, 'shortage' => $shortage];
    }

    public static function source(Fleet $fleet, Coordinate $target): array
    {
        if ($target->getType() !== CoordinateType::OBJECT
            || !in_array($target->getObjectType(), [ObjectType::PLANET, ObjectType::MOON], true)) {
            throw new \InvalidArgumentException('Для погрузки выберите свою планету или луну.');
        }
        $source = Planets::findByIndex('galaxy_system_planet_type', [$target->getGalaxy(), $target->getSystem(),
            $target->getOrbit(), $target->getObjectType()->value]);
        if ($source === null || (int)$source['owner_id'] !== $fleet->getOwnerId()) {
            throw new \LogicException('Объект погрузки отсутствует или больше не принадлежит вам.');
        }
        return $source;
    }

    /** Пустой бак не мешает погрузке у объекта, но не разрешает долететь до него. */
    public static function canResumeAtSource(Fleet $fleet): bool
    {
        $queue = FleetCommands::getFleetQueue($fleet->getId());
        $next = $queue[0] ?? null;
        // При правке приостановленного плана редактор сохраняет ручную паузу.
        if ($next !== null && (int)$next['command_type'] === Command::OBSERVE->value && isset(FleetControlService::params($next)['pause'])) {
            $next = $queue[1] ?? null;
        }
        if ($next === null || (int)$next['command_type'] !== Command::LOAD->value || !isset(FleetControlService::params($next)['loading'])) return false;
        $target = FleetCommandsService::getCommandTarget($next);
        $current = $fleet->getCurrentCoordinate();
        return $target !== null && $current->getType() === CoordinateType::OBJECT
            && $target->getType() === CoordinateType::OBJECT && $current->getGalaxy() === $target->getGalaxy()
            && $current->getSystem() === $target->getSystem() && $current->getOrbit() === $target->getOrbit()
            && $current->getObjectType() === $target->getObjectType();
    }

    /** EventLoop уже актуализировал OBJECT до execute_time, до создания AccountData. */
    public static function execute(array $command, Fleet $fleet, AccountData $owner): bool
    {
        if (FleetCommands::getCommand((int)$command['id']) !== $command || (int)$command['executed']) return true;
        $params = FleetControlService::params($command);
        // Receipt переживает отказ активации следующего участка и restart/replay.
        if (isset($params['loaded'])) {
            FleetControlService::patchCommand((int)$command['id'], ['executed' => 1]);
            return true;
        }
        try {
            if ((int)$owner['User']['id'] !== $fleet->getOwnerId()) throw new \LogicException('Владелец флота недоступен.');
            $target = Coordinate::fromArray($params['target']);
            $source = self::source($fleet, $target);
            $current = $fleet->getCurrentCoordinate();
            if ($current->getType() !== CoordinateType::OBJECT || $current->getGalaxy() !== $target->getGalaxy()
                || $current->getSystem() !== $target->getSystem() || $current->getOrbit() !== $target->getOrbit()
                || $current->getObjectType() !== $target->getObjectType()) {
                throw new \LogicException('Флот ещё не прибыл к объекту погрузки.');
            }
            FleetBattleService::assertFleetAvailable($fleet->getId());
            FleetBattleService::assertPlanetAvailable((int)$source['id']);
            $settings = self::settings($params['loading']);
        } catch (\InvalidArgumentException|\LogicException $error) {
            self::pause($command, $error->getMessage());
            return false;
        }
        $previous = $owner['WorkPlanet'];
        $owner['WorkPlanet'] = (int)$source['id'];
        try {
            $stock = [];
            foreach ([901, 902, 903] as $id) $stock[$id] = max(0, (float)$owner['Resources'][$id]['count']);
            $free = max(0, $fleet->getFreeCapacity());
            $fuel = min($settings['fuel'], $stock[903], $free);
            $stock[903] -= $fuel; $free -= $fuel;
            $loaded = [];
            foreach ($settings['resources'] as $id => $requested) {
                $loaded[$id] = min($requested, $stock[$id], $free);
                $stock[$id] -= $loaded[$id]; $free -= $loaded[$id];
            }
            if ($settings['shortage'] === 'wait' && ($fuel + .000001 < $settings['fuel']
                || array_sum($loaded) + .000001 < array_sum($settings['resources']))) {
                self::pause($command, 'Недостаточно ресурсов на объекте или свободной вместимости. Ничего не погружено.');
                return false;
            }
            if (($fill = $settings['fillResource']) !== null) {
                $extra = min($stock[$fill], $free);
                $loaded[$fill] += $extra; $stock[$fill] -= $extra;
            }
            FleetControlService::commit([$fleet], static function () use ($owner, $fleet, $stock, $loaded, $fuel, $command, $params): void {
                foreach ($stock as $id => $count) $owner['Resources'][$id]['count'] = $count;
                // Топливо получает место раньше груза; один и тот же дейтерий не расходуется дважды.
                $fleet->setFuelTotal($fleet->getFuelTotal() + $fuel);
                $fleet->setFuelCurrent($fleet->getFuelCurrent() + $fuel);
                foreach ($loaded as $id => $count) {
                    if ($fleet->getResources()[$id]->add($count) > .000001) {
                        throw new \RuntimeException('Не удалось поместить рассчитанный груз в трюм.');
                    }
                }
                $owner->save(); $fleet->save();
                $params['loaded'] = ['fuel' => $fuel, 'resources' => $loaded];
                unset($params['pause'], $params['planError']);
                $encoded = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (strlen($encoded) > 2048) throw new \RuntimeException('Результат погрузки не помещается в params команды.');
                FleetControlService::patchCommand((int)$command['id'], ['executed' => 1, 'params' => $encoded]);
            }, $owner);
            return true;
        } finally {
            $owner['WorkPlanet'] = $previous;
        }
    }

    private static function pause(array $command, string $message): void
    {
        $params = FleetControlService::params($command);
        $params['planError'] = $message;
        FleetControlService::patchCommand((int)$command['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        FleetControlService::pause(FleetCommands::getCommand((int)$command['id']), 'load');
    }
}
