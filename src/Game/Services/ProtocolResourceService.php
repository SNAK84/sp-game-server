<?php

namespace SPGame\Game\Services;

use SPGame\Core\Message;
use SPGame\Game\Pages\{OverviewPage, BuildingsPage, ResearchPage, HangarPage, GalaxyPage, FleetsPage,
    MessagesPage, FriendsPage, InformationPage, BattleReportPage};
use SPGame\Game\Repositories\Fleets;

/** Проекции данных; ни Page/HTML, ни навигации клиента в wire-контракте нет. */
final class ProtocolResourceService
{
    public static function account(int $accountId, array $context): AccountData
    {
        if (array_diff(array_keys($context), ['planetId'])) throw new \DomainException('query.invalid_input');
        $id = $context['planetId'] ?? null;
        if ($id !== null && (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id))) throw new \DomainException('query.invalid_input');
        $planets = ProtocolReadService::planets($accountId, $id);
        $account = new AccountData($accountId);
        $account['WorkPlanet'] = (int)$planets['selectedPlanetId'];
        return $account;
    }

    public static function value(int $accountId, string $name, array $context, array $data): array
    {
        $definition = ProtocolOperations::definition($name);
        ProtocolOperations::validateData($data, $definition);
        $account = self::account($accountId, $context);
        // fleetId в корне — исходный собственный флот. Чужая цель задаётся
        // только отдельной Coordinate и проверяется правилами видимости миссии.
        foreach (['fleetId', 'fleet_id'] as $key) if (array_key_exists($key, $data)) {
            if (!is_string($data[$key]) || !preg_match('/^(0|[1-9][0-9]{0,18})$/D', $data[$key])) throw new \DomainException('query.invalid_input');
            if ($data[$key] !== '0') {
                $fleet = Fleets::findExistingById((int)$data[$key]);
                if (!$fleet || (int)$fleet['owner_id'] !== (int)$account['User']['id']) throw new \OutOfBoundsException('Флот недоступен.');
            }
        }
        foreach ((array)($data['historyPages'] ?? []) as $id => $unused) {
            if (strlen((string)$id) === 19 && strcmp((string)$id, (string)PHP_INT_MAX) > 0) throw new \OutOfBoundsException('Флот недоступен.');
            $fleet = Fleets::findExistingById((int)$id);
            if ($fleet && (int)$fleet['owner_id'] !== (int)$account['User']['id']) throw new \OutOfBoundsException('Флот недоступен.');
        }
        if ($name === 'player.planets.get') return ProtocolReadService::planets($accountId, $context['planetId'] ?? null);
        if ($name === 'player.status.get') {
            $builder = new \SPGame\Game\PageBuilder(new Message(), 0);
            return ProtocolOperations::ids(['topNav' => $builder->GetTopNav($account), 'planets' => $builder->GetPlanetList($account),
                'language' => $account['Account']['lang']], true);
        }
        if ($name === 'localization.get') {
            $lang = (string)$account['Account']['lang'];
            return ['language' => $lang, 'texts' => Lang::load($lang)];
        }
        $message = new Message(['mode' => $definition[0], 'action' => $definition[1], 'data' => ProtocolOperations::ids($data, false)]);
        $page = match ($definition[0]) {
            'overview' => new OverviewPage($message), 'buildings' => new BuildingsPage($message),
            'researchs' => new ResearchPage($message), 'galaxy' => new GalaxyPage($message),
            'fleets' => new FleetsPage($message), 'messages' => new MessagesPage($message),
            'friends' => new FriendsPage($message), 'information' => new InformationPage($message),
            'battlereport' => new BattleReportPage($message),
            'shipyard', 'defense' => new HangarPage($message),
        };
        if ($page instanceof HangarPage) $page->hangarMode = $definition[0] === 'defense' ? 'Defenses' : 'Ships';
        $value = $page->render($account);
        unset($value['page']);
        return ProtocolOperations::ids($value, true);
    }
}
