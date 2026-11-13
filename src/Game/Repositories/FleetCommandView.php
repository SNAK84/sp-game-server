<?php
declare(strict_types=1);

namespace SPGame\Game\Repositories;

/** Короткий read-only view под world lock: в PHP живут ID, не тела всей истории.
 * @implements \ArrayAccess<int, array<string, mixed>>
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class FleetCommandView implements \ArrayAccess, \Countable, \IteratorAggregate
{
    private string $ids;
    private int $size;
    private int $tailStart = PHP_INT_MAX;
    private array $tail = [];

    public function __construct(private readonly int $fleetId, array $ids)
    {
        sort($ids, SORT_NUMERIC);
        $this->size = count($ids);
        $this->ids = $ids === [] ? '' : pack('J*', ...$ids);
    }

    public function count(): int { return $this->size; }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < $this->size;
    }

    public function offsetGet(mixed $offset): array
    {
        if (!$this->offsetExists($offset)) throw new \OutOfBoundsException('Нет команды в снимке.');
        if ($offset >= $this->tailStart) return $this->tail[$offset - $this->tailStart];
        $id = unpack('J', substr($this->ids, $offset * 8, 8))[1];
        $row = FleetCommands::findExistingById($id);
        if ($row === null || (int)$row['fleet_id'] !== $this->fleetId) {
            throw new \LogicException('Снимок команд изменён во время чтения.');
        }
        return $row;
    }

    public function offsetSet(mixed $offset, mixed $value): void { throw new \LogicException('Снимок только для чтения.'); }
    public function offsetUnset(mixed $offset): void { throw new \LogicException('Снимок только для чтения.'); }

    public function getIterator(): \Traversable
    {
        for ($i = 0; $i < $this->size; ++$i) yield $i => $this[$i];
    }

    /** Только прогноз потерянной ресурсной базы; основная Table не меняется. */
    public function withTail(int $offset, array $rows): self
    {
        $copy = clone $this;
        $copy->tailStart = $offset;
        $copy->tail = array_values($rows);
        $copy->size = $offset + count($rows);
        return $copy;
    }
}
