<?php

declare(strict_types=1);

namespace SPGame\Game\Services {
    // Подмена существует только в CLI теста. Другим сервисам оставляем настоящий RNG.
    function random_int(int $min, int $max): int
    {
        if (isset($GLOBALS['fleetPlanSpyRolls'])) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
            if (($caller['class'] ?? null) === FleetSpyService::class) {
                $value = array_shift($GLOBALS['fleetPlanSpyRolls']);
                if (!is_int($value) || $value < $min || $value > $max) {
                    throw new \RuntimeException('Неверный или лишний тестовый бросок шпионажа.');
                }
                return $value;
            }
        }
        return \random_int($min, $max);
    }
}

namespace {
    function withFleetPlanSpyRolls(array $rolls, callable $operation): mixed
    {
        if (isset($GLOBALS['fleetPlanSpyRolls'])) throw new LogicException('Броски шпионажа уже заданы.');
        $GLOBALS['fleetPlanSpyRolls'] = $rolls;
        try {
            $result = $operation();
            if ($GLOBALS['fleetPlanSpyRolls'] !== []) throw new RuntimeException('Не все тестовые броски шпионажа использованы.');
            return $result;
        } finally {
            unset($GLOBALS['fleetPlanSpyRolls']);
        }
    }
}
