<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\Logger;
use SPGame\Core\Connect;
use SPGame\Core\Environment;

use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Galaxy;
use SPGame\Game\Repositories\GalaxyOrbits;
use SPGame\Game\Repositories\Config;

use SPGame\Game\Services\RepositorySaver;


ini_set('memory_limit', '2G');
set_time_limit(0);


// ============================================================
// 1. Инициализация окружения
// ============================================================

Environment::init(__DIR__ . '/../.env');

$logger = Logger::getInstance();

if (Environment::getBool('DEBUG_MODE', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', '0');
}

$logger->info("=== START: Update orbit types ===");


// ============================================================
// 2. Инициализация репозиториев
// ============================================================

$saver = new RepositorySaver();

Accounts::init($saver);
Connect::init();
Config::init($saver);
Users::init($saver);
Planets::init($saver);
Galaxy::init($saver);
GalaxyOrbits::init($saver);


// ============================================================
// 3. Загружаем все планеты
// ============================================================

$allPlanets = Planets::findAll();

$planetCount = count($allPlanets);

$logger->info("Загружено планет: {$planetCount}");


// ============================================================
// 4. Создаём карту планет по координатам
//
// G:S:P => planet
// ============================================================

$planetMap = [];

foreach ($allPlanets as $planet) {

    $g = (int)($planet['galaxy'] ?? 0);
    $s = (int)($planet['system'] ?? 0);
    $p = (int)($planet['planet'] ?? 0);

    if ($g <= 0 || $s <= 0 || $p <= 0) {
        continue;
    }

    $key = "{$g}:{$s}:{$p}";

    $planetMap[$key] = $planet['id'];
}

$logger->info(
    "Сформирована карта занятых орбит: " . count($planetMap)
);


// ============================================================
// 5. Получаем список систем
// ============================================================

$systems = Galaxy::getAll() ?? [];

$totalSystems = count($systems);

$logger->info("Загружено систем: {$totalSystems}");


// ============================================================
// 6. Обрабатываем орбиты
// ============================================================

$processed = 0;
$updated   = 0;
$errors    = 0;

$countEmpty        = 0;
$countAsteroidBelt = 0;
$countGasGiant     = 0;
$countPlanet       = 0;


foreach ($systems as $systemData) {

    $g = (int)($systemData['galaxy'] ?? 0);
    $s = (int)($systemData['system'] ?? 0);

    if ($g <= 0 || $s <= 0) {
        continue;
    }

    try {

        // Получаем орбиты конкретной системы
        $orbits = GalaxyOrbits::findByIndex(
            'galaxy_system',
            [$g, $s]
        ) ?? [];

        foreach ($orbits as $orbit) {

            $processed++;

            $orbitId = (int)$orbit['id'];
            $p       = (int)$orbit['orbit'];

            $oldType = (int)($orbit['type'] ?? GalaxyOrbits::EMPTY);

            $key = "{$g}:{$s}:{$p}";


            // =================================================
            // Определяем правильный тип
            // =================================================

            if (isset($planetMap[$key])) {

                // На орбите находится планета
                $newType = GalaxyOrbits::PLANET;

                $countPlanet++;

            } else {

                // Планеты нет.
                //
                // ASTEROID_BELT и GAS_GIANT сохраняем.
                // Всё остальное переводим в EMPTY.

                if ($oldType === GalaxyOrbits::ASTEROID_BELT) {

                    $newType = GalaxyOrbits::ASTEROID_BELT;
                    $countAsteroidBelt++;

                } elseif ($oldType === GalaxyOrbits::GAS_GIANT) {

                    $newType = GalaxyOrbits::GAS_GIANT;
                    $countGasGiant++;

                } else {

                    $newType = GalaxyOrbits::EMPTY;
                    $countEmpty++;
                }
            }


            // =================================================
            // Изменяем только если тип отличается
            // =================================================

            if ($oldType !== $newType) {

                $orbit['type'] = $newType;

                GalaxyOrbits::update($orbit);

                $updated++;

                $logger->info(
                    "Орбита #{$orbitId} "
                    . "G{$g}:S{$s}:P{$p}: "
                    . "{$oldType} -> {$newType}"
                );
            }
        }

    } catch (\Throwable $e) {

        $errors++;

        $logger->error(
            "Ошибка G{$g}:S{$s}: " . $e->getMessage()
        );
    }
}


// ============================================================
// 7. Синхронизация с базой
// ============================================================

$logger->info("Синхронизация орбит с базой...");

GalaxyOrbits::syncToDatabase();


// ============================================================
// 8. Итог
// ============================================================

$logger->info("=== DONE: Update orbit types ===");

$logger->info("Обработано орбит: {$processed}");
$logger->info("Изменено орбит: {$updated}");
$logger->info("Ошибок: {$errors}");

$logger->info("PLANET: {$countPlanet}");
$logger->info("ASTEROID_BELT: {$countAsteroidBelt}");
$logger->info("GAS_GIANT: {$countGasGiant}");
$logger->info("EMPTY: {$countEmpty}");


echo PHP_EOL;
echo "Готово!" . PHP_EOL;
echo "Систем:          {$totalSystems}" . PHP_EOL;
echo "Орбит обработано: {$processed}" . PHP_EOL;
echo "Орбит изменено:   {$updated}" . PHP_EOL;
echo "Ошибок:           {$errors}" . PHP_EOL;
echo PHP_EOL;
echo "PLANET:        {$countPlanet}" . PHP_EOL;
echo "ASTEROID_BELT: {$countAsteroidBelt}" . PHP_EOL;
echo "GAS_GIANT:     {$countGasGiant}" . PHP_EOL;
echo "EMPTY:         {$countEmpty}" . PHP_EOL;
