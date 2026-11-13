<?php
declare(strict_types=1);

namespace SPGame\Game\Services;

/** Ограниченный read-model: одна страница и текущее плечо, не вся история. */
final class FleetCommandProjection
{
    public const PAGE_SIZE = 50;
    private array $rows = [];
    private array $lastRows = [];
    private int $lastPage = 1;
    private int $page;
    private int $total = 0;
    private int $remaining = 0;
    private bool $hasMove = false;
    private ?int $currentPosition = null;
    private bool $split = true;
    private mixed $step = null;
    private ?array $target = null;
    private bool $returnLeg = false;
    private bool $legSelected = false;
    private bool $frozen = false;
    private ?array $leg = null;
    private ?float $lastMoveEnd = null;
    private ?float $previousEnd = null;

    public function __construct(private readonly ?int $requestedPage, private readonly bool $isReturn,
        private ?array $origin, private readonly ?array $returnStart)
    {
        $this->page = $requestedPage ?? 1;
    }

    public function add(array $command, float $createdAt, mixed $step): void
    {
        $command['position'] = ++$this->total;
        $completed = $command['status'] === 'completed';
        $currentPage = intdiv($this->total - 1, self::PAGE_SIZE) + 1;
        if ($currentPage !== $this->lastPage) { $this->lastRows = []; $this->lastPage = $currentPage; }
        $this->lastRows[] = $command;
        if ($this->requestedPage === null && $this->currentPosition === null && $currentPage !== $this->page) {
            $this->page = $currentPage; $this->rows = [];
        }
        if ($currentPage === $this->page) $this->rows[] = $command;
        if (!$completed) {
            ++$this->remaining;
            $this->currentPosition ??= $this->total;
        }
        $moving = in_array((int)$command['type'], [10, 11, 12], true);
        if (!$completed && $moving) $this->hasMove = true;
        if (!$moving) { $this->split = true; return; }

        if ($this->split || $this->step !== $step || $this->target != $command['target']
            || $this->returnLeg !== (bool)$command['return_leg']) {
            $this->step = $step; $this->target = $command['target'];
            $this->returnLeg = (bool)$command['return_leg'];
            $this->legSelected = false;
            if ($this->returnLeg === $this->isReturn && !$this->frozen) {
                if ($this->leg !== null && $this->total > ($this->currentPosition ?? PHP_INT_MAX)) $this->frozen = true;
                else {
                    $this->legSelected = true;
                    $this->previousEnd = $this->lastMoveEnd;
                    $this->leg = ['direction' => $this->isReturn ? 'return' : 'outbound',
                        'start' => $this->isReturn ? $this->returnStart : $this->origin,
                        'target' => $this->target, 'started_at' => null, 'arrives_at' => null,
                        'estimated' => false, 'active_segment' => null, 'segments_total' => 0, 'segments' => []];
                }
            }
            $this->origin = $this->target;
        }
        $this->split = false;
        if ($this->legSelected) {
            $started = is_numeric($command['started_time']) ? (float)$command['started_time'] : null;
            $startEstimated = (bool)$command['start_time_estimated'];
            if ($started === null || $started <= 0) {
                $started = $this->previousEnd;
                if ($started === null || $started <= 0) $started = $createdAt > 0 ? $createdAt : null;
                $startEstimated = true;
            }
            $end = is_numeric($command['estimated_time']) && (float)$command['estimated_time'] > 0
                ? (float)$command['estimated_time'] : null;
            $estimated = $startEstimated || (bool)$command['time_estimated'];
            if ($this->leg['segments_total'] === 0) $this->leg['started_at'] = $started;
            ++$this->leg['segments_total'];
            if ($command['status'] === 'active') $this->leg['active_segment'] = $this->leg['segments_total'];
            $this->leg['arrives_at'] = $end;
            $this->leg['estimated'] = $this->leg['estimated'] || $estimated;
            $this->leg['segments'][] = ['id' => (int)$command['id'], 'position' => $command['position'],
                'type' => (int)$command['type'], 'key' => (string)$command['key'], 'lang' => (string)$command['lang'],
                'status' => (string)$command['status'], 'started_at' => $started, 'ends_at' => $end,
                'fuel_cost' => $command['fuel_cost'] ?? null, 'estimated' => $estimated];
            if (count($this->leg['segments']) > self::PAGE_SIZE) array_shift($this->leg['segments']);
            if ($end !== null) $this->previousEnd = $end;
        }
        if (is_numeric($command['estimated_time']) && (float)$command['estimated_time'] > 0) {
            $this->lastMoveEnd = (float)$command['estimated_time'];
        }
    }

    public function result(): array
    {
        $pages = max(1, (int)ceil($this->total / self::PAGE_SIZE));
        if ($this->page > $pages) { $this->page = $pages; $this->rows = $this->lastRows; }
        return ['commands' => $this->rows, 'commands_page' => ['page' => $this->page, 'pages' => $pages,
            'size' => self::PAGE_SIZE, 'total' => $this->total], 'commands_total' => $this->total,
            'commands_left' => $this->remaining, 'has_pending_move' => $this->hasMove, 'route_leg' => $this->leg];
    }
}
