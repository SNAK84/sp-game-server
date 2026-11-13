<?php
declare(strict_types=1);

use SPGame\Core\Message;
use SPGame\Game\Enums\FleetCommandType;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{Accounts, Users, Fleets, FleetCommands};
use SPGame\Game\Services\{AccountData, FleetsData, FleetControlService as Control, FleetFlightService, FleetCommandProjection, FleetPlanService, FleetCommandsService};

/** Только временный вызов из bootstrap основного dev после загрузки репозиториев.
 * Не самостоятельный/изолированный мир. Все пробные записи QA откатываются
 * до запуска сетевых handlers/saver. В штатном main.php этого вызова нет.
 */
return static function (int $fleetId, string $output): void {
    if (file_exists($output)) return;
    $fleet = FleetsData::getInstance()->get($fleetId);
    if ($fleet === null) throw new LogicException('Нет QA-флота.');
    $user = Users::findExistingById($fleet->getOwnerId());
    $account = Accounts::findExistingById((int)$user['account_id']);
    if (($account['login'] ?? null) !== 'qaown_260924') throw new LogicException('Probe только для выделенного QA.');
    $owner = new AccountData((int)$user['account_id']);
    $owner['WorkPlanet'] = $fleet->getHomePlanetId();
    $original = FleetCommands::getFleetCommandChain($fleetId);
    $fleetBefore = Fleets::findExistingById($fleetId);
    if (count($original) < 64) throw new LogicException('Нужна настоящая длинная история QA-рейса.');
    $checks = 0; $footprints = [];
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new RuntimeException('QA undo: ' . $label);
        ++$checks;
    };
    $same = static function () use ($fleetId, $original, $fleetBefore, $check): void {
        $check(FleetCommands::getFleetCommandChain($fleetId) === $original, 'восстановление всей цепочки');
        $check(Fleets::findExistingById($fleetId) === $fleetBefore, 'восстановление Fleet');
        $check(FleetCommands::getActiveCommand($fleetId) ===
            (array_values(array_filter($original, static fn($r) => !$r['executed'] && $r['execute_time'] > 0))[0] ?? null), 'ACTIVE после rollback');
    };
    $first = $original[0]; $second = $original[1];
    $fail = static function (): never { throw new LogicException('QA_UNDO_EXPECTED'); };
    try {
        $view = FleetCommands::view($fleetId);
        $check(iterator_to_array($view) === $original, 'ID-view равен полному снимку');
        $version = new ReflectionMethod(Control::class, 'commandVersion');
        $check($version->invoke(null, $view) === $version->invoke(null, $original), 'version без тел истории');
        $check(Control::routeProgress($fleet, $view) === Control::routeProgress($fleet, $original), 'прогресс view/array');
        $check(FleetPlanService::steps($fleet, $view) === FleetPlanService::steps($fleet, $original), 'редактор view/array');

        foreach (['nested-success', 'nested-failure', 'failed-write', 'delete-all'] as $case) {
            $measure = FleetCommands::beginChanges([$fleetId]);
            try {
                Control::commit([$fleet], static function () use ($case, $fleet, $fleetId, $first, $second, $fail, $check): void {
                    if ($case === 'delete-all') {
                        FleetCommands::clearFleetQueue($fleetId);
                        Control::add($fleetId, FleetCommandType::OBSERVE, []);
                        $fail();
                    }
                    Control::patchCommand((int)$first['id'], ['create_time' => $first['create_time'] + 1]);
                    if ($case === 'nested-success') {
                        Control::commit([$fleet], static function () use ($fleetId, $second): void {
                            Control::patchCommand((int)$second['id'], ['create_time' => $second['create_time'] + 1]);
                            Control::add($fleetId, FleetCommandType::OBSERVE, []);
                        });
                    } elseif ($case === 'nested-failure') {
                        try {
                            Control::commit([$fleet], static function () use ($first, $second, $fail): void {
                                Control::patchCommand((int)$first['id'], ['create_time' => $first['create_time'] + 2]);
                                FleetCommands::delete(['id' => (int)$second['id']]);
                                $fail();
                            });
                        } catch (LogicException $error) { if ($error->getMessage() !== 'QA_UNDO_EXPECTED') throw $error; }
                        $check(FleetCommands::getCommand((int)$first['id'])['create_time'] === $first['create_time'] + 1, 'child rollback сохраняет parent');
                        $check(FleetCommands::getCommand((int)$second['id']) === $second, 'child вернул удалённую строку');
                    } else {
                        Control::patchCommand((int)$second['id'], ['create_time' => $second['create_time'] + 1]);
                        Control::patchCommand((int)$first['id'], ['params' => str_repeat('x', 2049)]);
                    }
                    $fail();
                });
                throw new RuntimeException('Ожидаемая ошибка не возникла.');
            } catch (Throwable $error) {
                $check($case === 'failed-write'
                    ? $error instanceof RuntimeException && str_contains($error->getMessage(), 'runtime строку FleetCommands')
                    : $error instanceof LogicException && $error->getMessage() === 'QA_UNDO_EXPECTED', 'ожидаемая ошибка');
            } finally { $changed = FleetCommands::endChanges($measure); }
            $footprints[$case] = count($changed);
            $check(count($changed) === ($case === 'delete-all' ? count($original) + 1 : ($case === 'nested-success' ? 3 : 2)), 'undo только затронутых строк ' . $case);
            $same();
        }

        $active = FleetCommands::getActiveCommand($fleetId);
        if ($active === null) throw new LogicException('Нужен удерживающийся QA-флот.');
        $measure = FleetCommands::beginChanges([$fleetId]);
        try {
            Control::commit([$fleet], static function () use ($fleet, $owner, $active, $check, $fail): void {
                $fleet->setFuelCurrent(0.0); $fleet->save();
                try {
                    (new ReflectionMethod(FleetCommandsService::class, 'commitRecallReturn'))
                        ->invoke(null, $fleet, $owner, $active, $fleet->getCurrentPositionTime(), true);
                    throw new RuntimeException('Отзыв без топлива не отклонён.');
                } catch (LogicException $error) {
                    $check($error->getMessage() === 'Недостаточно топлива для возврата.', 'отказ настоящего отзыва');
                }
                $fail();
            });
        } catch (LogicException $error) { $check($error->getMessage() === 'QA_UNDO_EXPECTED', 'внешний rollback отзыва'); }
        finally { $changes = FleetCommands::endChanges($measure); }
        $footprints['recall-fuel-failure'] = count($changes);
        $check(count($changes) < count($original), 'отзыв не копирует выполненную историю');
        $same();

        $page = new FleetsPage(new Message());
        $method = new ReflectionMethod(FleetsPage::class, 'buildFlightCommandProjection');
        $projection = $method->invoke($page, $fleet, $owner, $view, new FleetFlightService(), 1);
        $all = [];
        for ($number = 1; $number <= $projection['commands_page']['pages']; ++$number) {
            $part = $method->invoke($page, $fleet, $owner, $view, new FleetFlightService(), $number);
            $check(count($part['commands']) <= 50, 'страница ограничена');
            array_push($all, ...$part['commands']);
        }
        $check(count($all) === $projection['commands_total'], 'вся история доступна по страницам');
        $creates = $steps = [];
        foreach ($original as $row) { $creates[$row['id']] = $row['create_time']; $steps[$row['id']] = Control::params($row)['planStepId'] ?? null; }
        $buffer = new FleetCommandProjection(null, $fleet->isReturn(), $fleet->getStartCoordinate()->toArray(), $fleet->getTargetCoordinate()->toArray());
        foreach ($all as $row) $buffer->add($row, $creates[$row['id']] ?? 0, $steps[$row['id']] ?? null);
        $reference = (new ReflectionMethod(FleetsPage::class, 'buildRouteLegProjection'))->invoke($page, $fleet, $all, $creates, $steps);
        $check($buffer->result()['route_leg'] === $reference, 'новое плечо совпало с прежним алгоритмом');
        $same();
        $check((new ReflectionProperty(FleetCommands::class, 'changeFrames'))->getValue() === [], 'undo-журналы освобождены');
        $result = ['ok' => true, 'checks' => $checks, 'historyRows' => count($original), 'footprints' => $footprints, 'restored' => true];
    } catch (Throwable $error) {
        $result = ['ok' => false, 'checks' => $checks, 'footprints' => $footprints, 'error' => $error->getMessage()];
    }
    $handle = fopen($output, 'x');
    if ($handle === false) throw new RuntimeException('Не создан результат QA undo.');
    chmod($output, 0600);
    try { fwrite($handle, json_encode($result, JSON_THROW_ON_ERROR)); fflush($handle); }
    finally { fclose($handle); }
    if (!$result['ok']) throw new RuntimeException('Основной QA undo-probe не пройден.');
};
