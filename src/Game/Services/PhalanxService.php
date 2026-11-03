<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\{Logger, Message};
use SPGame\Game\Fleet\Fleet;
use SPGame\Game\Pages\FleetsPage;
use SPGame\Game\Repositories\{Builds, Fleets, PhalanxScans, Planets, PlanetResources, Users};

/** Ручной скан планеты по Nova; исполнение только в существующем EventLoop. */
final class PhalanxService
{
    public const COST = 5000;

    public static function range(int $level): int
    {
        return $level <= 0 ? 0 : ($level === 1 ? 1 : $level * $level - 1);
    }

    public static function validate(AccountData $owner, mixed $targetId): array
    {
        $source = Planets::findExistingById((int)$owner['WorkPlanet']);
        $target = is_int($targetId) && $targetId > 0 ? Planets::findExistingById($targetId) : null;
        $level = (int)(Builds::findExistingById((int)($source['id'] ?? 0))['phalanx'] ?? 0);
        if ($source === null || (int)$source['owner_id'] !== (int)$owner['User']['id']
            || (int)$source['planet_type'] !== 3 || $level <= 0) {
            throw new \InvalidArgumentException('Для сканирования выберите свою луну с Сенсорной фалангой.');
        }
        if ($target === null || (int)$target['planet_type'] !== 1 || (int)$target['owner_id'] <= 0
            || (int)$target['galaxy'] !== (int)$source['galaxy']
            || abs((int)$source['system'] - (int)$target['system']) > self::range($level)) {
            throw new \InvalidArgumentException('Планета недоступна для сканирования или вне дальности фаланги.');
        }
        FleetBattleService::assertPlanetAvailable((int)$source['id']);
        if ((float)$owner['Resources'][903]['count'] < self::COST) {
            throw new \LogicException('Для сканирования требуется 5 000 дейтерия на выбранной луне.');
        }
        return $target;
    }

    public static function scan(AccountData $owner, int $targetId, int $eventId, string $token, ?float $now = null): array
    {
        $now ??= microtime(true);
        $userId = (int)$owner['User']['id'];
        $previous = PhalanxScans::get($userId, $now);
        if (($previous['eventId'] ?? null) === $eventId) return $previous;
        $target = self::validate($owner, $targetId);
        $events = [];
        $page = new FleetsPage(new Message());
        $owners = [];
        foreach (Fleets::getAll() ?? [] as $row) {
            if ((int)$row['status'] !== 0) $owners[(int)$row['owner_id']] = true;
        }
        foreach (array_keys($owners) as $ownerId) {
            $user = Users::findExistingById($ownerId);
            if ($user === null || (int)$user['account_id'] <= 0
                || Planets::findExistingById((int)$user['current_planet']) === null) continue;
            $account = new AccountData((int)$user['account_id']);
            foreach ($page->buildPhalanxFleetList($account, $target, (int)($owner['Techs']['spy_tech'] ?? 0)) as $id => $event) {
                $events[$id] = $event;
            }
        }
        uasort($events, static fn($a, $b) => ($a['arrivesAt'] ?? PHP_FLOAT_MAX) <=> ($b['arrivesAt'] ?? PHP_FLOAT_MAX));
        $scan = ['eventId' => $eventId, 'token' => $token, 'sourceId' => (int)$owner['WorkPlanet'],
            'targetId' => $targetId,
            'targetName' => CelestialNameGenerator::planetDisplayName($target, (string)($owner['Account']['lang'] ?? 'ru')),
            'scannedAt' => $now,
            'coordinates' => ['type' => 1, 'galaxy' => (int)$target['galaxy'], 'system' => (int)$target['system'],
                'orbit' => (int)$target['planet'], 'objectType' => 1], 'cost' => self::COST, 'fleets' => array_values($events)];
        $observations = [];
        foreach ($events as $id => $_) {
            $observation = FleetInterceptionService::captureObserved(new Fleet((int)$id), $now, true);
            if ($observation !== null) $observations[(int)$id] = $observation;
        }
        foreach ($scan['fleets'] as &$event) {
            $row = \SPGame\Game\Repositories\Fleets::getById((int)$event['id']);
            $active = \SPGame\Game\Repositories\FleetCommands::getActiveCommand((int)$event['id']);
            $event['interceptable'] = $row !== null && (int)$row['owner_id'] !== $userId
                && isset($observations[(int)$event['id']]) && (int)($active['command_type'] ?? 0) === 10;
        }
        unset($event);
        // В том же атомарном буфере и в пределах прежнего лимита, но НЕ в Page DTO.
        $packed = PhalanxScans::pack($scan + ['_observations' => $observations], $now); // Переполнение — до оплаты.
        $before = PlanetResources::findExistingById((int)$owner['WorkPlanet']);
        $oldResult = PhalanxScans::row($userId);
        $oldCount = (float)$owner['Resources'][903]['count'];
        try {
            PlanetResources::update(['id' => (int)$owner['WorkPlanet'], 'deuterium' => $oldCount - self::COST]);
            if (abs(PlanetResources::findExistingById((int)$owner['WorkPlanet'])['deuterium'] - ($oldCount - self::COST)) > .000001) {
                throw new \RuntimeException('Не сохранилась оплата сканирования.');
            }
            PhalanxScans::put($userId, $packed);
            $owner['Resources'][903]['count'] = $oldCount - self::COST;
        } catch (\Throwable $error) {
            try {
                PlanetResources::update($before);
                PhalanxScans::restore($userId, $oldResult);
                if (PlanetResources::findExistingById((int)$owner['WorkPlanet']) !== $before) throw new \RuntimeException('Не восстановлен дейтерий луны.');
                $owner['Resources'][903]['count'] = $oldCount;
            } catch (\Throwable $rollback) {
                Logger::getInstance()->error('CRITICAL: rollback сканирования фаланги не выполнен',
                    ['originalMessage' => $error->getMessage(), 'message' => $rollback->getMessage()]);
            }
            throw $error;
        }
        return $scan;
    }
}
