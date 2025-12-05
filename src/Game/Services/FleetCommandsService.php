<?php

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Repositories\FleetCommands;
use SPGame\Game\Enums\FleetCommandType;

class FleetCommandsService
{
    /**
     * Получить все команды для флота
     */
    public static function getFleetCommands(int $fleetId): array
    {
        $queue = FleetCommands::getFleetQueue($fleetId);
        return array_values(array_map(fn($cmd) => [
            'id' => $cmd['id'],
            'type' => $cmd['command_type'],
            'key' => FleetCommandType::from($cmd['command_type'])->getLangKey(),
            'params' => json_decode($cmd['params'], true),
            'execute_time' => $cmd['execute_time'],
            'trigger_event' => $cmd['trigger_event'],
            'executed' => (bool)$cmd['executed'],
        ], $queue));
    }

    /**
     * Установить цепочку команд (присланную клиентом)
     * Пример payload:
     * [
     *   { "type": 10, "params": {"phase":"exit_system"} },
     *   { "type": 20, "params": {"target":{"galaxy":2,"system":10}} }
     * ]
     */
    public static function setFleetCommands(int $fleetId, array $commands, float $startTime): array
    {
        // Сначала очистим очередь
        FleetCommands::clearFleetQueue($fleetId);

        $time = $startTime;
        foreach ($commands as $cmd) {
            $type = FleetCommandType::from($cmd['type']);
            $params = $cmd['params'] ?? [];
            $executeTime = $time;

            FleetCommands::addCommand(
                $fleetId,
                $type,
                $params,
                $executeTime
            );

            // Позже сюда можно добавить расчёт длительности
            $time += 1.0;
        }

        Logger::getInstance()->debug("Fleet #$fleetId commands replaced", [
            'count' => count($commands)
        ]);

        return self::getFleetCommands($fleetId);
    }

    /**
     * Удалить все команды флота
     */
    public static function clearFleet(int $fleetId): void
    {
        FleetCommands::clearFleetQueue($fleetId);
    }
}
