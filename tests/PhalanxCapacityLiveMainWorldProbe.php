<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{Accounts, Builds, PhalanxScans, Planets, PlanetResources, Users};
use Swoole\Timer;

/** Временный onStart основного dev. Заполняет только RAM-cache своими ключами.
 * .release освобождает их, .finish возвращает тестовые5000 дейтерия snak1.
 * При timeout также освобождает cache. Никаких SQL/newTable/новых аккаунтов.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $account = Accounts::findByLogin('snak1') ?? throw new LogicException('Нет snak1.');
    $user = Users::findByAccount((int)$account['id']); $source = $target = null;
    foreach (Planets::getAll() ?? [] as $planet) if ((int)$planet['owner_id'] === (int)$user['id']
        && (int)$planet['planet_type'] === 3 && (int)(Builds::findExistingById((int)$planet['id'])['phalanx'] ?? 0) > 0) {
        $source = $planet; break;
    }
    if ($source === null) throw new LogicException('Нет QA-луны с фалангой.');
    foreach (Planets::getAll() ?? [] as $planet) if ((int)$planet['planet_type'] === 1 && (int)$planet['owner_id'] > 0
        && $planet['galaxy'] === $source['galaxy'] && $planet['system'] === $source['system']) { $target = $planet; break; }
    if ($target === null) throw new LogicException('Нет QA-цели.');
    $original = PlanetResources::findExistingById((int)$source['id'])['deuterium'];
    if ($original < 5000) throw new LogicException('Нет топлива QA.');
    $table = (new ReflectionProperty(PhalanxScans::class, 'table'))->getValue();
    $owned = []; $started = microtime(true);
    $row = PhalanxScans::pack(['scannedAt' => $started, 'fleets' => []], $started);
    for ($i = 1; $table->count() < PhalanxScans::capacity(); ++$i) {
        $key = (string)(1901000000 + $i);
        if ($table->exists($key)) throw new LogicException('Конфликт fixture key.');
        $owned[] = $key;
        if (!$table->set($key, $row)) throw new RuntimeException('Не заполнен cache.');
    }
    $proof = ['phase' => 'full', 'sourceId' => (string)$source['id'], 'targetId' => (string)$target['id'],
        'galaxy' => (int)$target['galaxy'], 'system' => (int)$target['system'],
        'initialDeuterium' => $original, 'refunded' => false, 'fixturesReleased' => false, 'pid' => getmypid()];
    $write = static function () use ($path, &$proof): void {
        file_put_contents($path, json_encode($proof, JSON_THROW_ON_ERROR), LOCK_EX); chmod($path, 0600);
    };
    $write();
    Timer::tick(100, static function (int $timer) use ($path, $started, $table, $owned, &$proof, $write): void {
        $timeout = microtime(true) - $started > 120;
        $finish = $timeout || file_exists($path . '.finish');
        if (!$proof['fixturesReleased'] && ($finish || file_exists($path . '.release'))) {
            foreach ($owned as $key) if ($table->exists($key) && !$table->del($key)) throw new RuntimeException('Не освобождён fixture.');
            $proof['fixturesReleased'] = true; $proof['phase'] = 'released'; $write();
        }
        if (!$finish) return;
        $current = PlanetResources::findExistingById((int)$proof['sourceId'])['deuterium'];
        if (abs($current - ($proof['initialDeuterium'] - 5000)) < .000001) {
            PlanetResources::update(['id' => (int)$proof['sourceId'], 'deuterium' => $proof['initialDeuterium']]);
            $proof['refunded'] = true;
        } elseif (abs($current - $proof['initialDeuterium']) > .000001) {
            $proof['unexpectedDeuterium'] = true; // Не затирать неизвестные изменения.
        }
        $proof['finalDeuterium'] = PlanetResources::findExistingById((int)$proof['sourceId'])['deuterium'];
        $proof['phase'] = 'complete'; $proof['timeout'] = $timeout;
        Timer::clear($timer); $write();
    });
};
