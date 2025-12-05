<?php

namespace SPGame\Game\Services;

use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Enums\FleetMissionType;

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
     * @param array $start ['galaxy'=>, 'system'=>, 'orbit'=>, 'distance'?, 'deg'?]
     * @param array $target аналогично
     * @param array $options ['return' => bool, 'leave_at_target' => bool, 'final_action' => 'stay'|'land'|'none', ...]
     * @return array список команд вида ['type' => FleetCommandType::..., 'params' => [...]]
     */
    public static function build(FleetMissionType $mission, array $start, array $target, array $options = []): array
    {
        return match ($mission) {
            FleetMissionType::ATTACK => self::buildAttack($start, $target, $options),
            FleetMissionType::TRANSPORT => self::buildTransport($start, $target, $options),
            FleetMissionType::COLONIZE => self::buildColonize($start, $target, $options),
            FleetMissionType::HARVEST => self::buildHarvest($start, $target, $options),
            FleetMissionType::EXPEDITION => self::buildExpedition($start, $target, $options),
            FleetMissionType::DEFEND => self::buildDefend($start, $target, $options),
            FleetMissionType::SPY => self::buildSpy($start, $target, $options),
            FleetMissionType::RETURN => self::buildReturn($start, $options),
            FleetMissionType::RELOCATE => self::buildRelocate($start, $target, $options),
            FleetMissionType::MERGE => self::buildMerge($start, $target, $options),
            FleetMissionType::SCOUT => self::buildScout($start, $target, $options),
            default => [],
        };
    }

    // --------------------
    //  Утилиты
    // --------------------

    private static function isSameSystem(array $a, array $b): bool
    {
        return ($a['galaxy'] === $b['galaxy']) && ($a['system'] === $b['system']);
    }

    private static function needSystemJump(array $start, array $target): bool
    {
        return $start['system'] !== $target['system'];
    }

    private static function needGalaxyJump(array $start, array $target): bool
    {
        return $start['galaxy'] !== $target['galaxy'];
    }

    /**
     * Построить forward-путь (из start -> target).
     * Не включает return (возврат). Возвращает массив команд.
     */
    private static function buildForwardPath(array $start, array $target): array
    {
        $cmds = [];

        // Если старт и цель в одной системе
        if (self::isSameSystem($start, $target)) {
            $cmds[] = [
                'type' => FleetCommandType::MOVE_IN_SYSTEM,
                'params' => [
                    'phase' => 'move_to_target',
                    'target' => $target,
                ]
            ];
            return $cmds;
        }

        // 1) Вылет к краю стартовой системы
        $cmds[] = [
            'type' => FleetCommandType::MOVE_IN_SYSTEM,
            'params' => [
                'phase' => 'exit_system',
                'to' => [
                    'galaxy' => $target['galaxy'],
                    'system' => $target['system'],
                ]
            ]
        ];

        // 2) Межгалактический прыжок
        if (self::needGalaxyJump($start, $target)) {
            $cmds[] = [
                'type' => FleetCommandType::MOVE_BETWEEN_GALAXIES,
                'params' => [
                    'from' => $start['galaxy'],
                    'to' => $target['galaxy'],
                ]
            ];
        }

        // 3) Межсистемный переход
        if (self::needSystemJump($start, $target)) {
            $cmds[] = [
                'type' => FleetCommandType::MOVE_BETWEEN_SYSTEMS,
                'params' => [
                    'from' => $start['system'],
                    'to' => $target['system'],
                    'galaxy' => $target['galaxy']
                ]
            ];
        }

        // 4) Вход и приближение к цели
        $cmds[] = [
            'type' => FleetCommandType::MOVE_IN_SYSTEM,
            'params' => [
                'phase' => 'approach_target',
                'target' => $target,
            ]
        ];

        return $cmds;
    }

    // -----------------------
    //  Построение миссий
    // -----------------------

    public static function buildAttack(array $start, array $target, array $options = []): array
    {
        $return = $options['return'] ?? true;
        $leaveAtTarget = $options['leave_at_target'] ?? false;
        $finalAction = $options['final_action'] ?? ($leaveAtTarget ? 'stay' : 'none');

        $cmds = [];

        // forward
        $cmds = self::buildForwardPath($start, $target);

        // действие на месте
        $cmds[] = ['type' => FleetCommandType::ATTACK, 'params' => ['target' => $target]];

        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));

        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];

        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildTransport(array $start, array $target, array $options = []): array
    {
        $return = $options['return'] ?? true;

        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        // добыть/перевезти — упростим: load выполняется на старте (до вылета), unload — при прибытии
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $target]];

        if ($return) {
            $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
            $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];
        } else {
            $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $target]];
        }
        return $cmds;
    }

    public static function buildColonize(array $start, array $target, array $options = []): array
    {

        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        // колонизация: летим туда и выполняем COLONIZE, обычно без возврата
        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        $cmds[] = ['type' => FleetCommandType::COLONIZE, 'params' => ['target' => $target]];

        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $target]];


        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildHarvest(array $start, array $target, array $options = []): array
    {
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        $cmds[] = ['type' => FleetCommandType::HARVEST, 'params' => ['target' => $target]];
        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];
        return $cmds;
    }

    public static function buildExpedition(array $start, array $target, array $options = []): array
    {

        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        $cmds[] = ['type' => FleetCommandType::EXPEDITION, 'params' => ['target' => $target]];

        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];


        return $cmds;
    }

    public static function buildDefend(array $start, array $target, array $options = []): array
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

    public static function buildSpy(array $start, array $target, array $options = []): array
    {

        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        $cmds[] = ['type' => FleetCommandType::SPY, 'params' => ['target' => $target]];

        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }

    public static function buildReturn(array $start, array $options = []): array
    {
        // Простая команда "вернись домой" — разворачиваем в реальные движения:
        // Если флот в пределах системы — просто move_to_home, иначе составной маршрут.
        $fakeTarget = $options['current_position'] ?? $start;
        // buildReturnPath expects start(original_home), target(current_position)
        return self::buildForwardPath($fakeTarget, $start);
    }

    public static function buildRelocate(array $start, array $target, array $options = []): array
    {
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];
        // Перебазирование: летим и остаёмся
        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $target]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $target]];
        return $cmds;
    }

    public static function buildMerge(array $start, array $target, array $options = []): array
    {
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];

        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));
        $cmds[] = ['type' => FleetCommandType::MERGE, 'params' => ['with' => $target]];
        return $cmds;
    }

    public static function buildScout(array $start, array $target, array $options = []): array
    {
        $cmds = [['type' => FleetCommandType::LOAD, 'params' => ['target' => $start]]];
        $cmds = array_merge($cmds, self::buildForwardPath($start, $target));

        $cmds[] = ['type' => FleetCommandType::SCOUT, 'params' => ['target' => $target]];


        $cmds = array_merge($cmds, self::buildForwardPath($target, $start));
        $cmds[] = ['type' => FleetCommandType::UNLOAD, 'params' => ['target' => $start]];
        $cmds[] = ['type' => FleetCommandType::STAY, 'params' => ['at' => $start]];

        return $cmds;
    }
}
