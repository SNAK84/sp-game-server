<?php

declare(strict_types=1);

// Разовая подготовка, разрешённая пользователем; только остановленный dev-сервер.
require_once __DIR__ . '/../vendor/autoload.php';
SPGame\Core\Environment::init(__DIR__ . '/../.env');
use SPGame\Game\Repositories\{Vars, Config, Planets, Techs, Builds, Ships, Fleets, FleetsShips};
Vars::init(); Config::init(); Planets::init(); Techs::init(); Builds::init(); Ships::init();
Fleets::init(); FleetsShips::init();
$planet = Planets::findExistingById(26);
if ($planet === null || (int)$planet['owner_id'] !== 9 || (int)$planet['galaxy'] !== 1
    || (int)$planet['system'] !== 6 || (int)$planet['planet'] !== 2) throw new RuntimeException('QA-цель изменилась.');
$tech = Techs::findExistingById(9);
$build = Builds::findExistingById(26);
$ships = Ships::findExistingById(26);
if ($tech === null || $build === null || $ships === null) throw new RuntimeException('Нет runtime-данных QA-аккаунта.');
$inFleets = 0;
foreach (Fleets::findByIndex('owner_id', [9]) ?? [] as $fleet) {
    $inFleets += (int)(FleetsShips::findExistingById((int)$fleet['id'])['geological_surveyor_count'] ?? 0);
}
if (!in_array('--apply', $argv, true)) { echo "QA-подготовка проверена, для применения --apply.\n"; return; }
Techs::update(['id' => 9, 'sensor_tech' => max(10, $tech['sensor_tech']),
    'expedition_tech' => max(3, $tech['expedition_tech']), 'impulse_motor_tech' => max(4, $tech['impulse_motor_tech'])]);
Builds::update(['id' => 26, 'hangar' => max(6, $build['hangar'])]);
// Учитываем уже перенесённые во флоты корпуса, а не выдаём ещё16 в пустой ангар.
Ships::update(['id' => 26, 'geological_surveyor' => max(0, 16 - $inFleets, $ships['geological_surveyor'])]);
foreach ([Techs::class, Builds::class, Ships::class] as $class) {
    $class::syncToDatabase();
    if ($class::getPendingSync()['update'] !== []) throw new RuntimeException('QA-подготовка не сохранена: ' . $class);
}
echo "Подготовлен только QA owner=9, планета 26: S10, астрофизика3, импульс4, верфь6, 16 геологоразведчиков. Ресурсы не изменены.\n";
