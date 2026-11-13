<?php

declare(strict_types=1);

require __DIR__ . '/FleetTransportTest.php';

use SPGame\Core\Message;
use SPGame\Game\Pages\OverviewPage;
use SPGame\Game\Repositories\DebrisFields;
use SPGame\Game\Repositories\Vars;
use SPGame\Game\Services\AccountData;
use SPGame\Game\Services\FleetsData;
use Swoole\Table;

$fixture = installTransportFixture();
$account = $fixture['account'];
Vars::$resource += [1 => 'metal_mine', 33 => 'terraformer'];
Vars::$reslist['build'] = [1];
$account['Builds'] = ['metal_mine' => 1, 'terraformer' => 0];
$account['User']['name'] = 'Tester';
$fleets = new ReflectionProperty(AccountData::class, 'FleetsData');
$fleets->setAccessible(true);
$fleets->setValue($account, FleetsData::getInstance());
installTransportRepository(DebrisFields::class, transportTable(), transportSyncTable(), [
    'galaxy_system_orbit' => new Table(), 'galaxy_system' => new Table(),
]);
$page = new OverviewPage(new Message());

$snapshot = $page->render($account);
transportAssert($snapshot['Debris'] === null && DebrisFields::count() === 0,
    'Обзор пустого поля не должен создавать запись.');
echo "OK overview empty field is read-only\n";

DebrisFields::addResources(1, 10, 7, 12345, 6789, 100);
$snapshot = $page->render($account);
transportAssert($snapshot['Debris']['metal'] === 12345.0 && $snapshot['Debris']['crystal'] === 6789.0,
    'Overview должен передавать authoritative запасы текущей орбиты.');
echo "OK overview contains authoritative debris stocks\n";

$account['WorkPlanet'] = 11;
transportAssert($page->render($account)['Debris'] === null, 'При переключении планеты нельзя сохранять старое поле.');
DebrisFields::addResources(1, 10, 8, 0, 250, 100);
transportAssert($page->render($account)['Debris']['crystal'] === 250.0, 'Новая планета должна иметь собственные запасы.');
echo "OK planet switch reads the new orbit\n";

$moon = $fixture['source'];
$moon['id'] = 12;
$moon['planet_type'] = 3;
$account['Planets'][12] = $moon;
$account['WorkPlanet'] = 12;
transportAssert($page->render($account)['Debris']['metal'] === 12345.0, 'Луна и планета используют одно поле на орбите.');
echo "OK moon shares parent orbit debris\n";

$field = DebrisFields::at(1, 10, 7);
DebrisFields::update(['id' => $field['id'], 'metal' => 0, 'crystal' => 0]);
transportAssert($page->render($account)['Debris']['metal'] === 0.0,
    'Новый snapshot должен перекрывать предыдущие запасы, включая обнуление.');
echo "OK depleted field replaces previous stocks\n";
