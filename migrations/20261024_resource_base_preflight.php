<?php

declare(strict_types=1);

// Только проверка актуальной БД. DDL создаёт штатный init при полном restart;
// не запускать новый мастер до завершения живого QA-прогона без координации.
require_once __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};

if (in_array('--apply', $argv, true)) {
    throw new LogicException('Этот preflight не изменяет БД. Схему создаёт штатный init при согласованном полном restart.');
}
Environment::init(__DIR__ . '/../.env');
$database = Database::getInstance();
$columns = static function (string $table) use ($database): array {
    return array_column($database->fetchAll('SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, COLUMN_KEY AS column_key,
        IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]), null, 'name');
};
$fleetColumns = $columns('fleets');
$baseColumns = $columns('resource_bases');
$missing = $errors = [];
foreach (['home_site_id', 'fallback_planet_id'] as $name) {
    if (!isset($fleetColumns[$name])) { $missing[] = 'fleets.' . $name; continue; }
    $column = $fleetColumns[$name];
    if (!preg_match('/^bigint(?:\(\d+\))? unsigned$/i', $column['type'])
        || $column['nullable'] !== 'NO' || (string)$column['default_value'] !== '0') {
        $errors[] = 'Несовместимая колонка fleets.' . $name;
    }
}
foreach (['base_fuel_time', 'base_fuel_rate', 'base_fuel_reserve', 'base_fuel_speed'] as $name) {
    if (!isset($fleetColumns[$name])) { $missing[] = 'fleets.' . $name; continue; }
    $column = $fleetColumns[$name];
    if (!preg_match('/^double(?:\(\d+,\d+\))?$/i', $column['type']) || $column['nullable'] !== 'NO'
        || $column['default_value'] === null || (float)$column['default_value'] !== 0.0) {
        $errors[] = 'Несовместимая колонка fleets.' . $name;
    }
    if ($name === 'base_fuel_rate' && str_contains($column['type'], ',')) {
        $errors[] = 'Скорость стоянки не должна округляться до фиксированного числа знаков.';
    }
}
if ($baseColumns === []) $missing[] = 'resource_bases';
else foreach (['id', 'owner_id', 'created_at', 'updated_at'] as $name) {
    if (!isset($baseColumns[$name])) { $errors[] = 'Существующая resource_bases не содержит ' . $name; continue; }
    $column = $baseColumns[$name];
    $pattern = in_array($name, ['id', 'owner_id'], true) ? '/^bigint(?:\(\d+\))? unsigned$/i' : '/^double(?:\(\d+,\d+\))?$/i';
    if (!preg_match($pattern, $column['type']) || $column['nullable'] !== 'NO'
        || ($name === 'id' && $column['column_key'] !== 'PRI')) {
        $errors[] = 'Несовместимая колонка resource_bases.' . $name;
    }
}

$homeSite = isset($fleetColumns['home_site_id']) ? 'f.home_site_id' : '0';
$fallback = isset($fleetColumns['fallback_planet_id']) ? 'f.fallback_planet_id' : '0';
$clockFields = '';
foreach (['base_fuel_time', 'base_fuel_rate', 'base_fuel_reserve', 'base_fuel_speed'] as $name) {
    $clockFields .= ',' . (isset($fleetColumns[$name]) ? 'f.' . $name : '0') . ' AS ' . $name;
}
$fleets = $database->fetchAll('SELECT f.id,f.owner_id,f.home_planet_id,' . $homeSite . ' AS home_site_id,'
    . $fallback . ' AS fallback_planet_id,f.status' . $clockFields . ' FROM fleets f');
$lostHome = [];
foreach ($database->fetchAll('SELECT fleet_id,params FROM fleet_commands WHERE executed=0') as $command) {
    $params = json_decode($command['params'], true, 512, JSON_THROW_ON_ERROR);
    if (($params['pause'] ?? null) === 'base' && (int)($params['lostBase'] ?? 0) > 0) {
        $lostHome[(int)$command['fleet_id']] = (int)$params['lostBase'];
    } elseif ((int)($params['stopForLostBase'] ?? 0) > 0) {
        $lostHome[(int)$command['fleet_id']] = (int)$params['stopForLostBase'];
    }
}
$planetById = array_column($database->fetchAll('SELECT id,owner_id,planet_type FROM planets'), null, 'id');
$sites = array_column($database->fetchAll('SELECT id,site_type FROM orbital_resource_sites'), null, 'id');
$baseRows = [];
if ($baseColumns !== [] && isset($baseColumns['id'], $baseColumns['owner_id'])) {
    $baseRows = array_column($database->fetchAll('SELECT id,owner_id FROM resource_bases'), null, 'id');
    foreach ($baseRows as $base) {
        if ((int)$base['owner_id'] <= 0 || !isset($sites[$base['id']])) $errors[] = 'Некорректная ресурсная база ' . $base['id'];
    }
    foreach ($database->fetchAll('SELECT b.id FROM resource_bases b LEFT JOIN users u ON u.id=b.owner_id WHERE u.id IS NULL') as $base) {
        $errors[] = 'У ресурсной базы нет существующего владельца: ' . $base['id'];
    }
}
$planetary = $resource = 0;
$stranded = [];
foreach ($fleets as $fleet) {
    $id = (int)$fleet['id']; $owner = (int)$fleet['owner_id'];
    $site = (int)$fleet['home_site_id']; $planet = (int)$fleet['home_planet_id'];
    foreach (['base_fuel_time', 'base_fuel_rate', 'base_fuel_reserve', 'base_fuel_speed'] as $name) {
        if (!is_finite((float)$fleet[$name]) || (float)$fleet[$name] < 0) $errors[] = 'Некорректный checkpoint: fleet' . $id . '/' . $name;
    }
    if ((float)$fleet['base_fuel_time'] > 0 && ((float)$fleet['base_fuel_speed'] < 1 || (float)$fleet['base_fuel_speed'] > 10)) {
        $errors[] = 'Некорректная скорость эвакуации у флота ' . $id;
    }
    if ($site > 0) {
        $resource++;
        if ($planet === 0 && isset($sites[$site]) && ($lostHome[$id] ?? null) === $site
            && (!isset($baseRows[$site]) || (int)$baseRows[$site]['owner_id'] !== $owner)
            && (float)$fleet['base_fuel_time'] === 0.0) {
            $stranded[] = $id; // Исторический адрес, не действующая ресурсная база.
            continue;
        }
        if ($planet !== 0 || !isset($sites[$site], $baseRows[$site]) || (int)$baseRows[$site]['owner_id'] !== $owner) {
            $errors[] = 'Несогласованный адрес ресурсной базы у флота ' . $id;
        }
        $planet = (int)$fleet['fallback_planet_id'];
        if ((int)$fleet['status'] === 0 && (float)$fleet['base_fuel_time'] <= 0) $errors[] = 'Нет checkpoint стоянки у флота ' . $id;
    } else {
        $planetary++;
        if ((float)$fleet['base_fuel_time'] !== 0.0 || (float)$fleet['base_fuel_rate'] !== 0.0
            || (float)$fleet['base_fuel_reserve'] !== 0.0 || (float)$fleet['base_fuel_speed'] !== 0.0) $errors[] = 'Планетарный флот сохранил расход ресурсной стоянки: ' . $id;
    }
    $home = $planetById[$planet] ?? null;
    if ($home === null || (int)$home['owner_id'] !== $owner || !in_array((int)$home['planet_type'], [1, 3], true)) {
        $errors[] = 'Недоступная планетарная/резервная база у флота ' . $id;
    }
}
echo json_encode(['readOnly' => true, 'fleets' => count($fleets), 'planetary' => $planetary,
    'resource' => $resource, 'stranded' => $stranded, 'resourceBases' => count($baseRows), 'missingSchema' => $missing,
    'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
if ($errors !== []) throw new RuntimeException('Preflight ресурсных баз обнаружил несогласованные данные; автоматических исправлений нет.');
