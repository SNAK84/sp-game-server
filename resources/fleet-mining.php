<?php

declare(strict_types=1);

// Стартовый баланс согласован24.10.2026. Подключается обработчиком этапа5;
// сам файл не добавляет корабли и не изменяет работающие Vars/репозитории.
return [
    'technology' => 136,
    'yield_bonus_per_level' => 0.10,
    // Режимы, длительности и завершение согласованы25.10.2026.
    'belt_modes' => ['metal', 'crystal', 'mix'],
    'durations' => [3600, 7200, 14400, 28800, 43200, 57600, 115200],
    'default_duration' => 3600,
    'finish_options' => ['return', 'stay'],
    'base_limits' => ['minimum_level' => 1, 'base' => 1, 'level_step' => 2],
    'based_fleets' => ['technology' => 134, 'base' => 2],
    'friendly_defenders' => ['technology' => 134, 'base' => 0],
    'ships' => [
        223 => [
            'vars' => [
                'elementID' => 223, 'name' => 'asteroid_miner', 'class' => 200, 'type' => 1,
                'onPlanetType' => '1,3', 'onePerPlanet' => 0, 'factor' => 1,
                'cost901' => 40000, 'cost902' => 20000, 'cost903' => 10000,
                'capacity' => 20000, 'consumption1' => 100, 'consumption2' => 100,
                'speedTech' => 2, 'speed1' => 5000, 'speed2' => 5000, 'attack' => 1, 'defend' => 25,
                'fleetMissions' => '["transport","relocate","observe","merge","expedition","mining"]',
            ],
            'requirements' => [21 => 6, 117 => 4, 136 => 1],
            'target_type' => 'asteroid_sector',
            'rate_per_hour' => 1500,
        ],
        224 => [
            'vars' => [
                'elementID' => 224, 'name' => 'gas_extractor', 'class' => 200, 'type' => 1,
                'onPlanetType' => '1,3', 'onePerPlanet' => 0, 'factor' => 1,
                'cost901' => 30000, 'cost902' => 40000, 'cost903' => 20000,
                'capacity' => 20000, 'consumption1' => 100, 'consumption2' => 100,
                'speedTech' => 2, 'speed1' => 5000, 'speed2' => 5000, 'attack' => 1, 'defend' => 25,
                'fleetMissions' => '["transport","relocate","observe","merge","expedition","mining"]',
            ],
            'requirements' => [21 => 6, 117 => 4, 136 => 3],
            'target_type' => 'gas_giant',
            'rate_per_hour' => 1000,
        ],
    ],
];
