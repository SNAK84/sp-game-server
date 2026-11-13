<?php

declare(strict_types=1);

// php tests/celestial-name-examples.php > docs/celestial-name-examples.md
// Только демонстрация имён: игровые объекты и их параметры не создаются.
require_once __DIR__ . '/../vendor/autoload.php';

use SPGame\Game\Services\CelestialNameGenerator as Names;

echo "# Примеры процедурных имён\n\n";
echo "Seed: `sp-game-celestial-v1`. Каноническая латиница хранится один раз; русский вариант получается транслитерацией.\n\n";
echo "## 100 звёздных систем\n\n";
echo "Галактика 1, system от 1 до 100. Одна звезда имеет то же имя.\n\n";
echo "| system | English | Русский |\n| ---: | --- | --- |\n";
for ($system = 1; $system <= 100; $system++) {
    $name = Names::systemName(1, $system);
    echo "| {$system} | {$name} | " . Names::localize($name) . " |\n";
}

echo "\n## 100 планет\n\n";
echo "Галактика 1, system от 1 до 100, орбита 3. Планеты из разных систем могут иметь одинаковые имена.\n\n";
echo "| system | English | Русский |\n| ---: | --- | --- |\n";
for ($system = 1; $system <= 100; $system++) {
    $name = Names::planetName(1, $system, 3);
    echo "| {$system} | {$name} | " . Names::localize($name) . " |\n";
}

echo "\n## 50 лун\n\n";
echo "Галактика 1, system от 1 до 50, родительская орбита 3, спутник 1.\n\n";
echo "| system | English | Русский |\n| ---: | --- | --- |\n";
for ($system = 1; $system <= 50; $system++) {
    $name = Names::moonName(1, $system, 3, 1);
    echo "| {$system} | {$name} | " . Names::localize($name) . " |\n";
}

echo "\n## Полная система: пример отображения\n\n";
echo "Условный состав для демонстрации всех типов: он не изменяет распределение реальных орбит. Пояса на орбитах 2 и 6 получают номера I и II; гиганты на орбитах 3 и 8 — III и VIII.\n\n";
$systemName = Names::systemName(1, 100);
$rows = [['—', 'Система', $systemName, Names::localize($systemName)], ['—', 'Звезда', Names::starName($systemName, 'en'), Names::starName($systemName)]];
$used = [];
$beltNumber = 0;
foreach ([1 => 'planet', 2 => 'belt', 3 => 'gas', 4 => 'planet', 5 => 'planet', 6 => 'belt', 7 => 'planet', 8 => 'gas'] as $orbit => $kind) {
    if ($kind === 'planet') {
        $name = Names::planetName(1, 100, $orbit, $used);
        $used[] = $name;
        $rows[] = [(string)$orbit, 'Планета', $name, Names::localize($name)];
        if ($orbit === 1) {
            $moonName = Names::moonName(1, 100, $orbit, 1, $used);
            $used[] = $moonName;
            $rows[] = ['Спутник орбиты 1', 'Луна', $moonName, Names::localize($moonName)];
        }
    } elseif ($kind === 'gas') {
        $rows[] = [(string)$orbit, 'Газовый гигант', Names::gasGiantName($systemName, $orbit, 'en'), Names::gasGiantName($systemName, $orbit)];
    } else {
        $beltNumber++;
        $rows[] = [(string)$orbit, 'Пояс астероидов', Names::asteroidBeltName($systemName, $beltNumber, 'en'), Names::asteroidBeltName($systemName, $beltNumber)];
    }
}
echo "| Орбита | Тип | English | Русский |\n| --- | --- | --- | --- |\n";
foreach ($rows as $row) {
    echo '| ' . implode(' | ', $row) . " |\n";
}
