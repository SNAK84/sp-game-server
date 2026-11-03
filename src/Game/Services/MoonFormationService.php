<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\{Config, Planets};
use SPGame\Game\Space\{Coordinate, ObjectType};

/** Правила Nova и подготовка луны без записи gameplay state. Commit выполняет ATTACK. */
final class MoonFormationService
{
    public static function rules(): array
    {
        $factor = (float)(Config::getValue('MoonFactor') ?? 1);
        $maximum = (float)(Config::getValue('MoonChance') ?? 20);
        $consume = (int)(Config::getValue('DebrisMoon') ?? 1);
        if (!is_finite($factor) || $factor < 0 || !is_finite($maximum)
            || $maximum < 0 || $maximum > 100 || floor($maximum) !== $maximum
            || !in_array($consume, [0, 1], true)) {
            throw new \LogicException('Некорректные настройки образования луны.');
        }
        return ['factor' => $factor, 'maximum' => (int)$maximum, 'consumeDebris' => (bool)$consume];
    }

    public static function chance(float $newDebris, array $rules): int
    {
        if (!is_finite($newDebris) || $newDebris < 0) {
            throw new \InvalidArgumentException('Некорректное количество новых обломков.');
        }
        return (int)min($rules['maximum'], round($newDebris / 100000 * $rules['factor']));
    }

    public static function prepare(array $planet, array $debris, string $seed, float $time, array $rules): array
    {
        $result = ['chance' => 0, 'created' => false, 'reason' => 'no_chance', 'row' => null,
            'consumeDebris' => false];
        if ((int)$planet['planet_type'] !== ObjectType::PLANET->value) {
            $result['reason'] = 'not_planet';
            return $result;
        }
        if (Planets::findByIndex('galaxy_system_planet_type', [
            (int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet'], ObjectType::MOON->value,
        ]) !== null) {
            $result['reason'] = 'existing_moon';
            return $result;
        }
        $result['chance'] = self::chance((float)($debris[901] ?? 0) + (float)($debris[902] ?? 0), $rules);
        if ($result['chance'] === 0) return $result;

        // Отдельный поток случайности: повтор commit не перебрасывает исход/размер.
        $random = new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar(
            hash_hmac('sha256', 'moon-formation', $seed, true)));
        if ($random->getInt(1, 100) > $result['chance']) {
            $result['reason'] = 'roll_failed';
            return $result;
        }
        $diameter = (int)floor(sqrt($random->getInt(10, 20) + 3 * $result['chance']) * 1000);
        $maxTemperature = (int)$planet['temp_max'] - $random->getInt(10, 45);
        $minTemperature = min($maxTemperature, (int)$planet['temp_min'] - $random->getInt(10, 45));
        // MOON не получает собственной орбиты вокруг звезды: тот же anchor, что у планеты.
        $position = (new FleetFlightService())->resolvePhysicalPoint(Coordinate::object(
            (int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet'], ObjectType::PLANET), $time);
        $result['row'] = [
            'owner_id' => (int)$planet['owner_id'],
            'name' => CelestialNameGenerator::moonName(
                (int)$planet['galaxy'], (int)$planet['system'], (int)$planet['planet'], 1,
                array_column(Planets::findByIndex('galaxy_system', [(int)$planet['galaxy'], (int)$planet['system']]) ?? [], 'name')
            ),
            'name_generated' => 1,
            'galaxy' => (int)$planet['galaxy'], 'system' => (int)$planet['system'], 'planet' => (int)$planet['planet'],
            'planet_type' => ObjectType::MOON->value, 'type' => 'moon', 'image' => 'moon',
            'size' => $diameter, 'fields' => 1, 'temp_min' => $minTemperature, 'temp_max' => $maxTemperature,
            'distance' => (int)$position->radius, 'deg' => $position->angle,
            'speed' => (float)$planet['speed'], 'rotation' => (int)$planet['rotation'],
            'create_time' => (int)$time, 'update_time' => $time,
        ];
        $result['created'] = true;
        $result['reason'] = 'created';
        $result['consumeDebris'] = $rules['consumeDebris'];
        return $result;
    }
}
