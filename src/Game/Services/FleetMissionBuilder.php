<?php

namespace SPGame\Game\Services;

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Enums\FleetMissionType;
use SPGame\Game\Space\Coordinate;
use SPGame\Game\Space\CoordinateType;

/**
 * Генератор цепочек команд для миссий флота.
 * Исправленный: корректно обрабатывает случаи внутри одной системы,
 * между системами и между галактиками, разделяет forward/return части.
 */
class FleetMissionBuilder
{
    /**
     * Построить цепочку команд для миссии
     *
     * @param FleetMissionType $mission
     * @param Coordinate $start физическая начальная координата Fleet.
     * @param Coordinate $target логическая цель миссии.
     * @param array $options ['return' => bool, 'leave_at_target' => bool, 'final_action' => 'stay'|'land'|'none', ...]
     * @return array список команд вида ['type' => FleetCommandType::..., 'params' => [...]]
     */
    public static function build(FleetMissionType $mission, Coordinate $start, Coordinate $target, array $options = []): array
    {
        return match ($mission) {
            FleetMissionType::ATTACK => self::buildAttack($start, $target, $options),
            FleetMissionType::GROUP_ATTACK => self::buildGroupAttack($start, $target, $options),
            FleetMissionType::DESTROY => self::buildDestroy($start, $target, $options),
            FleetMissionType::TRANSPORT => self::buildTransport($start, $target, $options),
            FleetMissionType::COLONIZE => self::buildColonize($start, $target, $options),
            FleetMissionType::RECYCLE => self::buildRecycle($start, $target, $options),
            FleetMissionType::EXPEDITION => self::buildExpedition($start, $target, $options),
            FleetMissionType::DEFEND => self::buildDefend($start, $target, $options),
            FleetMissionType::SPY => self::buildSpy($start, $target, $options),
            FleetMissionType::RESEARCH => self::buildResearch($start, $target, $options),
            FleetMissionType::RELOCATE => self::buildRelocate($start, $target, $options),
            FleetMissionType::CAPTURE => self::buildCapture($start, $target, $options),
            FleetMissionType::MINING => self::buildMining($start, $target, $options),
            FleetMissionType::MERGE => self::buildMerge($start, $target, $options),
            FleetMissionType::OBSERVE => self::buildObserve($start, $target, $options),
            default => [],
        };
    }

    // --------------------
    //  Утилиты
    // --------------------

    private static function getSystem(Coordinate $coordinate): array
    {
        $position = $coordinate;
        if ($coordinate->getType() === CoordinateType::FLEET) {
            $position = $coordinate->getLastKnownPosition();
        }

        return [
            'galaxy' => $position->getGalaxy(),
            'system' => $position->getSystem(),
        ];
    }

    private static function isSameSystem(Coordinate $start, Coordinate $target): bool
    {
        $startSystem = self::getSystem($start);
        $targetSystem = self::getSystem($target);

        return $startSystem === $targetSystem;
    }

    private static function needSystemJump(Coordinate $start, Coordinate $target): bool
    {
        return self::getSystem($start)['system'] !== self::getSystem($target)['system'];
    }

    private static function needGalaxyJump(Coordinate $start, Coordinate $target): bool
    {
        return self::getSystem($start)['galaxy'] !== self::getSystem($target)['galaxy'];
    }

    /**
     * Построить forward-путь (из start -> target).
     * Не включает return (возврат). Возвращает массив команд.
     */
    public static function buildForwardPath(Coordinate $start, Coordinate $target): array
    {
        $cmds = [];

        // Если старт и цель в одной системе
        if (self::isSameSystem($start, $target)) {
            $cmds[] = [
                'type' => FleetCommandType::MOVE_IN_SYSTEM,
                'params' => [
                    'target' => $target,
                ]
            ];
            return $cmds;
        }

        // Вылет к краю стартовой системы. Геометрия границы определяется
        // активатором команды из текущего Fleet состояния.
        $cmds[] = [
            'type' => FleetCommandType::MOVE_IN_SYSTEM,
            'params' => [
                'target' => $target,
            ]
        ];

        // 2) Межгалактический прыжок
        if (self::needGalaxyJump($start, $target)) {
            $cmds[] = [
                'type' => FleetCommandType::MOVE_BETWEEN_GALAXIES,
                'params' => [
                    'target' => $target,
                    // Nova считает galaxy/system единым диагональным переходом.
                    'destination' => self::getSystem($target),
                ]
            ];
        } elseif (self::needSystemJump($start, $target)) {
            // 3) Межсистемный переход внутри одной галактики
            $cmds[] = [
                'type' => FleetCommandType::MOVE_BETWEEN_SYSTEMS,
                'params' => [
                    'target' => $target,
                    'destination' => self::getSystem($target),
                ]
            ];
        }

        // 4) Вход и приближение к цели
        $cmds[] = [
            'type' => FleetCommandType::MOVE_IN_SYSTEM,
            'params' => [
                'target' => $target,
            ]
        ];

        return $cmds;
    }

    // -----------------------
    //  Построение миссий
    // -----------------------

    /** Отзыв не загружает ресурсы повторно и не выполняет действие у прежней цели. */
    public static function buildReturn(Coordinate $current, Coordinate $home): array
    {
        $cmds = self::buildForwardPath($current, $home);
        if (!FleetSurveyService::isResource($home)) {
            $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $home, 'unloadFuel' => true]];
        }
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $home]];
        return $cmds;
    }

    public static function buildAttack(Coordinate $start, Coordinate $target, array $options = []): array
    {
        $return = $options['return'] ?? true;
        $leaveAtTarget = $options['leave_at_target'] ?? false;
        $finalAction = $options['final_action'] ?? ($leaveAtTarget ? 'stay' : 'none');

        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];
        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        // действие на месте
        $cmds[] = ['type' => FleetCommandType::ATTACK, 'params' => ['target' => $target]];

        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));

        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];

        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildGroupAttack(Coordinate $start, Coordinate $target, array $options = []): array
    {
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];
        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        $cmds[] = ['type' => FleetCommandType::GROUP_ATTACK, 'params' => ['target' => $target]];
        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildDestroy(Coordinate $start, Coordinate $target, array $options = []): array
    {
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];
        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        $cmds[] = ['type' => FleetCommandType::DESTROY, 'params' => ['target' => $target]];
        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildTransport(Coordinate $start, Coordinate $target, array $options = []): array
    {
        $return = $options['return'] ?? true;

        $cmds = FleetSurveyService::isResource($start) ? [] : [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        // добыть/перевезти — упростим: load выполняется на старте (до вылета), unload — при прибытии
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $target, 'transportSource' => $start->toArray()]];

        if ($return) {
            $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
            // Возвращаем на исходную планету неиспользованное топливо и любой
            // груз, который не удалось выгрузить у цели.
            if (!FleetSurveyService::isResource($start)) {
                $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
            }
            $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];
        } else {
            $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $target]];
        }
        return $cmds;
    }

    public static function buildColonize(Coordinate $start, Coordinate $target, array $options = []): array
    {

        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        // COLONIZE создаёт колонию и выгружает груз только при успехе.
        // При отказе груз остаётся во флоте; оставшиеся корабли возвращаются.
        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        $cmds[] = ['type' => FleetCommandType::COLONIZE, 'params' => ['target' => $target]];

        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildRecycle(Coordinate $start, Coordinate $target, array $options = []): array
    {
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        $cmds[] = ['type' => FleetCommandType::RECYCLE, 'params' => ['target' => $target]];
        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];
        return $cmds;
    }

    public static function buildExpedition(Coordinate $start, Coordinate $target, array $options = []): array
    {
        if (!self::isSameSystem($start, $target)) {
            throw new \InvalidArgumentException('Экспедиция может быть назначена только в текущей системе флота.');
        }

        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        $cmds[] = ['type' => FleetCommandType::EXPEDITION, 'params' => ['target' => $target,'duration'=>$options['duration'] ?? 3600]];

        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];


        return $cmds;
    }

    public static function buildDefend(Coordinate $start, Coordinate $target, array $options = []): array
    {
        // Защита — часто без возврата, но пусть будет configurable
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        $cmds[] = ['type' => FleetCommandType::DEFEND, 'params' => ['target' => $target]];

        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildSpy(Coordinate $start, Coordinate $target, array $options = []): array
    {

        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        $cmds[] = ['type' => FleetCommandType::SPY, 'params' => ['target' => $target]];

        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildResearch(Coordinate $start, Coordinate $target, array $options = []): array
    {
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        $cmds[] = ['type' => FleetCommandType::RESEARCH, 'params' => ['target' => $target]];

        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildRelocate(Coordinate $start, Coordinate $target, array $options = []): array
    {
        $cmds = FleetSurveyService::isResource($start) ? [] : [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];
        // Перебазирование: летим и остаёмся
        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        // У ресурсной базы нет планетарного склада: бак и груз остаются на борту.
        if (!FleetSurveyService::isResource($target)) {
            $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $target]];
        }
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $target]];
        return $cmds;
    }

    public static function buildCapture(Coordinate $start, Coordinate $target, array $options = []): array
    {
        if (!FleetSurveyService::isResource($target)) {
            throw new \InvalidArgumentException('Занять можно только газовый гигант или сектор пояса.');
        }
        return array_merge(
            FleetSurveyService::isResource($start) ? [] : [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]],
            self::buildForwardPath($start, $target),
            [['type' => FleetCommandType::ATTACK, 'params' => ['target' => $target, 'resourceCapture' => true]],
                ['type' => FleetCommandType::CAPTURE, 'params' => ['target' => $target, 'combatCapture' => true]],
                ['type' => FleetCommandType::STAY, 'params' => ['at' => $target]]]
        );
    }

    public static function buildMining(Coordinate $start, Coordinate $target, array $options = []): array
    {
        if (!FleetSurveyService::isResource($target)) throw new \InvalidArgumentException('Для добычи выберите гигант или сектор пояса.');
        $mining = FleetMiningRuntimeService::options($options, $target->getObjectType() === \SPGame\Game\Space\ObjectType::GAS_GIANT);
        $commands = FleetSurveyService::isResource($start) ? [] : [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];
        if ($start->toArray() !== $target->toArray()) $commands = array_merge($commands, self::buildForwardPath($start, $target));
        $commands[] = ['type' => FleetCommandType::MINING, 'params' => ['target' => $target, 'miningRequest' => $mining]];
        if ($mining['finish'] === 'stay') $commands[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $target]];
        elseif ($target->toArray() === $start->toArray()) $commands[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];
        else $commands = array_merge($commands, self::buildReturn($target, $start));
        return $commands;
    }

    public static function buildMerge(Coordinate $start, Coordinate $target, array $options = []): array
    {
        $cmds = FleetSurveyService::isResource($start) ? [] : [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        $cmds[] = ['type' => FleetCommandType::MERGE, 'params' => ['target' => $target]];
        return $cmds;
    }

    public static function buildObserve(Coordinate $start, Coordinate $target, array $options = []): array
    {
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];
        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        $cmds[] = ['type' => FleetCommandType::OBSERVE, 'params' => ['target' => $target]];


        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }
}
