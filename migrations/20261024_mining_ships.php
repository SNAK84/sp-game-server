<?php

declare(strict_types=1);

// Подготовка этапа5. По умолчанию только SELECT; --apply допустим после
// подключения добычи и при остановленном сервере, до штатного Vars::init().
require_once __DIR__ . '/../vendor/autoload.php';

use SPGame\Core\{Database, Environment};
use SPGame\Game\Enums\FleetMissionType;

Environment::init(__DIR__ . '/../.env');
$database = Database::getInstance();
$balance = require __DIR__ . '/../resources/fleet-mining.php';
$existingShips = [];

// Проверить ОБА корпуса и зависимости до первой записи. Чужие строки,
// неполную миграцию с другим балансом и осиротевшие ссылки не затирать.
foreach ($balance['ships'] as $id => $ship) {
    $definition = $ship['vars'];
    $existing = $database->fetchAll('SELECT * FROM vars WHERE elementID=? OR name=?', [$id, $definition['name']]);
    if (count($existing) > 1) throw new RuntimeException('Конфликт ID и имени добывающего корпуса: ' . $id);
    $old = $existing[0] ?? null;
    if ($old !== null) foreach ($definition as $field => $expected) {
        $matches = is_int($expected) || is_float($expected)
            ? (float)$old[$field] === (float)$expected : $old[$field] === $expected;
        if (!$matches) throw new RuntimeException('Параметры добывающего корпуса отличаются: ' . $id . '/' . $field);
    }
    foreach ($database->fetchAll('SELECT requireID,requireLevel FROM vars_requirements WHERE elementID=?', [$id]) as $requirement) {
        if (($ship['requirements'][(int)$requirement['requireID']] ?? null) !== (int)$requirement['requireLevel']) {
            throw new RuntimeException('Требования добывающего корпуса отличаются: ' . $id);
        }
    }
    if ($old === null && $database->fetchAll('SELECT elementID FROM vars_requirements WHERE requireID=?', [$id])) {
        throw new RuntimeException('ID добывающего корпуса уже используется осиротевшими требованиями: ' . $id);
    }
    foreach ($ship['requirements'] as $requireId => $level) {
        if ($database->fetchOne('SELECT elementID FROM vars WHERE elementID=?', [$requireId]) === null) {
            throw new RuntimeException('Нет обязательного объекта для добывающего корпуса: ' . $requireId);
        }
    }
    $existingShips[$id] = $old;
}

if (!in_array('--apply', $argv, true)) {
    echo "Корпуса223/224 и требования проверены без записи. Добыча и базы этим не включаются.\n";
    return;
}

// Пока обработчик не подключён, нельзя открыть строительство кораблей,
// которым сервер молча отбросит неизвестную capability mining.
if (FleetMissionType::fromKey('mining') === null
    || !is_callable([SPGame\Game\Services\FleetMiningRuntimeService::class, 'complete'])) {
    throw new LogicException('Сначала подключите серверную команду и обработчик добычи.');
}

foreach ($balance['ships'] as $id => $ship) {
    if ($existingShips[$id] === null) {
        $definition = $ship['vars'];
        $database->query('INSERT INTO vars (`' . implode('`,`', array_keys($definition)) . '`) VALUES ('
            . implode(',', array_fill(0, count($definition), '?')) . ')', array_values($definition));
    }
    foreach ($ship['requirements'] as $requireId => $level) {
        if ($database->fetchOne('SELECT requireLevel FROM vars_requirements WHERE elementID=? AND requireID=?', [$id, $requireId]) === null) {
            $database->query('INSERT INTO vars_requirements (elementID,requireID,requireLevel) VALUES (?,?,?)', [$id, $requireId, $level]);
        }
    }
}
echo "Корпуса223/224 добавлены. Динамические колонки создаст штатный init после запуска.\n";
