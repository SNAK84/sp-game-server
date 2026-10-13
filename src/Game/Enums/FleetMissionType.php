<?php

namespace SPGame\Game\Enums;

/**
 * Типы миссий флота (высокоуровневые сценарии)
 * Игрок выбирает одну из них при отправке флота.
 */
enum FleetMissionType: int
{
    /*

'Атаковать'
'Групповая атака'
'Оборонять' 	(Союзников, пояса астеройдов, газовые гиганты. В охранении постоянный расход топлива.)
'Уничтожить'	(Уничтожение луны, только спец кораблями типа звезда смерти)


'Транспорт'				(На любую планету или луну или на свой флот в ожидании для пополнения топлива/дейтерия)
'Передислокация'		(только на свою планету или луну)
'Переработать'			(Переработка обломков, захваченного пояса астеройдов или газового гиганта)
'Колонизировать'		(Свободную планету)
"Наблюдение без боя"	(Остаться на свободных координатах, в ожидании постоянный расход топлива.)
"Объединение"			(Объединение своих двух флотов в 1.)

'Шпионить'

"Исследование"		(Исследовать не известную планету, пояс астеройдов, газовый гигант)

'Экспедиция'			(Исследование неизвестного космаса, Полет за пределы системы)




    case ATTACK = 1;        // Атака цели
    case TRANSPORT = 2;     // Перевозка ресурсов
    case COLONIZE = 3;      // Колонизация
    case HARVEST = 4;       // Сбор обломков
    case EXPEDITION = 5;    // Экспедиция
    case DEFEND = 6;        // Защита союзника
    case SPY = 7;           // Шпионаж
    case RETURN = 8;        // Возврат домой
    case RELOCATE = 9;      // Перебазирование (смена домашней планеты)
    case MERGE = 10;        // Объединение флотов
    case SCOUT = 11;        // Разведка/наблюдение без боя


*/

    case ATTACK         = 1;    // Атаковать
    case GROUP_ATTACK    = 3;    // Групповая атака
    case DEFEND            = 5;    // Оборонять

    case DESTROY        = 7;    // Уничтожить       *

    case TRANSPORT        = 10;    // Транспорт
    case RELOCATE        = 11;    // Передислокация
    case RECYCLE        = 12;    // Переработать     *
    case COLONIZE        = 15;    // Колонизировать   *

    case SPY            = 20;    // Шпионить         *
    case RESEARCH        = 25;    // Исследование     *

    case OBSERVE        = 30;    // Наблюдение без боя
    case MERGE            = 35;    // Объединение

    case EXPEDITION        = 40;    // Экспедиция

    public function getLangKey(): string
    {
        return match ($this) {
            self::ATTACK       => 'fleet_mission.attack',
            self::GROUP_ATTACK => 'fleet_mission.group_attack',
            self::DEFEND       => 'fleet_mission.defend',
            self::DESTROY      => 'fleet_mission.destroy',
            self::SPY          => 'fleet_mission.spy',
            self::RESEARCH     => 'fleet_mission.research',
            self::TRANSPORT    => 'fleet_mission.transport',
            self::RELOCATE     => 'fleet_mission.relocate',
            self::RECYCLE      => 'fleet_mission.recycle',
            self::COLONIZE     => 'fleet_mission.colonize',
            self::OBSERVE      => 'fleet_mission.observe',
            self::MERGE        => 'fleet_mission.merge',
            self::EXPEDITION   => 'fleet_mission.expedition',
        };
    }

    public function key(): string
    {
        return match ($this) {
            self::ATTACK       => 'attack',
            self::GROUP_ATTACK => 'group_attack',
            self::DEFEND       => 'defend',
            self::DESTROY      => 'destroy',
            self::TRANSPORT    => 'transport',
            self::RELOCATE     => 'relocate',
            self::RECYCLE      => 'recycle',
            self::COLONIZE     => 'colonize',
            self::SPY          => 'spy',
            self::RESEARCH     => 'research',
            self::OBSERVE      => 'observe',
            self::MERGE        => 'merge',
            self::EXPEDITION   => 'expedition',
        };
    }

    public static function fromKey(string $key): ?self
    {
        foreach (self::cases() as $mission) {
            if ($mission->key() === $key) {
                return $mission;
            }
        }

        return null;
    }

    /**
     * Типы объектов, на которые может быть направлена миссия.
     *
     * object: planet/moon/asteroid_belt/gas_giant/debris/point.
     * fleet: собственный флот.
     */
    public function targetTypes(): array
    {
        return match ($this) {
            self::ATTACK       => ['planet', 'moon', 'fleet'],
            self::GROUP_ATTACK => ['planet', 'moon', 'fleet'],
            self::DEFEND       => ['planet', 'moon', 'asteroid_belt', 'gas_giant'],
            self::DESTROY      => ['moon'],
            self::SPY          => ['planet', 'moon'],
            self::RESEARCH     => ['planet', 'asteroid_belt', 'gas_giant'],
            self::TRANSPORT    => ['planet', 'moon', 'fleet'],
            self::RELOCATE     => ['planet', 'moon'],
            self::RECYCLE      => ['debris', 'asteroid_belt', 'gas_giant'],
            self::COLONIZE     => ['planet'],
            self::OBSERVE      => ['point'],
            self::MERGE        => ['fleet'],
            self::EXPEDITION   => ['orbit'],
        };
    }

    /**
     * Миссия остаётся в целевой точке после выполнения действия.
     */
    public function staysAtTarget(): bool
    {
        return match ($this) {
            self::DEFEND,
            self::RELOCATE,
            self::COLONIZE,
            self::OBSERVE,
            self::MERGE => true,
            default => false,
        };
    }

    /**
     * Постоянный расход топлива после прибытия в точку удержания.
     */
    public function hasContinuousFuelConsumption(): bool
    {
        return match ($this) {
            self::DEFEND,
            self::OBSERVE => true,
            default => false,
        };
    }

    public static function list(): array
    {
        $result = [];

        foreach (self::cases() as $case) {
            $result[] = [
                'id' => $case->value,
                'key' => $case->name,
                'lang' => $case->getLangKey(),
                'target_types' => $case->targetTypes(),
                'stays_at_target' => $case->staysAtTarget(),
                'continuous_fuel_consumption' => $case->hasContinuousFuelConsumption(),
            ];
        }

        return $result;
    }

    public function getActionCommand(): FleetCommandType
    {
        return match ($this) {
            self::ATTACK       => FleetCommandType::ATTACK,
            self::GROUP_ATTACK => FleetCommandType::GROUP_ATTACK,
            self::DEFEND       => FleetCommandType::DEFEND,
            self::DESTROY      => FleetCommandType::DESTROY,
            self::SPY          => FleetCommandType::SPY,
            self::RESEARCH     => FleetCommandType::RESEARCH,
            self::TRANSPORT    => FleetCommandType::TRANSPORT,
            self::RELOCATE     => FleetCommandType::STAY,
            self::RECYCLE      => FleetCommandType::RECYCLE,
            self::COLONIZE     => FleetCommandType::COLONIZE,
            self::OBSERVE      => FleetCommandType::OBSERVE,
            self::MERGE        => FleetCommandType::MERGE,
            self::EXPEDITION   => FleetCommandType::EXPEDITION,
        };
    }

    /**
     * Возвращает список миссий с фильтрацией по переданным ID
     */
    public static function listForFleet(array $missionIds): array
    {
        $allMissions = self::list();

        // Фильтруем только указанные ID
        return array_filter($allMissions, fn($m) => in_array($m['id'], $missionIds));
    }
}
