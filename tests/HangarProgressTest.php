<?php

declare(strict_types=1);

// Используем существующие изолированные Table/AccountData doubles.
require __DIR__ . '/FleetTransportTest.php';

use SPGame\Core\Message;
use SPGame\Game\Pages\HangarPage;
use SPGame\Game\Repositories\{Config, Queues, Vars};
use SPGame\Game\Services\{BuildFunctions, Data, Helpers, QueuesServices};

function hangarProgressFixture(): TransportAccountData
{
    $account = installTransportFixture()['account'];
    Vars::$resource += [6 => 'techno', 14 => 'robot_factory', 15 => 'nano_factory', 21 => 'hangar', 401 => 'launcher'];
    Vars::$reslist += ['build' => [6, 14, 15, 21], 'tech' => [], 'defense' => [401],
        'missile' => [], 'one' => [], 'bonus' => [], 'officier' => [], 'ressources' => [901, 902, 903]];
    Vars::$requirement = [];
    Vars::$pricelist = [202 => [901 => 100, 902 => 50], 401 => [901 => 100]];
    Vars::$attributes[202]['factor'] = 1;
    Vars::$attributes[401] = ['factor' => 1];
    $account['Builds'] = ['techno' => 0, 'robot_factory' => 0, 'nano_factory' => 0, 'hangar' => 1];
    $account['Ships'] = ['small_cargo' => 0];
    $account['Defenses'] = ['launcher' => 0];
    foreach ([901, 902, 903] as $id) $account['Resources'][$id]['count'] = 100000;
    $resources = &$account['Resources'];
    $dirty = false;
    $resources = new Data($resources, $dirty);

    $config = [];
    foreach (['GameSpeed' => 2500, 'FactorsUniversity' => 0, 'MinBuildTime' => 1,
        'MaxFleetPerBuild' => 10, 'MaxQueueHangar' => 10] as $key => $value) {
        $config[] = ['id' => count($config) + 1, 'name' => $key, 'value' => (string)$value];
    }
    installTransportRepository(Config::class, transportTable($config), transportSyncTable(count($config)),
        ['name' => uniqueTransportIndex(array_column($config, 'id', 'name'))]);
    $indexes = [];
    foreach (['queue', 'queue_tech', 'queue_p_status', 'queue_u_status', 'queue_p_type_status'] as $key) {
        $indexes[$key] = transportTable();
    }
    installTransportRepository(Queues::class, transportTable(), transportSyncTable(), $indexes);
    return $account;
}

function hangarProgressRows(TransportAccountData $account): array
{
    return Queues::getCurrentQueue(QueuesServices::HANGARS, $account['User']['id'], $account['Planet']['id']) ?: [];
}

function hangarProgressTick(float $time, TransportAccountData $account): void
{
    (new ReflectionMethod(Helpers::class, 'processHangar'))->invoke(null, $time, $account);
}

foreach ([202, 401] as $element) {
    $account = hangarProgressFixture();
    $time = BuildFunctions::getBuildingTime($element, $account);
    QueuesServices::AddToQueueHangar([$element => 5], $account, 1000.0);
    $queue = hangarProgressRows($account)[0];
    transportAssert($queue['count'] === 5 && $queue['total_count'] === 5, 'Новая партия должна сохранять исходное количество.');

    hangarProgressTick(1000 + 2.5 * $time, $account);
    $queue = hangarProgressRows($account)[0];
    transportAssert($queue['count'] === 3 && $queue['total_count'] === 5, 'Выпуск единиц не должен уменьшать total_count.');
    transportAssert(abs($queue['start_time'] - (1000 + 2 * $time)) < 0.000001, 'Опорное время оставшихся единиц сдвигается как раньше.');
    transportAssert(BuildFunctions::getElementLevel($element, $account) === 2, 'Выпущены ровно две единицы.');
    $method = new ReflectionMethod(HangarPage::class, 'buildQueueList');
    $snapshot = $method->invoke(new HangarPage(new Message()), [$queue]);
    transportAssert($snapshot[$queue['id']]['count'] === 3 && $snapshot[$queue['id']]['total_count'] === 5,
        'Snapshot должен содержать и остаток, и исходную партию.');

    // Дозаказ объединяется с остатком, но учитывает уже выпущенные единицы.
    QueuesServices::AddToQueueHangar([$element => 2], $account, 1000 + 2.5 * $time);
    $queue = hangarProgressRows($account)[0];
    transportAssert($queue['count'] === 5 && $queue['total_count'] === 7 && count(hangarProgressRows($account)) === 1,
        'Объединение должно увеличивать total_count на фактически добавленное количество.');
    hangarProgressTick(1000 + 3.5 * $time, $account);
    $queue = hangarProgressRows($account)[0];
    transportAssert($queue['count'] === 4 && $queue['total_count'] === 7, 'Повторный выпуск должен сохранить полный размер объединённой партии.');
    // Сериализация schema-строки имитирует reload, без клиентского cache.
    $restored = Queues::castRowToSchema(json_decode(json_encode($queue), true), true);
    transportAssert($restored['total_count'] === 7 && $restored['count'] === 4, 'total_count должен входить в сохраняемую схему.');

    $beforeCancel = $account['Resources'][901]['count'];
    QueuesServices::CancelToQueueHangar($queue['id'], $account, 1000 + 3.5 * $time);
    transportAssert(hangarProgressRows($account) === [], 'Отмена удаляет очередь.');
    transportAssert(abs($account['Resources'][901]['count'] - $beforeCancel - 350) < 0.000001,
        'Возврат должен учитывать оставшиеся 3.5 единицы, а не total_count=7.');
    echo "OK hangar {$element}: create, partial production, snapshot, merge, schema reload, cancel\n";
}

$account = hangarProgressFixture();
QueuesServices::AddToQueueHangar([202 => 5], $account, 1000.0);
$queue = hangarProgressRows($account)[0];
$queue['total_count'] = 0; // Ранее существовавшая запись после добавления колонки.
Queues::update($queue);
$time = BuildFunctions::getBuildingTime(202, $account);
hangarProgressTick(1000 + 2.5 * $time, $account);
$queue = hangarProgressRows($account)[0];
transportAssert($queue['count'] === 3 && $queue['total_count'] === 5, 'Legacy baseline фиксируется до уменьшения остатка.');
hangarProgressTick(1000 + 3.5 * $time, $account);
transportAssert(hangarProgressRows($account)[0]['total_count'] === 5, 'Legacy baseline не должен сдвигаться при следующем тике.');
hangarProgressTick(1000 + 5 * $time, $account);
transportAssert(hangarProgressRows($account) === [] && BuildFunctions::getElementLevel(202, $account) === 5,
    'Партия полностью завершается без повторного выпуска.');
echo "OK hangar legacy initialization and full completion\n";

$account = hangarProgressFixture();
QueuesServices::AddToQueueHangar([202 => 50], $account, 1000.0);
transportAssert(hangarProgressRows($account)[0]['total_count'] === 10, 'Сохраняется принятый объём, а не входные 50 сверх лимита.');
$time = BuildFunctions::getBuildingTime(202, $account);
hangarProgressTick(1000 + 2 * $time, $account);
QueuesServices::AddToQueueHangar([202 => 5], $account, 1000 + 2 * $time);
$rows = hangarProgressRows($account);
transportAssert(count($rows) === 2 && $rows[0]['count'] === 10 && $rows[0]['total_count'] === 12
    && $rows[1]['count'] === 3 && $rows[1]['total_count'] === 3,
    'При разделении по лимиту каждая строка хранит собственный размер партии.');
echo "OK hangar accepted count, capped merge and split batch\n";

$legacySnapshot = (new ReflectionMethod(HangarPage::class, 'buildQueueList'))->invoke(new HangarPage(new Message()), [
    ['id' => 99, 'object_id' => 202, 'count' => 3, 'time' => 10, 'start_time' => 1000, 'end_time' => 1030, 'status' => 'queued'],
]);
transportAssert($legacySnapshot[99]['total_count'] === 3, 'Старая запись без total_count должна иметь безопасный fallback.');
echo "OK hangar legacy snapshot fallback\n";
