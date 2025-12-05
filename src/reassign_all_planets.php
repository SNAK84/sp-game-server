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
use SPGame\Game\Services\GalaxyGenerator;


ini_set('memory_limit', '2G');
set_time_limit(0);

// === 1. Инициализация окружения ===
Environment::init(__DIR__ . '/../.env');
$logger = Logger::getInstance();

if (Environment::getBool('DEBUG_MODE', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', '0');
}

$logger->info("=== START: Reassign all planets ===");

// === 2. Инициализация репозиториев ===
$saver = new RepositorySaver();
Accounts::init($saver);
Connect::init();
Config::init($saver);
Users::init($saver);
Planets::init($saver);
Galaxy::init($saver);
GalaxyOrbits::init($saver);

// --- ЭТАП 1: Загрузка и очистка координат ---
$allPlanets = Planets::findAll();
$total = count($allPlanets);
$logger->info("Загружено планет: {$total}");

foreach ($allPlanets as &$planet) {
    $planet['galaxy'] = 0;
    $planet['system'] = 0;
    $planet['planet'] = 0;
}
$logger->info("Все координаты сброшены в 0");

// --- ЭТАП 2: Регенерация координат и свойств ---
$processed = 0;
$errors = 0;

foreach ($allPlanets as &$planet) {
    $processed++;
    try {
        $planetId = (int)$planet['id'];
        $ownerId = (int)($planet['owner_id'] ?? 0);

        // Определяем — домашняя ли это планета
        $isHomeWorld = false;
        if ($ownerId > 0) {
            $user = Users::findById($ownerId);
            if ($user && isset($user['main_planet']) && (int)$user['main_planet'] === $planetId) {
                $isHomeWorld = true;
            }
        }

        // Определяем свободную позицию
        GalaxyGenerator::normalizeCoordinates($planet);

        $g = (int)$planet['galaxy'];
        $s = (int)$planet['system'];
        $p = (int)$planet['planet'];

        if ($g === 0 || $s === 0 || $p === 0) {
            throw new \RuntimeException("Планете id={$planetId} не удалось назначить координаты");
        }

        // Получаем данные системы и орбиты
        $system = Galaxy::getSystem($g, $s);
        if (!$system) {
            throw new \RuntimeException("Система G{$g}:S{$s} не найдена");
        }
        $starType = $system['star_type'] ?? 'G';

        $orbits = GalaxyOrbits::findByIndex('galaxy_system', [$g, $s]);
        $distance = null;
        foreach ($orbits as $orbit) {
            if ((int)$orbit['orbit'] === $p) {
                $distance = (int)$orbit['distance'];
                break;
            }
        }
        if ($distance === null) {
            $distance = 1500;
        }

        // Генерация свойств планеты
        $newPhys = GalaxyGenerator::generatePlanet($starType, $distance, $isHomeWorld);

        // Обновляем физику планеты
        $planet['type']          = $newPhys['type'];
        $planet['image']         = $newPhys['image'];
        $planet['size']          = $newPhys['size'];
        $planet['fields']        = $newPhys['fields'];
        $planet['temp_min']      = $newPhys['temp_min'];
        $planet['temp_max']      = $newPhys['temp_max'];
        $planet['gravity']       = $newPhys['gravity'];
        $planet['atmosphere']    = $newPhys['atmosphere'];
        $planet['habitability']  = $newPhys['habitability'];
        

        Planets::update($planet);
        $logger->info("Планета #{$planetId} → G{$g}:S{$s}:P{$p}" . ($isHomeWorld ? " [Home]" : ""));
    } catch (\Throwable $e) {
        $errors++;
        $logger->error("Ошибка при обработке планеты #{$planet['id']}: " . $e->getMessage());
        continue;
    }
}

$logger->info("Этап 2 завершён. Всего: {$processed}, ошибок: {$errors}");

// --- ЭТАП 3: Синхронизация с базой ---
Planets::syncToDatabase();

$logger->info("=== DONE: Все планеты успешно перегенерированы ===");
echo "Готово! Всего обработано: {$processed}, ошибок: {$errors}\n";
