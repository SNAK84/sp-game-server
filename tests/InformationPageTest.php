<?php

declare(strict_types=1);

require __DIR__ . '/FleetTransportTest.php';

use SPGame\Core\Message;
use SPGame\Game\Pages\InformationPage;
use SPGame\Game\Repositories\{Builds, Config, EntitySettings, Queues, Resources, Vars};
use SPGame\Game\Services\{BuildFunctions, FleetColonizationService, FleetControlService, FleetExpeditionService, FleetFunctions, FleetsServices};

function informationFixture(): TransportAccountData
{
    $fixture = installTransportFixture();
    $account = $fixture['account'];
    Vars::$resource += [1 => 'metal_mine', 2 => 'crystal_mine', 3 => 'deuterium_synthesizer', 4 => 'solar_plant', 6 => 'techno', 12 => 'fusion_plant', 34 => 'ally_deposit',
        14 => 'robot_factory', 15 => 'nano_factory', 21 => 'hangar', 22 => 'metal_store', 31 => 'lab',
        113 => 'energy_tech', 115 => 'combustion_tech', 117 => 'impulse_motor_tech', 118 => 'hyperspace_motor_tech', 123 => 'network', 124 => 'astrophysics',
        131 => 'metal_tech', 132 => 'crystal_tech', 133 => 'deuterium_tech',
        212 => 'satellite', 401 => 'launcher'];
    Vars::$reslist += ['build' => [1, 2, 3, 4, 6, 12, 14, 15, 21, 22, 31, 34], 'tech' => [113, 115, 117, 118, 123, 124, 131, 132, 133],
        'defense' => [401], 'missile' => [], 'bonus' => [113], 'prod' => [1, 2, 3, 4, 12, 212], 'storage' => [22],
        'ressources' => [901, 902, 903, 911], 'allow' => [1 => [1, 3, 4, 6, 14, 15, 21, 22, 31]]];
    Vars::$reslist['resstype'][2] = [911];
    Vars::$reslist['fleet'][] = 212;
    foreach (array_merge(Vars::$reslist['build'], Vars::$reslist['tech'], [212, 401]) as $id) {
        Vars::$attributes[$id] = ['factor' => 1.5, 'max' => 100];
        Vars::$pricelist[$id] = [901 => 60, 902 => 15];
    }
    Vars::$attributes[202] = ['factor' => 1, 'max' => 0, 'tech' => 4, 'speed' => 5000,
        'consumption' => 10, 'consumption2' => 20, 'capacity' => 5000];
    Vars::$attributes[212] += ['tech' => 0, 'speed' => 0, 'consumption' => 0, 'consumption2' => 0, 'capacity' => 0];
    Vars::$pricelist[202] = [901 => 2000, 902 => 2000];
    Vars::$CombatCaps = [202 => ['attack' => 5, 'shield' => 10], 401 => ['attack' => 80, 'shield' => 20]];
    Vars::$rapidfire = [202 => [212 => 5], 401 => [202 => 2]];
    Vars::$requirement = [1 => [14 => 1], 202 => [115 => 1], 115 => [31 => 5], 117 => [115 => 5]];
    Vars::$production = [1 => [901 => '30 * $BuildLevel * pow(1.1, $BuildLevel) * ($BuildLevelFactor / 100)',
        902 => '0', 911 => '-10 * $BuildLevel'], 2 => [902 => '20 * $BuildLevel * pow(1.1, $BuildLevel)', 911 => '-15 * $BuildLevel'],
        3 => [903 => '$BuildLevel * (1.36 - 0.004 * $BuildTemp)', 911 => '-20 * $BuildLevel'],
        4 => [911 => '20 * $BuildLevel * pow(1.1, $BuildLevel) * ($BuildLevelFactor / 100)'],
        12 => [911 => '30 * $BuildLevel * pow(1.05 + __TECH__113__ * 0.01, $BuildLevel) * ($BuildLevelFactor / 100)', 903 => '-10 * $BuildLevel'],
        212 => [911 => 'floor(($BuildTemp + 140) / 6)']];
    Vars::$storage = [22 => [901 => '5000 * pow(2, $BuildLevel)']];
    Vars::$bonus = [113 => array_fill_keys(Vars::$bonusList, [0, 0])];
    Vars::$bonus[113]['Energy'] = [0.1, 0];

    $account['Builds'] = array_fill_keys(array_map(fn($id) => Vars::$resource[$id], Vars::$reslist['build']), 0);
    $account['Builds']['metal_mine'] = 2;
    $account['Builds']['crystal_mine'] = 2;
    $account['Builds']['deuterium_synthesizer'] = 4;
    $account['Builds']['solar_plant'] = 5;
    $account['Builds']['fusion_plant'] = 2;
    $account['Builds']['robot_factory'] = 1;
    $account['Builds']['hangar'] = 2;
    $account['Builds']['lab'] = 3;
    $account['Techs'] = ['combustion_tech' => 2, 'impulse_motor_tech' => 5, 'hyperspace_motor_tech' => 0, 'energy_tech' => 3, 'network' => 0,
        'metal_tech' => 2, 'crystal_tech' => 1, 'deuterium_tech' => 3];
    $account['Ships'] = ['small_cargo' => 10, 'satellite' => 1];
    $account['Defenses'] = ['launcher' => 0];
    $account['All_Builds'] = new class { public function toArray(): array { return []; } };
    installTransportRepository(Builds::class, transportTable([['id' => 10, 'owner_id' => 100, 'lab' => 3]]), transportSyncTable(), []);
    installTransportRepository(Queues::class, transportTable(), transportSyncTable(), []);
    installTransportRepository(EntitySettings::class, transportTable(), transportSyncTable(), []);
    $rows = [];
    foreach (['GameSpeed' => 2500, 'MinBuildTime' => 1, 'FactorUniversity' => 0,
        'EnergySpeed' => 3, 'ResourceMultiplier' => 10, 'StorageMultiplier' => 10,
        'metalBasicIncome' => 30, 'crystalBasicIncome' => 15, 'deuteriumBasicIncome' => 0] as $name => $value) {
        $rows[] = ['id' => count($rows) + 1, 'name' => $name, 'value' => (string)$value];
    }
    installTransportRepository(Config::class, transportTable($rows), transportSyncTable(count($rows)), [
        'name' => uniqueTransportIndex(array_column($rows, 'id', 'name')),
    ]);
    return $account;
}

function informationSnapshot(mixed $id, TransportAccountData $account): array
{
    return (new InformationPage((new Message())->setData('id', $id)))->render($account);
}

$account = informationFixture();
$before = [$account['Builds'], $account['Techs'], $account['Resources'], $account['Ships']];
$info = informationSnapshot(1, $account);
transportAssert($info['kind'] === 'building' && $info['current'] === 2, 'Нужен фактический завершённый уровень.');
transportAssert(count($info['rows']) === 15 && $info['rows'][0]['level'] === 0, 'Таблица уровней должна быть ограниченной.');
$row = $info['rows'][2];
transportAssert(abs($row['production'][901] - 726.0) < 0.001 && $row['production'][911] === -60.0, 'Нужны актуальные формулы и разные множители энергии/ресурсов.');
transportAssert(!array_key_exists('price', $row) && !array_key_exists('duration', $row), 'В таблице не нужны цены и время каждого уровня.');
transportAssert($info['price'] === BuildFunctions::getElementPrice(1, $account, false, 3), 'Отдельная цена следующего уровня должна совпадать с игровой функцией.');
transportAssert($info['duration'] === BuildFunctions::getBuildingTime(1, $account, $info['price']), 'Время должно совпадать с игровой функцией.');
transportAssert($info['requirements'][0]['met'], 'Выполненные требования не должны исчезать.');
transportAssert(EntitySettings::count() === 0 && EntitySettings::getPendingSync()['update'] === [], 'Чтение эффективности не должно создавать настройки.');
transportAssert($before === [$account['Builds'], $account['Techs'], $account['Resources'], $account['Ships']], 'Просмотр не должен менять AccountData.');
echo "OK information building formulas, prices, duration and no mutations\n";

$storage = informationSnapshot(22, $account);
transportAssert($storage['rows'][0]['storage'][901] === 50000.0, 'Хранилище использует текущую формулу и StorageMultiplier.');
$research = informationSnapshot(115, $account);
transportAssert($research['kind'] === 'research' && !$research['requirements'][0]['met'], 'Невыполненное требование должно быть видно.');
transportAssert(informationSnapshot(34, $account)['rows'] === [], 'Объектам без вычисляемых показателей не нужна пустая таблица уровней.');
transportAssert($research['rows'][0]['level'] === $research['current']
    && $research['rows'][0]['effects']['engineSpeed'] === 20, 'У непонижаемого исследования таблица начинается с текущего уровня.');
transportAssert(count($research['unlocks']) === 2, 'Нужны обратные зависимости.');
$energy = informationSnapshot(113, $account);
transportAssert($energy['bonuses']['Energy'] === [0.1, 0], 'Бонусы должны приходить из актуального Vars.');
echo "OK information storage, research, bonuses and dependencies\n";

foreach ([0, 1, 2, 3, 4, 8, 9, 20] as $level) {
    $account['Techs']['astrophysics'] = $level;
    $astro = informationSnapshot(124, $account);
    transportAssert($astro['rows'][0]['level'] === $level, 'Астрофизика: нельзя показывать уровни ниже текущего.');
    $currentRow = array_values(array_filter($astro['rows'], fn($row) => $row['level'] === $level))[0];
    transportAssert($currentRow['effects']['planets'] === FleetColonizationService::planetLimit($account), 'Справка астрофизики должна совпадать с реальным лимитом колонизации.');
    foreach ($astro['rows'] as $row) {
        $limits=FleetExpeditionService::limitsForLevel($row['level']);
        transportAssert($row['effects']['expeditionSlots']===$limits['max'] && $row['effects']['expeditionHours']===$limits['maxHours'],
            'Все строки астрофизики должны показывать действующие лимиты экспедиций и часов.');
    }
    transportAssert(array_column($astro['effects'],'key')===['planets','expeditionSlots','expeditionHours'],'Нужны все три показателя астрофизики.');
}
transportAssert(informationSnapshot(14, $account)['rows'][1]['effects']['buildingTime'] === 50, 'Роботы: время относительно базового на уровне 1 — 50%.');
transportAssert(informationSnapshot(15, $account)['rows'][2]['effects']['naniteTime'] === 25.0, 'Наниты: время на уровне 2 — 25%.');
transportAssert(informationSnapshot(123, $account)['rows'][3]['effects']['networkLabs'] === 4, 'Сеть: лимит лабораторий — уровень плюс один.');
transportAssert(informationSnapshot(131, $account)['productionResources'] === [901], 'Ресурсная технология показывает производство, а не только процент.');
$lab = informationSnapshot(31, $account);
transportAssert($lab['rows'][2]['level'] === 3 && $lab['rows'][2]['effects']['laboratoryTime'] === 25, 'Лаборатория: множитель времени без сети на уровне 3 — 25%.');
$energyCurrent = array_values(array_filter($energy['rows'], fn($row) => $row['level'] === 3))[0];
transportAssert($energy['effects'] === [] && $energyCurrent['production'][911] > 0, 'Энергетическая технология показывает выработку планеты, без процентной колонки.');
echo "OK information astrophysics, engines, factories, network and resource effects\n";

Vars::$attributes[115]['max'] = 3;
$limited = informationSnapshot(115, $account);
transportAssert(array_column($limited['rows'], 'level') === [2, 3], 'Диапазон исследования начинается с текущего уровня и не превышает максимальный.');
transportAssert(array_column($info['rows'], 'level') === range(0, 14), 'Разрушаемые постройки сохраняют две предыдущие ступени.');
echo "OK information level range respects demolition and maximum\n";

$ship = informationSnapshot(202, $account);
transportAssert($ship['stats']['structure']['base'] === 4000.0, 'Структура считается из плоского pricelist SP-Game, не Nova cost.');
transportAssert($ship['stats']['speed']['current'] === FleetFunctions::GetShipSpeed(202, $account)
    && $ship['stats']['consumption']['current'] === FleetFunctions::GetShipConsumption(202, $account), 'Скорость/расход должны совпадать с рабочими функциями.');
transportAssert($ship['engine'] === 117 && $ship['rows'] === [] && $ship['price'][901] === 2000, 'Нужен текущий двигатель и цена одной единицы, без таблицы уровней.');
transportAssert($ship['rapidfire']['to'][0]['value'] === 5.0 && $ship['rapidfire']['from'][0]['id'] === 401, 'Нужны оба направления скорострельности.');
transportAssert(informationSnapshot(401, $account)['kind'] === 'defense', 'Оборона также должна открывать справку.');
transportAssert(count(informationSnapshot(212, $account)['productionResources']) === 1, 'У спутника должна быть выработка единицы, а не таблица партии.');
transportAssert(count(informationSnapshot(212, $account)['rows']) === 1, 'Выработка спутника показывается для одной единицы.');
echo "OK information ships, defense, satellite and rapidfire\n";

// Сравнение прогноза с настоящим ReBuildRes. Только fixture меняет уровни.
// Все настройки предварительно существуют: сам справочник их создавать не должен.
$settings = [];
foreach (Vars::$reslist['prod'] as $id) $settings[] = ['id' => 10000 + $id, 'planet_id' => 10, 'entity_id' => $id,
    'efficiency' => $id === 4 ? 80 : 100, 'last_used' => 0];
installTransportRepository(EntitySettings::class, transportTable($settings), transportSyncTable(), []);
Vars::$reslist['bonus'][] = 14;
Vars::$bonus[14] = array_fill_keys(Vars::$bonusList, [0, 0]);
Vars::$bonus[14]['Energy'] = [0.05, 0];
Vars::$bonus[14]['Resource'] = [0.02, 0];
foreach ([false, true] as $energyDeficit) {
    $account['Builds']['solar_plant'] = $energyDeficit ? 0 : 5;
    $account['Builds']['fusion_plant'] = $energyDeficit ? 0 : 2;
    $account['Ships']['satellite'] = $energyDeficit ? 0 : 1;
    foreach ([113 => 911, 131 => 901, 132 => 902, 133 => 903] as $techId => $resourceId) {
        $originalTechs = $account['Techs'];
        $originalResources = $account['Resources'];
        $projection = informationSnapshot($techId, $account);
        transportAssert($projection['planetProduction'] && $projection['rows'][0]['level'] === $projection['current'], 'Производственная технология начинается с текущего уровня.');
        transportAssert($account['Techs'] === $originalTechs && $account['Resources'] === $originalResources
            && EntitySettings::getPendingSync()['update'] === [], 'Прогноз не должен менять технологии, ресурсы или настройки.');
        foreach (array_slice($projection['rows'], 0, 5) as $row) {
            $account['Techs'][Vars::$resource[$techId]] = $row['level'];
            $actual = $account['Resources'];
            Resources::ReBuildRes($actual, $account);
            $expected = $actual[$resourceId][$resourceId === 911 ? 'max' : 'perhour'];
            transportAssert(abs($row['production'][$resourceId] - $expected) < 0.000001, 'Прогноз должен совпадать с реальным ReBuildRes, включая термоядерную формулу и дефицит энергии.');
        }
        $account['Techs'] = $originalTechs;
    }
}
echo "OK information energy and mining projections match ReBuildRes without mutation\n";

foreach ([null, [], true, 0, -1, 901, 999999, '1x', 1.5] as $id) {
    try {
        informationSnapshot($id, $account);
    } catch (InvalidArgumentException $exception) {
        continue;
    }
    throw new RuntimeException('Недопустимый ID должен быть отклонён.');
}
echo "OK information rejects invalid and unsupported IDs\n";

$account = informationFixture();
foreach ([108 => 'computer_tech', 134 => 'fleet_logistics_tech'] as $id => $name) {
    Vars::$resource[$id] = $name;
    Vars::$reslist['tech'][] = $id;
    Vars::$attributes[$id] = ['factor' => 2, 'max' => 255];
    Vars::$pricelist[$id] = [901 => 400, 902 => 800, 903 => 400];
    foreach ([0,2,3,5,6,11,12,13] as $level) {
        $account['Techs'][$name] = $level;
        $before=[$account['Techs'],$account['Resources'],EntitySettings::getPendingSync()];
        $info = informationSnapshot($id, $account);
        $effect = $id === 108 ? 'fleetMissionSlots' : 'fleetBaseSlots';
        transportAssert($info['rows'][0]['level'] === $level,'Исследования показываются начиная с текущего уровня.');
        foreach ($info['rows'] as $row) {
            $caps=FleetControlService::capabilitiesForLevels($id===108?$row['level']:0,$id===134?$row['level']:0);
            transportAssert($row['effects'][$effect]===($id===108?FleetsServices::missionSlotLimit($row['level']):FleetsServices::baseSlotLimit($row['level'])),
                'Численный лимит должен совпадать с gameplay.');
            if ($id === 108) transportAssert($row['effects']['fleetStages'] === $caps['maxStages'], 'Неверный лимит этапов в справке.');
            foreach ($id===108?['fleetPlan'=>'plan','fleetEdit'=>'edit']:['fleetHold'=>'hold','fleetCargoRefuel'=>'cargoRefuel'] as $key=>$capability) {
                transportAssert($row['effects'][$key]===$caps[$capability],'Доступность должна совпадать с реальным порогом технологии.');
                $meta=array_values(array_filter($info['effects'],static fn($e)=>$e['key']===$key))[0];
                transportAssert($meta['type']==='boolean' && $meta['difference']===false,'Колонкам доступности не нужна разница.');
            }
        }
        transportAssert($info['effects'][0]['unit'] === '', 'Количество слотов не является процентом.');
        transportAssert($before===[$account['Techs'],$account['Resources'],EntitySettings::getPendingSync()],'Чтение будущих уровней не меняет игровой контекст.');
    }
}
echo "OK information complete computer/logistics tables, actual thresholds and no mutations\n";

$account = informationFixture();
Vars::$resource[135] = 'sensor_tech';
Vars::$reslist['tech'][] = 135;
Vars::$attributes[135] = ['factor'=>2,'max'=>255];
Vars::$pricelist[135] = [901=>2000,902=>4000,903=>2000];
$account['Techs']['sensor_tech'] = 2;
$info = informationSnapshot(135,$account);
foreach($info['rows'] as $row) {
    transportAssert($row['effects']['sensorScore']===2*$row['level'],'Вклад сенсоров не соответствует gameplay.');
    transportAssert($row['effects']['sensorMode']===($row['level']>=3),'Неверный порог сенсоров.');
}
echo "OK information sensor score and activation threshold\n";
