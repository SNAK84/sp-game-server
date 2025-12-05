<?php
namespace SPGame\Game\Enums;

/**
 * Типы миссий флота (высокоуровневые сценарии)
 * Игрок выбирает одну из них при отправке флота.
 */
enum FleetMissionType: int
{
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

    public function getLangKey(): string
    {
        return match ($this) {
            self::ATTACK        => 'fleet_mission.attack',
            self::TRANSPORT     => 'fleet_mission.transport',
            self::COLONIZE      => 'fleet_mission.colonize',
            self::HARVEST       => 'fleet_mission.harvest',
            self::EXPEDITION    => 'fleet_mission.expedition',
            self::DEFEND        => 'fleet_mission.defend',
            self::SPY           => 'fleet_mission.spy',
            self::RETURN        => 'fleet_mission.return',
            self::RELOCATE      => 'fleet_mission.relocate',
            self::MERGE         => 'fleet_mission.merge',
            self::SCOUT         => 'fleet_mission.scout',
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
            ];
        }
        return $result;
    }
}
