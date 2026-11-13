<?php
declare(strict_types=1);

use SPGame\Game\Enums\FleetCommandType as Command;
use SPGame\Game\Repositories\{Accounts, Users, Fleets, FleetCommands, Config};
use SPGame\Game\Services\{FleetControlService as Control, FleetCommandsService};

/** Временный bootstrap основного dev, до сети/saver. Только QA-команды;
 * отдельный мир не создаётся, пробные строки/markers всегда убираются.
 */
return static function (int $fleetId, string $output): void {
    if (file_exists($output)) return;
    $fleet = Fleets::findExistingById($fleetId);
    $user = $fleet === null ? null : Users::findExistingById((int)$fleet['owner_id']);
    $account = $user === null ? null : Accounts::findExistingById((int)$user['account_id']);
    if (($account['login'] ?? null) !== 'qaown_260924' || (int)$fleet['status'] !== 0
        || count(FleetCommands::view($fleetId)) !== 0) throw new LogicException('Нужен свободный QA-флот.');
    $before = FleetCommands::getAll();
    $sync = (new ReflectionProperty(FleetCommands::class, 'syncTable'))->getValue();
    $syncBefore = iterator_to_array($sync);
    unset($syncBefore['meta']); // Выданные ID намеренно не переиспользуем.
    $resolve = new ReflectionMethod(FleetCommandsService::class, 'recallSpeedFactor');
    $ids = []; $checks = 0; $failure = null;
    $journal = FleetCommands::beginChanges([$fleetId]);
    $add = static function (Command $type, array $params, float $end = 0, bool $done = false) use ($fleetId, &$ids): array {
        $row = FleetCommands::addCommand($fleetId, $type, $params);
        $ids[] = (int)$row['id'];
        FleetCommands::update(['id' => $row['id'], 'execute_time' => $end, 'executed' => (int)$done]);
        return FleetCommands::getCommand((int)$row['id']);
    };
    $patch = static function (array $row, array $params): array {
        Control::patchCommand((int)$row['id'], ['params' => json_encode($params, JSON_THROW_ON_ERROR)]);
        return FleetCommands::getCommand((int)$row['id']);
    };
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new RuntimeException('B073: ' . $label);
        ++$checks;
    };
    try {
        $now = microtime(true);
        $sourceParams = ['speedFactor' => 7, 'cancelled' => true, 'startedAt' => $now - 10];
        $source = $add(Command::MOVE_IN_SYSTEM, $sourceParams, $now + 120, true);
        $add(Command::MOVE_IN_SYSTEM, ['speedFactor' => 2, 'cancelled' => true, 'returnLeg' => true], 0, true);
        $holdParams = ['hold' => ['startedAt' => $now, 'lastAt' => $now, 'rate' => .001]];
        $hold = $add(Command::OBSERVE, $holdParams, $now + 500);
        $next = $add(Command::MOVE_IN_SYSTEM, ['speedFactor' => 3]);
        $check($resolve->invoke(null, $hold) === 7.0, 'Остановленная MOVE важнее отменённого возврата и нового плана.');
        $changed = $patch($hold, $holdParams + ['speedFactor' => 5]);
        $check($resolve->invoke(null, $changed) === 5.0, 'Явная скорость приоритетна.');
        $changed = $patch($hold, $holdParams + ['speedFactor' => null]);
        $check($resolve->invoke(null, $changed) === 7.0, 'Legacy null считается отсутствующей скоростью.');
        $changed = $patch($hold, ['hold' => ['startedAt' => $now + 1000, 'lastAt' => $now + 1000]]);
        $check($resolve->invoke(null, $changed) === 7.0, 'Перезапуск clock при слиянии не теряет исходную скорость.');
        $hold = $patch($hold, $holdParams);
        $source = $patch($source, ['speedFactor' => 7, 'startedAt' => $now - 200, 'cancelled' => true]);
        Control::patchCommand((int)$source['id'], ['execute_time' => $now - 100]);
        $check($resolve->invoke(null, $hold) === 3.0, 'После завершённого перехода берётся оставшийся участок.');
        Control::patchCommand((int)$next['id'], ['executed' => 1]);
        $check($resolve->invoke(null, $hold) === 7.0, 'Без будущего участка берётся последний начатый.');
        $patch($source, ['speedFactor' => 7, 'cancelled' => true]);
        $default = (float)(Config::getValue('FleetSpeedFactor') ?? 1.0);
        $check($resolve->invoke(null, $hold) === $default, 'Без доказательств сохраняется общий default.');
        foreach ([1, 5, 10, '7.5'] as $speed) {
            $row = $patch($hold, $holdParams + ['speedFactor' => $speed]);
            $check($resolve->invoke(null, $row) === (float)$speed, '10/50/100% и прежний numeric-string контракт.');
        }
        $invalid = $patch($hold, $holdParams + ['speedFactor' => 0]);
        try {
            $resolve->invoke(null, $invalid);
            throw new LogicException('Невалидная скорость принята.');
        } catch (InvalidArgumentException $error) { ++$checks; }
        $other = $add(Command::RESEARCH, []);
        $check($resolve->invoke(null, $other) === $default, 'Default других действий не меняется.');
    } catch (Throwable $error) { $failure = $error; }
    finally {
        FleetCommands::rollbackChanges(FleetCommands::endChanges($journal));
        foreach ($ids as $id) if ($sync->exists((string)$id) && !$sync->del((string)$id)) throw new RuntimeException('Не убран QA marker.');
    }
    $check(FleetCommands::getAll() === $before, 'Исходные команды мира восстановлены точно.');
    $syncAfter = iterator_to_array($sync);
    unset($syncAfter['meta']);
    ksort($syncBefore); ksort($syncAfter);
    $check($syncAfter === $syncBefore, 'Чужие dirty markers не изменены.');
    $check(Fleets::findExistingById($fleetId) === $fleet, 'Fleet не изменён.');
    file_put_contents($output, json_encode(['ok' => $failure === null, 'checks' => $checks,
        'qaCommandsRemoved' => count($ids), 'error' => $failure?->getMessage()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
};
