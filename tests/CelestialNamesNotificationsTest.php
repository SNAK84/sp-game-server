<?php

declare(strict_types=1);

ob_start();
require __DIR__ . '/InformationPageTest.php';
ob_end_clean();

use SPGame\Game\Repositories\{Builds, Fleets, Messages, PhalanxScans, PlanetResources, Planets, Vars};
use SPGame\Game\Services\{CelestialNameGenerator as Names, Notification, PhalanxService};

(new ReflectionProperty(SPGame\Core\Logger::class, 'echoToConsole'))->setValue(SPGame\Core\Logger::getInstance(), false);

function celestialMessage(string $sample, int $recipient): array
{
    $rows = array_filter(Messages::getAll(), static fn(array $row): bool =>
        $row['sample'] === $sample && (int)$row['to_id'] === $recipient);
    $row = end($rows);
    transportAssert($row !== false, 'Ожидалось уведомление ' . $sample);
    return json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
}

foreach ([1, 0] as $generated) {
    foreach (['ru', 'en'] as $language) {
        $fixture = installTransportFixture();
        $owner = $fixture['account'];
        $owner['Account']['lang'] = $language;
        $owner['User']['name'] = 'Tester';
        $source = array_replace($fixture['source'], ['name' => 'Nirella', 'name_generated' => $generated]);
        $target = array_replace($fixture['target'], ['owner_id' => 200, 'name' => 'Velaria', 'name_generated' => $generated]);
        $owner['Planets'] = [10 => $source];
        $host = new TransportAccountData(200, 11, [11 => [901 => ['count' => 0]]], [11 => $target], 2000);
        $otherLanguage = $language === 'ru' ? 'en' : 'ru';
        $host['Account']['lang'] = $otherLanguage;
        $expectedSource = Names::planetDisplayName($source, $language);
        $expectedTarget = Names::planetDisplayName($target, $language);

        Notification::sendFleetTransportArrival($owner, $host, 1, $source, $target, [901 => 10], 100);
        $sent = celestialMessage('FleetTransportArrivalOwner', 100);
        $received = celestialMessage('FleetTransportArrivalTarget', 200);
        transportAssert($sent['SourcePlanetName'] === $expectedSource && $sent['TargetPlanetName'] === $expectedTarget,
            'Транспорт: имена на языке отправителя.');
        transportAssert($received['SourcePlanetName'] === Names::planetDisplayName($source, $otherLanguage)
            && $received['TargetPlanetName'] === Names::planetDisplayName($target, $otherLanguage),
            'Транспорт: отдельные имена на языке получателя.');

        Notification::sendFleetSpyResult($owner, 1, ['targetPlanet' => $target, 'targetAccountData' => $host,
            'report' => ['destroyed' => false]], 100);
        transportAssert(celestialMessage('FleetSpyReport', 100)['TargetPlanetName'] === $expectedTarget
            && celestialMessage('FleetSpyDetected', 200)['TargetPlanetName'] === Names::planetDisplayName($target, $otherLanguage),
            'Разведка: язык каждой стороны сохраняется независимо.');
        Notification::sendFleetDefense($owner, $host, 1, $target, 'arrived', 100);
        transportAssert(celestialMessage('FleetDefense', 100)['TargetPlanetName'] === $expectedTarget
            && celestialMessage('FleetDefense', 200)['TargetPlanetName'] === Names::planetDisplayName($target, $otherLanguage),
            'Защита: разные языки получателей.');

        Notification::sendFleetReturned($owner, 1, $source, 100);
        transportAssert(celestialMessage('FleetReturned', 100)['PlanetName'] === $expectedSource, 'Имя при возврате.');
        Notification::sendFleetRelocated($owner, 1, $target, 100);
        transportAssert(celestialMessage('FleetRelocated', 100)['PlanetName'] === $expectedTarget, 'Имя при перебазировании.');
        Notification::sendFleetSpyReturned($owner, 1, $source, 100);
        transportAssert(celestialMessage('FleetReturned', 100)['PlanetName'] === $expectedSource, 'Возврат разведки.');
        Notification::sendFleetColonizationResult($owner, 1, ['success' => true, 'planet' => $target,
            'coordinate' => ['galaxy' => 1, 'system' => 10, 'orbit' => 8], 'resources' => [], 'depositedFuel' => 0], 100);
        transportAssert(celestialMessage('FleetColonizationSuccess', 100)['PlanetName'] === $expectedTarget,
            'Имя новой колонии в сообщении.');
        Notification::sendFleetExpeditionReturned($owner, $fixture['fleet'], [
            'planet' => $source, 'unloadedResources' => [], 'expeditionResult' => ['credits' => 0], 'unloadedFuel' => 0,
        ], 100);
        transportAssert(celestialMessage('FleetExpeditionReturned', 100)['PlanetName'] === $expectedSource,
            'Имя при возврате экспедиции.');
        Vars::$resource[1] = 'metal_mine';
        Vars::$reslist['build'] = [1];
        Notification::sendBuildBuyable($owner, [901 => 1], 'build', 1, 100);
        transportAssert(celestialMessage('BuildBuyable', 100)['PlanetName'] === $expectedSource, 'Имя при строительстве.');
        $owner['Account']['lang'] = $otherLanguage;
        transportAssert(celestialMessage('FleetTransportArrivalOwner', 100) === $sent
            && $owner['Planet']['name'] === 'Nirella' && $target['name'] === 'Velaria',
            'Смена языка не переписывает историческое сообщение и канонические строки.');
    }
}
echo "OK celestial notifications: RU/EN recipients, transport, spy, defense, colony, returns, construction, manual names and immutable history\n";

$account = informationFixture();
$account['Planet']['name'] = 'Velaria';
$account['Planet']['name_generated'] = 1;
foreach (['ru', 'en'] as $language) {
    $account['Account']['lang'] = $language;
    transportAssert(informationSnapshot(1, $account)['planetName'] === Names::localize('Velaria', $language)
        && $account['Planet']['name'] === 'Velaria', 'Справка локализует копию имени.');
}
$account['Account']['lang'] = 'ru';
$account['Planet']['name_generated'] = 0;
transportAssert(informationSnapshot(1, $account)['planetName'] === 'Velaria', 'Справка сохраняет ручное имя.');
echo "OK celestial information: language-specific presentation and untouched manual/runtime names\n";

foreach ([1, 0] as $generated) {
    foreach (['ru', 'en'] as $language) {
        $fixture = installTransportFixture();
        $owner = $fixture['account'];
        $owner['Account']['lang'] = $language;
        $source = array_replace($fixture['source'], ['planet_type' => 3]);
        $target = array_replace($fixture['target'], ['owner_id' => 200, 'name' => 'Velaria', 'name_generated' => $generated]);
        $owner['Planets'] = [10 => $source];
        installTransportRepository(Planets::class, transportTable([$source, $target]), transportSyncTable(11), []);
        installTransportRepository(Builds::class, transportTable([['id' => 10, 'phalanx' => 1]]), transportSyncTable(), []);
        installTransportRepository(PlanetResources::class, transportTable([['id' => 10, 'deuterium' => 10000.0]]), transportSyncTable(), []);
        installTransportRepository(Fleets::class, transportTable(), transportSyncTable(), []);
        (new ReflectionProperty(PhalanxScans::class, 'table'))->setValue(null, transportTable());
        $scan = PhalanxService::scan($owner, 11, 123, 'celestial', 100);
        transportAssert($scan['targetName'] === Names::planetDisplayName($target, $language)
            && Planets::findExistingById(11)['name'] === 'Velaria', 'Фаланга локализует только имя снимка.');
        $owner['Account']['lang'] = $language === 'ru' ? 'en' : 'ru';
        $paid = PlanetResources::getAll();
        transportAssert(PhalanxService::scan($owner, 11, 123, 'celestial', 101) === $scan
            && PlanetResources::getAll() === $paid, 'Replay сохраняет оплаченный снимок и не повторяет оплату.');
    }
}
echo "OK celestial phalanx: RU/EN, manual names, preserved canonical row and paid snapshot replay\n";
