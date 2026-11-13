<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Users;

/** Ресурсная проекция планет, без навигации и изменения current_planet. */
final class ProtocolReadService
{
    public static function planets(int $accountId, ?string $selectedId): array
    {
        $account = Accounts::findExistingById($accountId);
        $user = Users::findByIndex('account', (string)$accountId);
        if (!$account || !$user) throw new \RuntimeException('Игрок не найден.');
        $items = [];
        foreach (Planets::getPlanetsList($user) as $planet) {
            $items[] = [
                'id' => (string)$planet['id'],
                'name' => CelestialNameGenerator::planetDisplayName($planet, $account['lang']),
                'objectType' => (int)$planet['planet_type'],
                'image' => (string)$planet['image'],
                'coordinate' => ['galaxy' => (int)$planet['galaxy'], 'system' => (int)$planet['system'], 'orbit' => (int)$planet['planet']],
            ];
        }
        $selectedId ??= (string)$user['current_planet'];
        if (!in_array($selectedId, array_column($items, 'id'), true)) throw new \OutOfBoundsException('Объект недоступен игроку.');
        return ['playerId' => (string)$user['id'], 'selectedPlanetId' => $selectedId,
            'mainPlanetId' => (string)$user['main_planet'], 'items' => $items];
    }
}
