<?php

namespace SPGame\Game\Repositories;

use SPGame\Core\Database;
use SPGame\Core\Helpers;
use SPGame\Core\Logger;

class Vars
{

    public static array $resource;       // Содежит имена по id [1 => metal_mine]
    public static array $requirement;    // Требования [6 => [14=>20, 31=>22, 15=>4,108=>12,123=>3]]
    public static array $pricelist;      // Стоимость производства по id [1 => [901=>60, 902=>15, 903=>0, 911=>0, 921=>0, 922=>0]]
    public static array $attributes;     // Артибуты по id factor,max,type,consumption,consumption2,speed,speed2,capacity,tech,time

    public static array $production;     // Производительность по id 1 => [901=>'30 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', 902=>0, 903=>0, 911=>0, 921=>0, 922=>0]]
    public static array $storage;        // Вместимость хранилища id [1 => [901=>'floor(2.5 * pow(1.8331954764, $BuildLevel)) * 5000', 902=>0, 903=>0, 911=>0, 921=>0, 922=>0]]

    public static array $CombatCaps;     // Боевые характеристики attack, shield
    public static array $rapidfire;      // Скорострельность [202 => [210 = > 5, 212 => 5]]

    public static array $bonus;

    public static array $reslist;

    public static array $bonusList = [
        'Attack',
        'Defensive',
        'Shield',
        'BuildTime',
        'ResearchTime',
        'ShipTime',
        'DefensiveTime',
        'Resource',
        'Energy',
        'ResourceStorage',
        'ShipStorage',
        'FlyTime',
        'FleetSlots',
        'Planets',
        'SpyPower',
        'Expedition',
        'GateCoolTime',
        'MoreFound',
    ];

    /** @var Logger */
    protected static Logger $logger;

    public static function init(): void
    {
        $start = microtime(true);
        $before_memory = memory_get_usage();


        self::$logger = Logger::getInstance();

        self::$resource = [];
        self::$requirement = [];
        self::$pricelist = [];
        self::$attributes = [];

        self::$reslist = [
            'prod' => [],                   // Производства
            'storage' => [],                // Хранения
            'bonus' => [],
            'one' => [],                    // Одна на все планеты
            'build' => [],                  // Постройки
            'allow' => [1 => [], 3 => []],  // Разрешено толоко на 1 - Планете, 3 - Спутнике
            'tech' => [],                   // Исследования
            'fleet' => [],                  // Корабли
            'defense' => [],                // Оборона
            'missile' => [],                // Ракеты
            'officier' => [],               // Офицеры
            'dmfunc' => [],                 // ХЗ
            'types' => [
                0   => 'build',
                100 => 'tech',
                200 => 'fleet',
                400 => 'defense',
                500 => 'missile',
                600 => 'officier',
                700 => 'dmfunc',
            ],
            'nametype' => [
                'build' => [
                    1 => 'resources',  // ключ
                    2 => 'factories',
                    3 => 'others'
                ],
                'reseach' => [
                    1 => 'basic',
                    2 => 'advanced',
                    3 => 'combat',
                    4 => 'engines'
                ],
                'fleet' => [
                    1 => 'civil',
                    2 => 'military'
                ],
                'fleet' => [
                    1 => 'civil',
                    2 => 'military'
                ],
                'defense' => [
                    1 => 'installations',
                    2 => 'shields',
                    3 => 'missiles'
                ],
            ],
            'ressources' => [901, 902, 903, 911, 921, 922],
            'resstype' => [
                1 => [901, 902, 903],   // Планетарные ресурсы
                2 => [911],             // Не накапливаемые ресурсы
                3 => [921, 922]         // Пользовательские ресурсы
            ]
        ];

        // Базовые ресурсы
        self::$resource[901] = 'metal';
        self::$resource[902] = 'crystal';
        self::$resource[903] = 'deuterium';
        self::$resource[911] = 'energy';
        self::$resource[921] = 'credit';
        self::$resource[922] = 'doubloon';

        self::$reslist['ressources'] = [901, 902, 903, 911, 921, 922];
        self::$reslist['resstype'][1] = [901, 902, 903];                // 
        self::$reslist['resstype'][2] = [911];
        self::$reslist['resstype'][3] = [921, 922];
        // Загружаем данные из БД
        $count = self::loadAll();

        $duration = round(microtime(true) - $start, 3);
        $use_memory = memory_get_usage() - $before_memory;
        self::$logger->info("Loaded {$count} vars in {$duration}s use_memory " . Helpers::formatNumberShort($use_memory, 2));
    }

    public static function loadAll(): int
    {
        $db = Database::getInstance();

        foreach ($row_requirement = $db->fetchAll("SELECT * FROM `vars_requirements`") as $reqRow) {
            self::$requirement[$reqRow['elementID']][$reqRow['requireID']] = $reqRow['requireLevel'];
        }

        foreach ($row_vars = $db->fetchAll("SELECT * FROM `vars`") as $varsRow) {
            $id = (int)$varsRow['elementID'];

            self::$resource[$id] = $varsRow['name'];
            self::$pricelist[$id] = [
                901 => $varsRow['cost901'],
                902 => $varsRow['cost902'],
                903 => $varsRow['cost903'],
                911 => $varsRow['cost911'],
                921 => $varsRow['cost921'],
            ];

            self::$attributes[$id] = [
                'factor'      => $varsRow['factor'],
                'max'         => $varsRow['maxLevel'],
                'type'        => $varsRow['type'],
                'consumption' => $varsRow['consumption1'],
                'consumption2' => $varsRow['consumption2'],
                'speed'       => $varsRow['speed1'],
                'speed2'      => $varsRow['speed2'],
                'capacity'    => $varsRow['capacity'],
                'tech'        => $varsRow['speedTech'],
                'time'        => $varsRow['timeBonus'],
            ];

            $bonusData = [];
            foreach (self::$bonusList as $bonusName) {
                $value = $varsRow['bonus' . $bonusName] ?? null;
                $unit  = $varsRow['bonus' . $bonusName . 'Unit'] ?? null;


                if ($value !== null || $unit !== null) {
                    $bonusData[$bonusName] = [$value, $unit];
                }
            }

            if (!empty($bonusData)) {
                self::$bonus[$id]   = $bonusData;
                self::$reslist['bonus'][] = $varsRow['elementID'];
            }

            if (
                $varsRow['production901'] ||
                $varsRow['production902'] ||
                $varsRow['production903'] ||
                $varsRow['production911']
            )
                self::$production[$id] = [
                    901 => $varsRow['production901'],
                    902 => $varsRow['production902'],
                    903 => $varsRow['production903'],
                    911 => $varsRow['production911'],
                ];
            if (
                $varsRow['storage901'] ||
                $varsRow['storage902'] ||
                $varsRow['storage903']
            )
                self::$storage[$id] = [
                    901 => $varsRow['storage901'],
                    902 => $varsRow['storage902'],
                    903 => $varsRow['storage903'],
                ];

            self::$CombatCaps[$id] = [
                'attack' => $varsRow['attack'],
                'shield' => $varsRow['defend'],
            ];

            if (!empty(self::$production[$id]) && array_filter(self::$production[$id])) {
                self::$reslist['prod'][] = $id;
            }
            if (!empty(self::$storage[$id]) && array_filter(self::$storage[$id])) {
                self::$reslist['storage'][] = $id;
            }

            if ($varsRow['onePerPlanet'] == 1) self::$reslist['one'][] = $id;

            switch ($varsRow['class']) {
                case 0:
                    self::$reslist['build'][] = $id;
                    self::$reslist['types']['build'][$varsRow['type']][] = $varsRow['elementID'];
                    foreach (explode(',', $varsRow['onPlanetType']) as $type) {
                        self::$reslist['allow'][$type][] = $id;
                    }
                    break;
                case 100:
                    self::$reslist['tech'][] = $id;
                    self::$reslist['types']['tech'][$varsRow['type']][] = $varsRow['elementID'];
                    break;
                case 200:
                    self::$reslist['fleet'][] = $id;
                    self::$reslist['types']['fleet'][$varsRow['type']][] = $varsRow['elementID'];
                    break;
                case 400:
                    self::$reslist['defense'][] = $id;
                    self::$reslist['types']['defense'][$varsRow['type']][] = $varsRow['elementID'];
                    break;
                case 500:
                    self::$reslist['missile'][] = $id;
                    self::$reslist['types']['missile'][$varsRow['type']][] = $varsRow['elementID'];
                    break;
                case 600:
                    self::$reslist['officier'][] = $id;
                    self::$reslist['types']['officier'][$varsRow['type']][] = $varsRow['elementID'];
                    break;
                case 700:
                    self::$reslist['dmfunc'][] = $id;
                    break;
            }
        }

        foreach ($row_rapidfire = $db->fetchAll("SELECT * FROM `vars_rapidfire`") as $row) {
            self::$rapidfire[$row['elementID']][$row['rapidfireID']] = $row['shoots'];
        }

        return (count($row_requirement) + count($row_vars) + count($row_rapidfire));
    }
}
