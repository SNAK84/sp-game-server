<?php

declare(strict_types=1);

// Только отдельный PHP-процесс: свежие Swoole Tables и запрещённый MySQL.
define('SP_GAME_RESEARCH_FIXTURE_ONLY', true);
require __DIR__ . '/ResearchResourcesIsolationTest.php';

use SPGame\Core\{Database, Message};
use SPGame\Game\Pages\{BuildingsPage, HangarPage, ResearchPage, InformationPage};
use SPGame\Game\Repositories\{Builds, Config, Defenses, PlayerQueue, Queues, Ships, Vars};
use SPGame\Game\Services\{AccountData, BuildFunctions, Helpers, QueuesServices};

function productionFixture(): AccountData
{
    $a = researchFixture();
    researchRepository(Queues::class, []);
    Queues::setLastId(100);
    Config::add(['name' => 'FactorUniversity', 'value' => '10']);
    Config::add(['name' => 'MaxQueueBuild', 'value' => '10']);
    Config::add(['name' => 'MaxQueueHangar', 'value' => '10']);
    Config::add(['name' => 'MaxFleetPerBuild', 'value' => '100']);
    Vars::$resource += [202 => 'small_cargo', 401 => 'launcher', 44 => 'silo'];
    Vars::$reslist['fleet'] = [202];
    Vars::$reslist['defense'] = [401];
    Vars::$reslist['nametype'] = ['build' => [], 'reseach' => [], 'fleet' => [], 'defense' => []];
    foreach (array_merge(Vars::$reslist['build'], Vars::$reslist['tech'], [202, 401]) as $id) {
        Vars::$attributes[$id] = ['factor' => 2, 'max' => 100, 'type' => 1];
        Vars::$pricelist[$id] = [901 => 1000, 902 => 500];
    }
    Vars::$attributes[202]['factor'] = Vars::$attributes[401]['factor'] = 1;
    researchRepository(Ships::class, [['id' => 101]], ['small_cargo']);
    researchRepository(Defenses::class, [['id' => 101]], ['launcher']);
    $a['Builds']['hangar'] = 1;
    return $a;
}

function productionQueue(int $id, int $element, int $planet = 101, string $status = 'active', float $start = 1000, float $time = 1000): array
{
    return ['id' => $id, 'user_id' => 1, 'planet_id' => $planet, 'object_id' => $element, 'count' => 1,
        'action' => 'build', 'type' => QueuesServices::QueueType($element), 'status' => $status,
        'time' => $time, 'start_time' => $start, 'end_time' => $start + $time];
}

function productionNear(float $actual, float $expected, string $message): void
{
    researchAssert(abs($actual - $expected) < 0.00001, "$message: $actual != $expected");
}

if (defined('SP_GAME_PRODUCTION_FIXTURE_ONLY') && SP_GAME_PRODUCTION_FIXTURE_ONLY) return;

researchCase('B007: buyable ниже, на и выше max с учётом будущего уровня очереди', static function (): void {
    foreach ([2 => true, 3 => false, 4 => false] as $level => $expected) {
        $a = productionFixture();
        Vars::$attributes[21]['max'] = 3;
        $a['Builds'][Vars::$resource[21]] = $level;
        $card = (new BuildingsPage(new Message()))->render($a)['BuildList'][21];
        researchAssert($card['buyable'] === $expected && !$card['working'], 'Неверная граница максимального уровня.');
    }
    $a = productionFixture();
    Vars::$attributes[21]['max'] = 3;
    $a['Builds'][Vars::$resource[21]] = 2;
    Queues::add(productionQueue(1, 21, status: 'queued'));
    $card = (new BuildingsPage(new Message()))->render($a)['BuildList'][21];
    researchAssert($card['level'] === 2 && $card['levelToBuild'] === 3 && !$card['buyable'], 'Не учтён предел уже заказанного уровня.');
});

foreach ([202, 401] as $product) {
    foreach ([15, 21] as $building) {
        researchCase("ангар $product → блокировка $building в UI и service", static function () use ($product, $building): void {
            $a = productionFixture();
            Queues::add(productionQueue(1, $product, status: 'queued'));
            $before = $a['Resources']->toArray();
            $page = (new BuildingsPage(new Message()))->render($a);
            researchAssert($page['BuildList'][$building]['working'], 'Нет блокировки карточки.');
            researchAssert(!$page['BuildList'][$building]['buyable'], 'Занятая постройка доступна для заказа.');
            QueuesServices::AddToQueue($building, $a, 1000);
            QueuesServices::AddToQueue($building, $a, 1000, false);
            researchAssert(count(Queues::getCurrentQueue('hangars', 1, 101)) === 1, 'Изменилась очередь ангара.');
            researchAssert(empty(Queues::getCurrentQueue('builds', 1, 101)), 'Принято изменение занятой постройки.');
            researchAssert($a['Resources']->toArray() === $before, 'Списаны ресурсы.');
            $a['WorkPlanet'] = 102;
            researchAssert(!QueuesServices::isElementBusy($building, $a), 'Заблокирована другая планета.');
        });
    }
}

foreach (['active', 'queued'] as $status) {
    foreach ([15, 21] as $building) {
        researchCase("постройка $building ($status) → блокировка ships/defense", static function () use ($building, $status): void {
            $a = productionFixture();
            Queues::add(productionQueue(1, $building, status: $status));
            $before = $a['Resources']->toArray();
            researchAssert((new HangarPage(new Message()))->render($a)['IsHangarBuild'], 'Нет блокировки UI.');
            QueuesServices::AddToQueueHangar([202 => 1, 401 => 1], $a, 1000);
            researchAssert(empty(Queues::getCurrentQueue('hangars', 1, 101)), 'Обойдена блокировка.');
            researchAssert($a['Resources']->toArray() === $before, 'Списаны ресурсы.');
        });
    }
}

researchCase('busy activation: queued-верфь не активируется при queued-ангаре', static function (): void {
    $a = productionFixture();
    Queues::add(productionQueue(1, 202, status: 'queued'));
    Queues::add(productionQueue(2, 21, status: 'queued'));
    $pending = [Queues::findExistingById(2)];
    $before = $a['Resources']->toArray();
    researchAssert(!QueuesServices::activateNextQueueItem($pending, $a, 1000), 'Активирована занятая верфь.');
    researchAssert(Queues::findExistingById(2) === null, 'Занятая запись осталась.');
    researchAssert($a['Resources']->toArray() === $before, 'Произошло списание.');
});

foreach ([6, 31] as $building) {
    researchCase("лаборатория/Технополис $building ↔ исследование", static function () use ($building): void {
        $a = productionFixture();
        Queues::add(productionQueue(1, 113));
        researchAssert(QueuesServices::isElementBusy($building, $a), 'Не заблокирована постройка.');
        QueuesServices::AddToQueue($building, $a, 1000);
        researchAssert(empty(Queues::getCurrentQueue('builds', 1, 101)), 'Принята занятая постройка.');
        Queues::delete(Queues::findExistingById(1));
        Queues::add(productionQueue(2, $building, status: 'queued'));
        QueuesServices::AddToQueue(113, $a, 1000);
        researchAssert(empty(Queues::getCurrentQueue('techs', 1, 101)), 'Принято исследование при стройке.');
        researchAssert((new ResearchPage(new Message()))->render($a)['IsLabinBuild'], 'UI не видит занятую лабораторию.');
    });
}

researchCase('освобождение блокировки и обычный запуск производства', static function (): void {
    $a = productionFixture();
    Queues::add(productionQueue(1, 21));
    QueuesServices::AddToQueueHangar([202 => 1], $a, 1000);
    Queues::delete(Queues::findExistingById(1));
    $before = (float)$a['Resources'][901]['count'];
    QueuesServices::AddToQueueHangar([202 => 1], $a, 1000);
    researchAssert(count(Queues::getCurrentQueue('hangars', 1, 101)) === 1, 'Производство не возобновилось.');
    productionNear($a['Resources'][901]['count'], $before - 1000, 'Списание один раз');
});

researchCase('Технополис: 10% за уровень и таблица Инфо совпадают', static function (): void {
    $a = productionFixture();
    $a['Builds']['university'] = 0;
    $base = BuildFunctions::getBuildingTime(113, $a);
    $a['Builds']['university'] = 2;
    productionNear(BuildFunctions::getBuildingTime(113, $a), $base * 0.81, 'Бонус Технополиса');
    $info = (new InformationPage((new Message())->setData('id', 6)))->render($a);
    researchAssert(in_array('researchTime', array_column($info['effects'] ?? [], 'key'), true), 'Нет таблицы эффекта Технополиса.');
});

researchCase('сеть: локальный cache, N дополнительных лучших labs, порог конкретного исследования', static function (): void {
    $a = productionFixture();
    $a['Builds']['laboratory'] = 2;
    $a['WorkPlanet'] = 102; $a['Builds']['laboratory'] = 8;
    $a['WorkPlanet'] = 103; $a['Builds']['laboratory'] = 4;
    $a['WorkPlanet'] = 101;
    researchAssert(Helpers::getNetworkLevels($a) === [2], 'При сети 0 подключена чужая лаборатория или старый локальный уровень.');
    $a['Techs']['intergalactic_tech'] = 1;
    researchAssert(Helpers::getNetworkLevels($a) === [2, 8], 'Не выбрана лучшая дополнительная лаборатория.');
    Vars::$requirement[113][31] = 5;
    productionNear(BuildFunctions::getBuildingTime(113, $a, [901 => 1000, 902 => 500]), 5400 / 9, 'Не отфильтрованы неподходящие уровни');
    Queues::add(productionQueue(1, 31, 102));
    researchAssert(Helpers::getNetworkLevels($a) === [2, 4], 'Занятая лаборатория участвует в сети.');
    Queues::delete(Queues::findExistingById(1));
    researchAssert(Helpers::getNetworkLevels($a) === [2, 8], 'Лаборатория не вернулась в сеть.');
});

researchCase('перерасчёт: 50% прогресса, повтор без дрейфа и повторная смена скорости', static function (): void {
    $a = productionFixture();
    Queues::add(productionQueue(1, 113, time: 2700));
    $a['Builds']['university'] = 1;
    QueuesServices::ReCalcTimeQueue('techs', $a, 2350);
    $q = Queues::findExistingById(1);
    productionNear($q['time'], 2430, 'Новая длительность');
    productionNear($q['end_time'], 3565, 'Осталось 50% новой длительности');
    QueuesServices::ReCalcTimeQueue('techs', $a, 2450);
    productionNear(Queues::findExistingById(1)['end_time'], 3565, 'Дрейф повторного пересчёта');
    $a['Builds']['university'] = 2;
    QueuesServices::ReCalcTimeQueue('techs', $a, 2450);
    productionNear(Queues::findExistingById(1)['end_time'], 2450 + (3565 - 2450) * 0.9, 'Вторая смена скорости');
});

researchCase('формула Nova: стоимость, GameSpeed, Factors и минимальное время', static function (): void {
    $a = productionFixture();
    $a['Builds']['laboratory'] = 4;
    $a['Builds']['university'] = 2;
    $a['Techs']['energy_tech'] = 2;
    Vars::$reslist['bonus'] = [113];
    Vars::$bonus[113] = array_fill_keys(Vars::$bonusList, [0, 0]);
    Vars::$bonus[113]['ResearchTime'] = [-0.05, 0];
    $speed = Config::findByIndex('name', 'GameSpeed');
    Config::update(['id' => $speed['id'], 'value' => '5000']);
    productionNear(BuildFunctions::getBuildingTime(113, $a, [901 => 1000, 902 => 500, 903 => 999999]),
        1500 / (1000 * 5) / 2 * 0.81 * 0.9 * 3600, 'Формула времени');
    productionNear(BuildFunctions::getBuildingTime(113, $a, [901 => 0, 902 => 0]), 1, 'MinBuildTime');
});

researchCase('начало/отмена удалённой лаборатории: замедление и возврат скорости без потери прогресса', static function (): void {
    $a = productionFixture();
    $a['WorkPlanet'] = 103; $a['Builds']['laboratory'] = 0;
    $a['WorkPlanet'] = 101;
    $a['Techs']['intergalactic_tech'] = 1;
    Queues::add(productionQueue(1, 113, time: 1800));
    $a['WorkPlanet'] = 102;
    QueuesServices::AddToQueue(31, $a, 1100);
    $builds = Queues::getCurrentQueue('builds', 1, 102);
    researchAssert(count($builds) === 1, 'Нельзя улучшить удалённую лабораторию.');
    QueuesServices::ReCalcTimeQueue('techs', $a, 1100);
    productionNear(Queues::findExistingById(1)['end_time'], 3650, 'Сеть не исключила лабораторию');
    QueuesServices::CancelToQueue((int)$builds[0]['id'], $a, 1200);
    QueuesServices::ReCalcTimeQueue('techs', $a, 1200);
    productionNear(Queues::findExistingById(1)['end_time'], 1200 + (3650 - 1200) * 2 / 3, 'Не восстановилась скорость');
});

researchCase('требования research snapshot берутся с выбранной планеты, не из глобальной очереди', static function (): void {
    $a = productionFixture();
    $a['Builds']['laboratory'] = 5;
    Vars::$requirement[113][31] = 3;
    $page = new ResearchPage(new Message());
    researchAssert($page->render($a)['ResearchList'][113]['requirements'] === [], 'Доступная технология требует лабораторию.');
    $a['WorkPlanet'] = 102;
    $req = $page->render($a)['ResearchList'][113]['requirements'][31];
    researchAssert($req['own'] === 1 && $req['count'] === 3, 'Переданы уровни другой планеты.');
    $a['WorkPlanet'] = 101;
    researchAssert($page->render($a)['ResearchList'][113]['requirements'] === [], 'Не очистились требования.');
});

researchCase('сеть не ускоряет собственное исследование; следующие используют новый уровень', static function (): void {
    $a = productionFixture();
    $a['WorkPlanet'] = 102; $a['Builds']['laboratory'] = 5;
    $a['WorkPlanet'] = 101;
    Queues::add(productionQueue(1, 123, time: 2700));
    Queues::add(productionQueue(2, 113, status: 'queued', start: 3700));
    QueuesServices::ReCalcTimeQueue('techs', $a, 1000);
    productionNear(Queues::findExistingById(1)['time'], 2700, 'Сеть ускорила себя');
    productionNear(Queues::findExistingById(2)['time'], 5400 / 7, 'Следующее исследование не учитывает сеть');
    researchAssert($a['Techs']['intergalactic_tech'] === 0, 'Прогноз изменил реальные технологии.');
});

researchCase('общий перерасчёт построек: фабрика не ускоряет себя, но ускоряет следующий элемент', static function (): void {
    $a = productionFixture();
    Queues::add(productionQueue(1, 14, time: 2160));
    Queues::add(productionQueue(2, 21, status: 'queued', start: 3160));
    QueuesServices::ReCalcTimeQueue('builds', $a, 1000);
    productionNear(Queues::findExistingById(1)['time'], 2160, 'Фабрика ускорила себя');
    productionNear(Queues::findExistingById(2)['time'], 2160, 'Не учтено ускорение следующего элемента');
    researchAssert($a['Builds']['robot_factory'] === 0, 'Прогноз изменил постройки.');
});

researchCase('завершение удалённой лаборатории ускоряет активное исследование до save()', static function (): void {
    $a = productionFixture();
    $a['Techs']['intergalactic_tech'] = 1;
    Queues::add(productionQueue(1, 113, time: 2700));
    Queues::add(productionQueue(2, 31, 102, start: 1000, time: 100));
    QueuesServices::CompleteQueue(2, $a, 1100);
    // До завершения удалённая лаборатория исключена; после — 1 + 2 уровней.
    productionNear(Queues::findExistingById(1)['time'], 1350, 'Не учтён новый уровень до save');
    productionNear(Queues::findExistingById(1)['end_time'], 2400, 'Не сохранён прогресс');
});

researchCase('enqueue на другой планете: локальный Технополис, а не последняя планета очереди', static function (): void {
    $a = productionFixture();
    Queues::add(productionQueue(1, 113, planet: 102, time: 2700));
    $a['Builds']['university'] = 2;
    QueuesServices::AddToQueue(123, $a, 1000);
    $rows = Queues::getCurrentQueue('techs', 1, 101);
    researchAssert(count($rows) === 2, 'Не добавлено исследование.');
    productionNear($rows[1]['time'], 2700 * 0.81, 'Выбран Технополис другой планеты');
    researchAssert((int)$rows[1]['planet_id'] === 101, 'Неверный источник оплаты.');
});

researchCase('MySQL не вызывался', static function (): void {
    researchAssert(Database::$calls === 0, 'Был запрос в MySQL.');
});
restore_error_handler();
echo "TOTAL production/research: $passed passed, $failed failed; SQL calls: " . Database::$calls . "\n";
exit($failed === 0 ? 0 : 1);
