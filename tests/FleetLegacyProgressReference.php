<?php
declare(strict_types=1);

use SPGame\Game\Services\FleetControlService as Control;

/** Зафиксированный до B074 алгоритм. Только oracle; не оптимизировать вместе с production. */
return static function (array $commands, ?int &$visits = null): array {
    $visits = 0;
    $weights = $completed = [];
    foreach ($commands as $index => $hold) {
        ++$visits;
        if ((int)$hold['command_type'] !== 43) continue;
        $holdTime = (float)(Control::params($hold)['hold']['startedAt'] ?? 0);
        if ($holdTime <= 0) continue;
        for ($i = $index - 1; $i >= 0; --$i) {
            ++$visits;
            $old = $commands[$i];
            if (!in_array((int)$old['command_type'], [10,11,12], true)) continue;
            $p = Control::params($old);
            if (empty($p['cancelled']) || isset($p['progressCompleted'])) continue;
            $start = (float)($p['startedAt'] ?? 0); $end = (float)$old['execute_time'];
            if ($start <= 0 || $end <= $start || $holdTime < $start || $holdTime > $end) continue;
            for ($j = $index + 1; isset($commands[$j]); ++$j) {
                ++$visits;
                $next = $commands[$j];
                if (!in_array((int)$next['command_type'], [10,11,12], true)) continue;
                $np = Control::params($next);
                if (($np['target'] ?? null) !== ($p['target'] ?? null)) break;
                $weight = (float)($weights[$old['id']] ?? $p['progressWeight'] ?? 1);
                $done = min($weight, max(0.0, (float)($p['progressDone'] ?? 0)));
                $travelled = min($weight, $done + (float)($p['progressSpan'] ?? $weight - $done) * ($holdTime - $start) / ($end - $start));
                $completed[$old['id']] = $travelled;
                if (!isset($np['progressWeight'])) $weights[$next['id']] = max(0.0, $weight - $travelled);
                break;
            }
            break;
        }
    }
    return [$weights, $completed];
};
