<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

/**
 * Chronological scheduler для уже активированных Fleet events.
 *
 * Репозиторий передаётся callback-ом намеренно: после каждого события
 * scheduler снова запрашивает глобально самое раннее событие и не продолжает
 * локальную очередь Fleet автоматически.
 */
final class FleetEventScheduler
{
    public bool $exhausted = false;
    /**
     * @param callable(float):(?array) $getNextDueEvent
     * @param callable(array):bool $executeEvent
     * @return list<int> IDs обработанных команд
     */
    public function process(
        float $now,
        callable $getNextDueEvent,
        callable $executeEvent
    ): array {
        $processed = [];
        $this->exhausted = false;
        $iterations = 0;
        $started = hrtime(true);

        while (true) {
            // Остаток сохраняет хронологический порядок и продолжится следующим tick.
            if (++$iterations > 512 || hrtime(true) - $started >= 100000000) { $this->exhausted = true; break; }

            $event = $getNextDueEvent($now);
            if ($event === null) {
                break;
            }

            if (!$executeEvent($event)) {
                throw new \LogicException(
                    'Fleet event handler не выполнил due-команду #'
                    . (int)($event['id'] ?? 0)
                    . ' (command_type='
                    . (int)($event['command_type'] ?? 0)
                    . ').'
                );
            }

            $processed[] = (int)$event['id'];
        }

        return $processed;
    }
}
