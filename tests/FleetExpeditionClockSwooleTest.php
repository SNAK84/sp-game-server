<?php

declare(strict_types=1);

// Свежие native Swoole Tables. Игра и MySQL недоступны из этого процесса.
define('SP_GAME_DEFEND_FIXTURE_ONLY', true);
require __DIR__ . '/FleetDefendSwooleTest.php';

use SPGame\Core\Database;
use SPGame\Game\Enums\FleetMissionType as Mission;
use SPGame\Game\Fleet\FleetTarget;
use SPGame\Game\Repositories\{Config, FleetCommands, FleetExpeditions as States, Messages, UserResources, Vars};
use SPGame\Game\Services\{FleetCommandsService as Commands, FleetEventScheduler, FleetsData};
use SPGame\Game\Space\Coordinate;

foreach (['round-down', 'round-up', 'early-command'] as $mode) {
    researchCase('expedition clock: ' . $mode, static function () use ($mode): void {
        $owner = defendFixture();
        researchRepository(States::class, []);
        Vars::$resource[124] = 'expedition_tech'; $owner['Techs']['expedition_tech'] = 3;
        Vars::$attributes[202]['missions'][] = Mission::EXPEDITION->value;
        foreach (['resources', 'credits', 'ships', 'combat', 'lost', 'time', 'nothing'] as $event) {
            Config::add(['name' => 'ExpeditionWeight' . ucfirst($event), 'value' => $event === 'credits' ? '1' : '0']);
        }
        $fleet = FleetsData::getInstance()->get(1);
        $target = Coordinate::object(1, 1, 5);
        $travel = $fleet->getTravelInfo(FleetTarget::fromCoordinates($target->toArray()), $owner, 1, Mission::EXPEDITION);
        $creditBefore = (float)UserResources::findExistingById(1)['credit'];
        $hours = $mode === 'early-command' ? 1 : 3;
        // У round-down потеря через числовое поле составляет около двух микросекунд.
        $start = $mode === 'round-up' ? 1792557171.5656818 : 1792557171.5656018;
        Commands::startMission($fleet, $owner, 40, $target->toArray(), 1, ceil($travel['fuel']['max']), [], $start, $hours * 3600);
        Commands::completeCommand(FleetCommands::getActiveCommand(1), $fleet, $owner);
        $scheduler = new FleetEventScheduler();
        $next = static fn(float $now): ?array => FleetCommands::getPendingTimeCommands($now)[0] ?? null;
        $execute = static fn(array $command): bool => Commands::completeCommand($command, $fleet, $owner);

        for ($hour = 1; $hour <= $hours; ++$hour) {
            $state = States::state(1);
            $at = $state['startedAt'] + $hour * 3600;
            // JSON checkpoint сохраняет исходный double, числовой timestamp после reload округлён.
            $restored = $mode === 'early-command' ? $at - 5 : round($at, 4);
            FleetCommands::update(['id' => $state['commandId'], 'execute_time' => $restored]);
            $stale = FleetCommands::getActiveCommand(1);
            $credits = $fleet->getResources()[921]->getCount();
            if ($mode !== 'round-up') {
                researchAssert($restored < $at, 'Fixture не воспроизводит ранний deadline.');
                $handled = $scheduler->process($restored, $next, $execute);
                researchAssert(count($handled) === 1, 'Scheduler зациклился или пропустил восстановление deadline.');
                researchAssert(FleetCommands::getActiveCommand(1)['execute_time'] === $at, 'Не восстановлен точный deadline из checkpoint.');
                researchAssert(States::state(1) === $state && $fleet->getResources()[921]->getCount() === $credits,
                    'До полного часа изменились checkpoint или находки.');
                Commands::completeCommand($stale, $fleet, $owner);
                researchAssert(States::state(1) === $state, 'Старый snapshot применил событие до срока.');
            }
            $scheduler->process(max($at, $restored), $next, $execute);
            $after = States::state(1);
            researchAssert(count($after['events']) === $hour && $after['events'][$hour - 1]['at'] === $at,
                'Потерян час или сместилось логическое время события.');
            $reward = $after['events'][$hour - 1]['credits'];
            researchAssert($fleet->getResources()[921]->getCount() === $credits + $reward, 'Находка начислена не один раз.');
            Commands::completeCommand($stale, $fleet, $owner);
            researchAssert($fleet->getResources()[921]->getCount() === $credits + $reward, 'Повтор старой команды начислил находку ещё раз.');
            researchAssert(count(Messages::getAll() ?? []) === ($hour === $hours ? 1 : 0), 'Дублирован или преждевременно отправлен итог.');
        }
        $total = array_sum(array_column(States::state(1)['events'], 'credits'));
        for ($i = 0; $i < 5 && ($active = FleetCommands::getActiveCommand(1)) !== null; ++$i) {
            Commands::completeCommand($active, $fleet, $owner);
        }
        $owner->save();
        researchAssert($fleet->getStatus() === 0 && FleetCommands::getFleetQueue(1) === [] && States::state(1) === null,
            'Исправленная экспедиция не завершила возврат.');
        researchAssert((float)UserResources::findExistingById(1)['credit'] === $creditBefore + $total,
            'Кредиты потеряны или повторно зачислены при возвращении.');
        $messages = Messages::getAll();
        researchAssert(count($messages) === 2
            && count(array_filter($messages, static fn($row) => $row['sample'] === 'FleetExpeditionReturned')) === 1,
            'После возвращения нужны один итог и одно уведомление о прибытии.');
    });
}

restore_error_handler();
echo "RESULT expedition clock: $passed passed / $failed failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
