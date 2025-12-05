<?php

namespace SPGame\Game\Enums;

/**
 * Типы команд флота (аналог миссий OGame / 2Moons)
 * 
 * Используются в FleetCommands для задания действий флота.
 */

enum FleetCommandType: int
{

    // ====== MOVEMENT ======
    case MOVE_IN_SYSTEM = 10;         // Полёт внутри одной системы
    case MOVE_BETWEEN_SYSTEMS = 11;   // Перелёт между системами
    case MOVE_BETWEEN_GALAXIES = 12;  // Перелёт между галактиками

        // ====== COMBAT ======
    case ATTACK = 20;       // Атака цели (планеты, флота, станции)
    case GROUP_ATTACK = 21; // Совместная атака (ACS Attack)
    case DEFEND = 22;       // Защита союзной планеты
    case SPY = 23;          // Шпионаж (отправка зондов)
    case DESTROY = 24;      // Уничтожение луны или орбитальной станции
    case INTERCEPT = 25;    // Перехват флота в полёте (динамический бой)

        // ====== LOGISTICS ======
    case TRANSPORT = 30;    // Перевозка ресурсов
    case LOAD = 31;         // Погрузка ресурсов и топлива
    case UNLOAD = 32;       // Выгрузка ресурсов и топлива
    case HARVEST = 33;      // Сбор обломков (переработчики)
    case COLONIZE = 34;     // Колонизация новой планеты
    case TRANSFER = 35;     // Передача ресурсов между флотами (в космосе или при стыковке)

        // ====== SPECIAL ======
    case EXPEDITION = 40;   // Экспедиция (исследовательская миссия)
    case SCOUT = 41;        // Разведка области без боя
    case PATROL = 42;       // Патрулирование орбиты / системы с циклическим маршрутом
    case OBSERVE = 43;      // Наблюдение за системой (сенсоры, спутники)

        // ====== CONTROL ======
    case WAIT = 50;         // Ожидание / пауза
    case LAND = 51;         // Приземление / расформирование флота
    case STAY = 52;         // Сменить домашнюю планету
    case MERGE = 53;        // Объединить два флота
    case SPLIT = 54;        // Разделить флот
    case CANCEL = 55;       // Отмена текущей миссии (возврат домой)

    /**
     * Возвращает ключ для файла локализации (lang.json)
     * Пример: "fleet_command.attack"
     */
    public function getLangKey(): string
    {
        return match ($this) {
            self::MOVE_IN_SYSTEM        => 'fleet_command.move_in_system',
            self::MOVE_BETWEEN_SYSTEMS  => 'fleet_command.move_between_systems',
            self::MOVE_BETWEEN_GALAXIES => 'fleet_command.move_between_galaxies',

            self::ATTACK        => 'fleet_command.attack',
            self::GROUP_ATTACK  => 'fleet_command.group_attack',
            self::DEFEND        => 'fleet_command.defend',
            self::SPY           => 'fleet_command.spy',
            self::DESTROY       => 'fleet_command.destroy',
            self::INTERCEPT     => 'fleet_command.intercept',

            self::TRANSPORT     => 'fleet_command.transport',
            self::LOAD          => 'fleet_command.load',
            self::UNLOAD        => 'fleet_command.unload',
            self::HARVEST       => 'fleet_command.harvest',
            self::COLONIZE      => 'fleet_command.colonize',
            self::TRANSFER      => 'fleet_command.transfer',

            self::EXPEDITION    => 'fleet_command.expedition',
            self::SCOUT         => 'fleet_command.scout',
            self::PATROL        => 'fleet_command.patrol',
            self::OBSERVE       => 'fleet_command.observe',

            self::WAIT          => 'fleet_command.wait',
            self::LAND          => 'fleet_command.land',
            self::STAY          => 'fleet_command.stay',
            self::MERGE         => 'fleet_command.merge',
            self::SPLIT         => 'fleet_command.split',
            self::CANCEL        => 'fleet_command.cancel',
        };
    }

    /**
     * Возвращает ассоциативный массив всех команд для клиента
     */
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
