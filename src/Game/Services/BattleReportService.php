<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

/** Публичный исторический DTO: не содержит seed, future commands или запасов планеты. */
final class BattleReportService
{
	public static function snapshot(
		array $state,
		array $result,
		array $restored,
		array $rewards,
		array $loot,
		array $debris,
		array $losses,
		float $finishedAt,
		?array $moon = null,
		array $destruction = []
	): array {
		$spaceTarget = $state['mission']['spaceTarget'] ?? null;
		$planet = $state['mission']['planet'] ?? null;
		$participants = [];
		foreach ($state['mission']['participants'] as $id => $participant) {
			// Реально вступившие группы определяются calculator result, не ожидающим флотом.
			$found = false;
			foreach ($result[$participant['side']]['groups'] as $group) {
				if ($group['kind'] === 'fleet' && $group['fleetId'] === (int)$id) {
					$found = true;
					break;
				}
			}
			if (!$found) continue;
			$participants['fleet:' . $id] = [
				'userId' => (int)$participant['ownerId'],
				'userName' => (string)($participant['ownerName'] ?? ''),
				'fleetId' => (int)$id,
				'fleetName' => (string)($participant['fleetName'] ?? ''),
				'side' => $participant['side'],
				'coordinate' => $participant['coordinate'] ?? null,
				'joinedRound' => (int)($participant['joinedRound'] ?? 1),
			];
		}
		$planetKey = $planet === null ? null : 'planet:' . $planet['id'];
		if ($planet !== null) $participants[$planetKey] = [
			'userId' => (int)$state['mission']['defenderId'],
			'userName' => (string)($state['mission']['defenderName'] ?? ''),
			'fleetId' => null,
			'fleetName' => (string)$planet['name'],
			'name_generated' => (int)($planet['name_generated'] ?? 0),
			'side' => 'defender',
			'joinedRound' => 1,
			'coordinate' => [
				'type' => 1,
				'galaxy' => (int)$planet['galaxy'],
				'system' => (int)$planet['system'],
				'orbit' => (int)$planet['planet'],
				'objectType' => (int)$planet['planet_type']
			]
		];
		$groups = [];
		foreach (['attacker', 'defender'] as $side) foreach ($result[$side]['groups'] as $id => $group) {
			$repair = (int)($restored[$side][$id] ?? 0);
			$xp = $rewards[$side]['groups'][$id];
			$groups[$id] = [
				'participant' => $group['kind'] === 'fleet' ? 'fleet:' . $group['fleetId'] : $planetKey,
				'side' => $side,
				'type' => $group['type'],
				'kind' => $group['kind'],
				'before' => $group['before'],
				'survived' => $group['after'],
				'restored' => $repair,
				'lost' => $group['before'] - $group['after'] - $repair,
				'attack' => $group['attack'],
				'shield' => $group['shield'],
				'hull' => $group['hull'],
				'experienceBefore' => $group['experience'],
				'experienceAfter' => $xp['experience'],
				'experienceGained' => $xp['gained'],
				'experienceLost' => $xp['lost'],
			];
		}
		return [
			'version' => 1,
			'battleId' => $state['id'],
			'startedAt' => $state['startedAt'],
			'finishedAt' => $finishedAt,
			'target' => $spaceTarget ?? [
				'name' => (string)$planet['name'],
				'name_generated' => (int)($planet['name_generated'] ?? 0),
				'coordinate' => $participants[$planetKey]['coordinate']
			],
			'outcome' => $result['outcome'],
			'reason' => $result['reason'],
			'roundLimit' => $result['roundLimit'],
			'participants' => $participants,
			'groups' => $groups,
			'rounds' => $result['rounds'],
			'totals' => ['losses' => $losses, 'loot' => $loot, 'debris' => $debris],
			'moon' => $moon,
			'destruction' => $destruction,
			'attackGroupId' => (int)($state['mission']['groupId'] ?? 0)
		];
	}

	/** Локализовать копию исторического снимка, не обращаясь к текущим объектам. */
	public static function localizeNames(array $report, string $language): array
	{
		foreach (['target', 'moon'] as $key) {
			if (!empty($report[$key]['name_generated']) && is_string($report[$key]['name'] ?? null)) {
				$report[$key]['name'] = CelestialNameGenerator::planetDisplayName($report[$key], $language);
			}
		}
		foreach ($report['participants'] ?? [] as $key => $participant) {
			if (!empty($participant['name_generated'])) {
				$report['participants'][$key]['fleetName'] = CelestialNameGenerator::planetDisplayName([
					'name' => $participant['fleetName'], 'name_generated' => 1,
				], $language);
			}
		}
		return $report;
	}
}
