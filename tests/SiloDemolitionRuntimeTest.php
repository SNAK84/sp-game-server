<?php

declare(strict_types=1);

define('SP_GAME_PRODUCTION_FIXTURE_ONLY', true);
require __DIR__ . '/ProductionResearchRuntimeTest.php';

use SPGame\Core\{Database, Message};
use SPGame\Game\Pages\{BuildingsPage, HangarPage};
use SPGame\Game\Repositories\{Builds, Config, Defenses, Queues, Vars};
use SPGame\Game\Services\{AccountData, BuildFunctions, QueuesServices};

function siloFixture(): AccountData
{
    $a = productionFixture();
    Vars::$resource += [502 => 'interceptor', 503 => 'interplanetary'];
    Vars::$reslist['build'][] = 44;
    Vars::$reslist['allow'][1][] = 44;
    Vars::$reslist['missile'] = [502, 503];
    foreach ([44, 502, 503] as $id) {
        Vars::$attributes[$id] = ['factor' => $id === 44 ? 2 : 1, 'max' => 100, 'type' => 1];
        Vars::$pricelist[$id] = [901 => 1000, 902 => 500];
    }
    Config::add(['name' => 'SiloFactor', 'value' => '3']);
    researchRepository(Builds::class, [
        ['id' => 101, 'silo' => 3, 'laboratory' => 1, 'hangar' => 1],
        ['id' => 102, 'silo' => 3, 'laboratory' => 1, 'hangar' => 1],
        ['id' => 103, 'silo' => 3, 'laboratory' => 1, 'hangar' => 1],
    ], ['silo']);
    researchRepository(Defenses::class, [['id' => 101], ['id' => 102], ['id' => 103]], ['interceptor', 'interplanetary']);
    $a['Builds']['silo'] = 3;
    $a['Defenses']['interceptor'] = $a['Defenses']['interplanetary'] = 0;
    return $a;
}

foreach ([502, 503] as $missile) {
    researchCase("готовая ракета $missile: демонтаж запрещён, улучшение разрешено", static function () use ($missile): void {
        $a = siloFixture();
        $a['Defenses'][Vars::$resource[$missile]] = 1;
        $before = $a['Resources']->toArray();
        $card = (new BuildingsPage(new Message()))->render($a)['BuildList'][44];
        researchAssert($card['demolitionBlocked'] && !$card['working'], 'Неверные флаги UI.');
        QueuesServices::AddToQueue(44, $a, 1000, false);
        researchAssert(empty(Queues::getCurrentQueue('builds', 1, 101)), 'Создан демонтаж.');
        researchAssert($a['Resources']->toArray() === $before, 'Списаны ресурсы за запрет.');
        $a['WorkPlanet'] = 102;
        researchAssert(!QueuesServices::isElementBusy(44, $a, true), 'Ракеты заблокировали другую планету.');
        $a['WorkPlanet'] = 101;
        QueuesServices::AddToQueue(44, $a, 1000);
        researchAssert(count(Queues::getCurrentQueue('builds', 1, 101)) === 1, 'Запрещено улучшение.');
    });

    foreach (['queued', 'active'] as $status) {
        researchCase("ракета $missile в очереди $status блокирует демонтаж", static function () use ($missile, $status): void {
            $a = siloFixture();
            Queues::add(productionQueue(1, $missile, status: $status));
            $before = $a['Resources']->toArray();
            QueuesServices::AddToQueue(44, $a, 1000, false);
            researchAssert(empty(Queues::getCurrentQueue('builds', 1, 101)), 'Не учтена строящаяся ракета.');
            researchAssert($a['Resources']->toArray() === $before, 'Есть списание.');
            Queues::delete(Queues::findExistingById(1));
            researchAssert(!(new BuildingsPage(new Message()))->render($a)['BuildList'][44]['demolitionBlocked'], 'После отмены осталась блокировка.');
        });
    }
}

foreach ([202, 401] as $other) {
    researchCase("корабли/обычная оборона $other не мешают демонтировать шахту", static function () use ($other): void {
        $a = siloFixture();
        Queues::add(productionQueue(1, $other, status: 'queued'));
        QueuesServices::AddToQueue(44, $a, 1000, false);
        researchAssert(count(Queues::getCurrentQueue('builds', 1, 101)) === 1, 'Лишняя блокировка.');
    });
}

foreach (['active', 'queued'] as $status) {
    researchCase("демонтаж $status: нельзя заказать ракеты, но можно корабли и оборону", static function () use ($status): void {
        $a = siloFixture();
        $q = productionQueue(1, 44, status: $status);
        $q['action'] = 'destroy';
        Queues::add($q);
        $page = new HangarPage(new Message());
        $page->hangarMode = 'Defenses';
        $cards = $page->render($a)['FleetList'];
        researchAssert($cards[502]['siloDismantling'] && $cards[503]['siloDismantling'], 'Нет флага блокировки ракет.');
        researchAssert(!$cards[401]['siloDismantling'], 'Заблокирована обычная оборона.');
        $before = (float)$a['Resources'][901]['count'];
        QueuesServices::AddToQueueHangar([502 => 1, 503 => 1, 202 => 1, 401 => 1], $a, 1000);
        $queue = Queues::getCurrentQueue('hangars', 1, 101);
        researchAssert(array_column($queue, 'object_id') === [202, 401], 'Ракеты прошли запрет или заблокированы другие элементы.');
        productionNear($a['Resources'][901]['count'], $before - 2000, 'Неверное списание');
        Queues::delete(Queues::findExistingById(1));
        QueuesServices::AddToQueueHangar([502 => 1, 503 => 1], $a, 1000);
        researchAssert(count(Queues::getCurrentQueue('hangars', 1, 101)) === 4, 'После снятия демонтажа ракеты не строятся.');
    });
}

researchCase('ожидающий демонтаж повторно проверяет появившиеся ракеты до списания', static function (): void {
    $a = siloFixture();
    $q = productionQueue(1, 44, status: 'queued');
    $q['action'] = 'destroy';
    Queues::add($q);
    $a['Defenses']['interceptor'] = 1;
    $before = $a['Resources']->toArray();
    $pending = [Queues::findExistingById(1)];
    researchAssert(!QueuesServices::activateNextQueueItem($pending, $a, 1000), 'Демонтаж активирован.');
    researchAssert(Queues::findExistingById(1) === null, 'Отклонённая запись не удалена.');
    researchAssert($a['Builds']['silo'] === 3 && $a['Defenses']['interceptor'] === 1, 'Изменена шахта/ракеты.');
    researchAssert($a['Resources']->toArray() === $before, 'Списаны ресурсы.');
});

researchCase('ожидающий демонтаж повторно проверяет очередь производства ракет', static function (): void {
    $a = siloFixture();
    $q = productionQueue(1, 44, status: 'queued');
    $q['action'] = 'destroy';
    Queues::add($q);
    Queues::add(productionQueue(2, 503, status: 'queued'));
    $before = $a['Resources']->toArray();
    $pending = [Queues::findExistingById(1)];
    researchAssert(!QueuesServices::activateNextQueueItem($pending, $a, 1000), 'Не учтена очередь ракет.');
    researchAssert($a['Resources']->toArray() === $before && $a['Builds']['silo'] === 3, 'Изменены ресурсы/шахта.');
});

researchCase('отмена настоящего демонтажа возвращает оплату и разрешает производство ракет', static function (): void {
    $a = siloFixture();
    $before = $a['Resources']->toArray();
    QueuesServices::AddToQueue(44, $a, 1000, false);
    $q = Queues::getCurrentQueue('builds', 1, 101)[0];
    QueuesServices::CancelToQueue((int)$q['id'], $a, 1000);
    researchAssert($a['Resources']->toArray() === $before, 'Неверный возврат при отмене.');
    QueuesServices::AddToQueueHangar([502 => 1, 503 => 1], $a, 1000);
    researchAssert(count(Queues::getCurrentQueue('hangars', 1, 101)) === 2, 'После отмены остался запрет.');
});

researchCase('улучшение шахты не блокирует производство ракет; другая планета не мешает демонтажу', static function (): void {
    $a = siloFixture();
    Queues::add(productionQueue(1, 503, 102, 'queued'));
    researchAssert(!QueuesServices::isElementBusy(44, $a, true), 'Ракеты другой планеты блокируют демонтаж.');
    QueuesServices::AddToQueue(44, $a, 1000);
    QueuesServices::AddToQueueHangar([502 => 1], $a, 1000);
    researchAssert(count(Queues::getCurrentQueue('hangars', 1, 101)) === 1, 'Улучшение запретило строить ракеты.');
});

researchCase('числовые строковые ID ракет не обходят запрет производства при демонтаже', static function (): void {
    $a = siloFixture();
    $q = productionQueue(1, 44);
    $q['action'] = 'destroy';
    Queues::add($q);
    $before = $a['Resources']->toArray();
    QueuesServices::AddToQueueHangar(['0502' => 1, '503.0' => 1], $a, 1000);
    researchAssert(empty(Queues::getCurrentQueue('hangars', 1, 101)), 'Обход через строковый ID.');
    researchAssert($a['Resources']->toArray() === $before, 'Списаны ресурсы.');
});

researchCase('пустая шахта: демонтаж запускается, оплачивается один раз и завершается', static function (): void {
    $a = siloFixture();
    $before = $a['Resources']->toArray();
    $price = BuildFunctions::getElementPrice(44, $a, true, 3);
    QueuesServices::AddToQueue(44, $a, 1000, false);
    $queue = Queues::getCurrentQueue('builds', 1, 101);
    researchAssert(count($queue) === 1 && $queue[0]['status'] === 'active', 'Нет активного демонтажа.');
    foreach ($price as $id => $amount) productionNear($a['Resources'][$id]['count'], $before[$id]['count'] - $amount, 'Оплата');
    QueuesServices::CompleteQueue((int)$queue[0]['id'], $a, (float)$queue[0]['end_time']);
    researchAssert($a['Builds']['silo'] === 2, 'Уровень не уменьшился.');
    researchAssert(empty(Queues::getCurrentQueue('builds', 1, 101)), 'Осталась очередь.');
});

researchCase('MySQL не вызывался', static function (): void {
    researchAssert(Database::$calls === 0, 'Тест обратился к MySQL.');
});
restore_error_handler();
echo "TOTAL silo demolition: $passed passed, $failed failed; SQL calls: " . Database::$calls . "\n";
exit($failed === 0 ? 0 : 1);
