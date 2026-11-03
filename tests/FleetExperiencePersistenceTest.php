<?php

declare(strict_types=1);

// Настоящие Fleet/MoveShip/repositories, но in-memory Table из общего fixture.
// Не является проверкой MySQL, рестарта Swoole или commit ещё не созданного ATTACK.
require __DIR__ . '/FleetManagementTest.php';

use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Repositories\{Fleets, FleetsShips, Vars};
use SPGame\Game\Services\{FleetExperienceService, FleetsData, FleetsServices};

$experienceKeepAlive = [];

function experiencePersistenceNear(float $actual, float $expected, string $message): void
{
    transportAssert(abs($actual - $expected) <= max(1e-7, abs($expected)*1e-10), $message);
}

function experiencePersistenceFixture(bool $secondFleet = false): array
{
    global $experienceKeepAlive;
    $fixture = installManagementFixture();
    // Существующий лёгкий fixture не нуждался в ценах, пока XP был нулевым.
    Vars::$pricelist = [202 => [901 => 2000, 902 => 2000]];
    $fixture['account']['Ships'] = ['small_cargo' => 0];
    $experienceKeepAlive[] = $fixture['fleet'];
    if ($secondFleet) {
        $row = Fleets::getById(1);
        $row['id'] = 2;
        Fleets::add($row);
        $row = FleetsShips::findById(1);
        $row['id'] = $row['fleet_id'] = 2;
        FleetsShips::add($row);
        $fixture['second'] = FleetsData::getInstance()->get(2);
        $experienceKeepAlive[] = $fixture['second'];
    }
    return $fixture;
}

$f = experiencePersistenceFixture();
$fleet = $f['fleet'];
$ship = $fleet->getShip(202);
Fleets::update(['id' => 1, 'experience' => 999.0]);
experiencePersistenceNear($fleet->getExperience(), 0, 'Самостоятельный опыт флота не должен давать опыт кораблям.');
$ship->setExperience(1000);
experiencePersistenceNear($fleet->getExperience(), 100, 'Показатель пересчитывается до save.');
$fleet->save();
experiencePersistenceNear(Fleets::getById(1)['experience'], 100, 'Сохраняется производный показатель.');
experiencePersistenceNear(FleetsShips::findById(1)['small_cargo_experience'], 1000, 'Сохраняется суммарный опыт кораблей.');
foreach ([Fleets::class, FleetsShips::class] as $repository) {
    transportAssert(in_array(1, $repository::getPendingSync()['update'], true), 'XP должен попасть в обычный RepositorySaver.');
}
$before = [Fleets::getById(1), FleetsShips::findById(1)];
$fleet->save();
transportAssert([Fleets::getById(1), FleetsShips::findById(1)] === $before, 'Повторный save не начисляет опыт.');
echo "OK experience runtime derived value, save and dirty markers\n";

$f = experiencePersistenceFixture(true);
$account = $f['account'];
$f['fleet']->getShip(202)->setExperience(1000);
$f['fleet']->save();
FleetsServices::MoveShip(2, 1, 202, 4, $account, 100);
experiencePersistenceNear($f['fleet']->getShip(202)->getExperience(), 600, 'Перенос сохраняет опыт оставшихся шести кораблей.');
experiencePersistenceNear($f['second']->getShip(202)->getExperience(), 400, 'Четыре корабля переносят 400 XP.');
experiencePersistenceNear($f['fleet']->getExperience(), 100, 'Опыт источника.');
experiencePersistenceNear($f['second']->getExperience(), 400/14, 'Новички в получателе разбавляют средний опыт.');
experiencePersistenceNear(Fleets::getById(2)['experience'], 400/14, 'Получатель сохраняет новый сводный показатель.');
for ($i = 0; $i < 25; ++$i) {
    FleetsServices::MoveShip(1, 2, 202, 1, $account, 100);
    FleetsServices::MoveShip(2, 1, 202, 1, $account, 100);
}
experiencePersistenceNear($f['fleet']->getShip(202)->getExperience()+$f['second']->getShip(202)->getExperience(),
    1000, 'Повторные переносы не создают и не округляют опыт.');
echo "OK experience runtime fleet transfers conserve fractional XP\n";

$f = experiencePersistenceFixture();
$account = $f['account'];
$f['fleet']->getShip(202)->setExperience(1000);
FleetsServices::MoveShip(0, 1, 202, 4, $account, 100);
experiencePersistenceNear($f['fleet']->getShip(202)->getExperience(), 600, 'Опыт перенесённой на планету части теряется.');
transportAssert($account['Ships']['small_cargo'] === 4 && count($account['Ships']) === 1, 'Планета хранит только количество.');
FleetsServices::MoveShip(1, 0, 202, 4, $account, 100);
experiencePersistenceNear($f['fleet']->getShip(202)->getExperience(), 600, 'Возврат с планеты не возвращает потерянный XP.');
experiencePersistenceNear($f['fleet']->getExperience(), 60, 'Новые корабли снижают средний опыт.');
experiencePersistenceNear(Fleets::getById(1)['experience'], 60, 'Разбавленный показатель сохранён в runtime.');
echo "OK experience runtime planet transfer loses XP and dilutes veterans\n";

$f = experiencePersistenceFixture();
$fleet = $f['fleet'];
$ship = $fleet->getShip(202);
$ship->setCount(100);
$ship->setExperience(10000);
$result = FleetExperienceService::calculateRewards([
    'fleet:1:202' => ['before' => 100, 'after' => 40, 'experience' => 10000,
        'unitCost' => 4000, 'contribution' => 200000, 'eligible' => true],
], 1000, 1000, 200000)['groups']['fleet:1:202'];
// Узко проверяем существующий setter/save путь для ПОДГОТОВЛЕННОГО результата.
// Будущий handler боя должен обернуть все mutations и marker в общий commit.
$ship->setCount($result['count']);
$ship->setExperience($result['experience']);
$fleet->save();
experiencePersistenceNear($ship->getExperience(), 4400, 'Старый опыт погибших не достаётся выжившим.');
experiencePersistenceNear($fleet->getExperience(), 110, 'Средний опыт после потерь и награды.');
Fleets::update(['id' => 1, 'experience' => 999.0]);
$reloaded = new Fleet(1);
$experienceKeepAlive[] = $reloaded;
experiencePersistenceNear($reloaded->getShip(202)->getExperience(), 4400, 'Сумма XP восстанавливается из runtime row.');
experiencePersistenceNear($reloaded->getExperience(), 110, 'Старый сводный кэш не перекрывает опыт кораблей.');
$snapshot = FleetsShips::findById(1);
$reloaded->getExperience();
transportAssert(FleetsShips::findById(1) === $snapshot, 'Чтение показателя не начисляет награду.');
echo "OK experience runtime prepared outcome save and row reload\n";

$f = experiencePersistenceFixture();
$account = $f['account'];
$f['fleet']->getShip(202)->setExperience(1000);
transportAssert(FleetsServices::DisbandFleet(1, $account, 100), 'Флот должен расформироваться штатно.');
transportAssert($account['Ships']['small_cargo'] === 10, 'Корабли вернулись на планету.');
transportAssert(Fleets::getById(1) === null && FleetsShips::findExistingById(1) === null, 'XP исчезает вместе с расформированным флотом.');
transportAssert(in_array(1, FleetsShips::getPendingSync()['delete'], true), 'Удаление XP сохраняется обычным delete marker.');
echo "OK experience runtime disband intentionally discards XP\n";
