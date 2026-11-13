<?php

declare(strict_types=1);

// Чистые проверки: ни MySQL, ни Swoole Table, ни рабочий сервер не используются.
require_once __DIR__ . '/../vendor/autoload.php';

use SPGame\Game\Services\CelestialNameGenerator as Names;

$checks = 0;
function nameAssert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function nameKey(string $name): string
{
    return mb_strtolower(Names::localize($name, 'ru'), 'UTF-8');
}

$systems = [];
$russianSystems = [];
for ($system = 1; $system <= 32768; $system++) {
    $name = Names::systemName(1, $system);
    nameAssert(!isset($systems[$name]), "Коллизия систем: {$name}");
    nameAssert(!isset($russianSystems[nameKey($name)]), "Коллизия русских систем: {$name}");
    nameAssert(preg_match('/^[A-Z][a-z]+$/D', $name) === 1 && strlen($name) <= 32, "Недопустимое системное имя: {$name}");
    $systems[$name] = true;
    $russianSystems[nameKey($name)] = true;
}
unset($systems, $russianSystems);

// Один и тот же корень в разных каталоговых блоках, включая буквенные суффиксы.
$catalogue = [];
$russianCatalogue = [];
foreach ([1, 32768, 32769, 65536, 65537, 4294967294, 4294967295] as $system) {
    $canonical = Names::systemName(7, $system, 'another-seed');
    nameAssert(!isset($catalogue[$canonical]), 'Коллизия на границе блока');
    nameAssert(strlen($canonical) <= 32, 'Системное имя не помещается в Galaxy.name');
    $catalogue[$canonical] = true;
    nameAssert($canonical === Names::systemName(7, $system, 'another-seed'), 'Неустойчивое имя системы');
}
for ($block = 0; $block < 1000; $block++) {
    $canonical = Names::systemName(1, 1 + 32768 * $block);
    $localized = nameKey($canonical);
    nameAssert(!isset($russianCatalogue[$localized]), 'Каталоговые индексы сливаются после транслитерации');
    $russianCatalogue[$localized] = true;
}
$orderIndependent = [];
foreach ([1, 19, 32768, 32769, 123456789, 4294967295] as $system) {
    $orderIndependent[$system] = Names::systemName(5, $system);
}
foreach (array_reverse(array_keys($orderIndependent)) as $system) {
    nameAssert(Names::systemName(5, $system) === $orderIndependent[$system], 'Имя зависит от порядка генерации');
}
nameAssert(Names::systemName(1, 17) !== Names::systemName(2, 17), 'Галактика не влияет на системное имя');
nameAssert(Names::systemName(1, 17) !== Names::systemName(1, 17, 'other'), 'Seed не влияет на системное имя');

$planetSet = [];
$moonSet = [];
$maxRussianBytes = 0;
for ($system = 1; $system <= 10000; $system++) {
    foreach (['planet', 'moon'] as $kind) {
        $name = $kind === 'planet' ? Names::planetName(1, $system, 3) : Names::moonName(1, $system, 3, 1);
        $russian = Names::localize($name, 'ru');
        nameAssert($name !== '' && preg_match('/^[A-Z][a-z]+$/D', $name) === 1, "Неверные символы {$kind}: {$name}");
        nameAssert(strlen($name) <= 32 && strlen($russian) <= 32, "Превышена длина {$kind}: {$name}");
        nameAssert(preg_match('/^[А-ЯЁ][а-яё]+$/uD', $russian) === 1, "Неверная транслитерация: {$russian}");
        nameAssert(preg_match('/([a-z]{2,4})\1/i', $name) === 0, "Повтор слогов: {$name}");
        foreach (['sra', 'suk', 'piz', 'sex', 'porno', 'pidor', 'govn', 'ebat', 'shit', 'fuck', 'cunt'] as $unwanted) {
            nameAssert(!str_contains(strtolower($name), $unwanted), "Нежелательное сочетание: {$name}");
        }
        $maxRussianBytes = max($maxRussianBytes, strlen($russian));
        if ($kind === 'planet') {
            $planetSet[$name] = true;
        } else {
            $moonSet[$name] = true;
        }
    }
}
nameAssert(count($planetSet) >= 9000, 'Недостаточное разнообразие планет');
nameAssert(count($moonSet) >= 8000, 'Недостаточное разнообразие лун');

$planet = Names::planetName(4, 91, 3);
$moon = Names::moonName(4, 91, 3, 1);
nameAssert($planet === Names::planetName(4, 91, 3), 'Неустойчивое имя планеты');
nameAssert($moon === Names::moonName(4, 91, 3, 1), 'Неустойчивое имя луны');
nameAssert($planet !== Names::planetName(4, 91, 4), 'Орбита не влияет на имя');
nameAssert($planet !== Names::planetName(4, 91, 3, [], 'other'), 'Seed не влияет на имя планеты');
nameAssert($moon !== Names::moonName(4, 91, 3, 2), 'Спутник не влияет на имя');
nameAssert(nameKey(Names::planetName(4, 91, 3, [mb_strtoupper(Names::localize($planet), 'UTF-8')])) !== nameKey($planet), 'Не учитывается ручное русское имя');
nameAssert(nameKey(Names::moonName(4, 91, 3, 1, [strtoupper($moon)])) !== nameKey($moon), 'Не учитывается ручное латинское имя');

$used = [];
$keys = [];
for ($orbit = 1; $orbit <= 150; $orbit++) {
    foreach (['planet', 'moon'] as $kind) {
        $name = $kind === 'planet' ? Names::planetName(3, 49, $orbit, $used) : Names::moonName(3, 49, $orbit, 1, $used);
        nameAssert(!isset($keys[nameKey($name)]), "Коллизия объектов одной системы: {$name}");
        $keys[nameKey($name)] = true;
        $used[] = $name;
    }
}
// Намеренное истощение вариантов одного идентификатора проверяет гарантированный fallback.
$exhausted = [];
for ($iteration = 0; $iteration < 110; $iteration++) {
    $name = Names::planetName(1, 1, 3, $exhausted);
    nameAssert(!in_array($name, $exhausted, true), 'Каталоговый fallback дал коллизию');
    nameAssert(strlen($name) <= 32 && strlen(Names::localize($name)) <= 32, 'Fallback превышает размер имени');
    $exhausted[] = $name;
}
nameAssert(str_starts_with($name, 'Lira-3-'), 'Не проверен путь истощения фонетических вариантов');
$exhaustedMoons = [];
for ($iteration = 0; $iteration < 100; $iteration++) {
    $name = Names::moonName(1, 1, 4294967295, 4294967295, $exhaustedMoons);
    nameAssert(strlen($name) <= 32 && strlen(Names::localize($name)) <= 32, 'Лунный fallback с uint32 превышает размер имени');
    $exhaustedMoons[] = $name;
}
nameAssert(str_starts_with($name, 'Luna-4294967295-'), 'Не проверен лунный fallback');

foreach (['Velaria' => 'Велария', 'Nirella' => 'Нирелла', 'Altaris' => 'Альтарис', 'Zeron' => 'Зерон'] as $canonical => $russian) {
    // Начальное Al перед согласной имеет привычное мягкое произношение.
    nameAssert(Names::localize($canonical, 'ru') === $russian, "Неверный русский вариант {$canonical}");
    nameAssert(Names::localize($canonical, 'en') === $canonical, 'Каноническое имя изменено для английского языка');
}
nameAssert(Names::starName('Valerion') === 'Валерион', 'Одиночная звезда потеряла имя системы');
nameAssert(Names::starName('Altaris', 'en', 2) === 'Altaris B', 'Неверное обозначение компонента');
nameAssert(Names::gasGiantName('Valerion', 7) === 'Валерион VII', 'Номер газового гиганта не равен номеру орбиты');
nameAssert(Names::gasGiantName('Valerion', 14, 'en') === 'Valerion XIV', 'Неверная римская нумерация');
nameAssert(Names::asteroidBeltName('Valerion', 1) === 'Пояс Валериона I', 'Неверное имя первого пояса');
nameAssert(Names::asteroidBeltName('Altaris', 2) === 'Пояс Альтариса II', 'Неверное имя второго пояса');
nameAssert(Names::asteroidBeltName('Kassira', 3) === 'Пояс Кассиры III', 'Неверное склонение на -а');
nameAssert(Names::asteroidBeltName('Altaris', 2, 'en') === 'Altaris Belt II', 'Неверное английское имя пояса');
nameAssert(Names::planetDisplayName(['name' => 'Velaria', 'name_generated' => 0], 'ru') === 'Velaria', 'Изменено ручное латинское имя');
nameAssert(Names::planetDisplayName(['name' => 'Мой дом'], 'en') === 'Мой дом', 'Изменено историческое имя');
nameAssert(Names::planetDisplayName(['name' => 'Velaria', 'name_generated' => 1], 'ru') === 'Велария', 'Не локализовано созданное имя');

// В тесте задаётся глобальная последовательность MT и сравнивается её следующий элемент.
mt_srand(73119);
$expectedRandom = mt_rand();
mt_srand(73119);
Names::systemName(8, 345);
Names::planetName(8, 345, 5);
Names::moonName(8, 345, 5, 2);
nameAssert(mt_rand() === $expectedRandom, 'Генератор имён изменил глобальное состояние MT');

foreach ([0, -1, 4294967296] as $invalid) {
    try {
        Names::systemName(1, $invalid);
        throw new RuntimeException('Принят неверный идентификатор системы');
    } catch (InvalidArgumentException) {
        $checks++;
    }
}

$benchmarks = [];
foreach (['system', 'planet', 'moon'] as $kind) {
    $start = hrtime(true);
    for ($system = 1; $system <= 10000; $system++) {
        match ($kind) {
            'system' => Names::systemName(1, $system),
            'planet' => Names::planetName(1, $system, 3),
            'moon' => Names::moonName(1, $system, 3, 1),
        };
    }
    $benchmarks[$kind] = round((hrtime(true) - $start) / 1000000, 2);
}

echo json_encode([
    'status' => 'CELESTIAL_NAMES_OK',
    'checks' => $checks,
    'unique_systems' => 32768,
    'unique_planets_of_10000' => count($planetSet),
    'unique_moons_of_10000' => count($moonSet),
    'maximum_russian_bytes' => $maxRussianBytes,
    'generation_10000_ms' => $benchmarks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
