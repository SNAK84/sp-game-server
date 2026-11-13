<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SPGame\Game\Services\BattleReportService;

$archive = [
    'target' => ['name' => 'Velaria', 'name_generated' => 1, 'coordinate' => ['type' => 1, 'orbit' => 3]],
    'moon' => ['name' => 'Nirella', 'name_generated' => 1, 'created' => true, 'diameter' => 12000],
    'participants' => [
        'planet:10' => ['fleetName' => 'Velaria', 'name_generated' => 1, 'side' => 'defender'],
        'fleet:2' => ['fleetName' => 'Velaria', 'side' => 'attacker'],
        'planet:11' => ['fleetName' => 'My World', 'name_generated' => 0, 'side' => 'defender'],
    ],
    'groups' => ['defense:401' => ['before' => 20, 'lost' => 3]],
];
$before = serialize($archive);
$ru = BattleReportService::localizeNames($archive, 'ru');
if ($ru['target']['name'] !== 'Велария' || $ru['moon']['name'] !== 'Нирелла'
    || $ru['participants']['planet:10']['fleetName'] !== 'Велария'
    || $ru['participants']['fleet:2']['fleetName'] !== 'Velaria'
    || $ru['participants']['planet:11']['fleetName'] !== 'My World'
    || $ru['groups'] !== $archive['groups'] || $ru['target']['coordinate'] !== $archive['target']['coordinate']
    || serialize($archive) !== $before || BattleReportService::localizeNames($archive, 'en') !== $archive) {
    throw new RuntimeException('Локализация боевого снимка меняет архив, флот, ручное имя или боевые данные.');
}
$legacy = ['target' => ['name' => 'Velaria'], 'moon' => ['name' => 'Moon'],
    'participants' => ['planet:1' => ['fleetName' => 'Nirella']]];
if (BattleReportService::localizeNames($legacy, 'ru') !== $legacy) {
    throw new RuntimeException('Исторический архив без признака генерации был изменён.');
}
echo "PASS battle names: RU/EN copies, planet/moon metadata, manual/fleet/legacy names and immutable archive\n";
