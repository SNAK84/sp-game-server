<?php

declare(strict_types=1);

namespace SPGame\Game\Fleet;

final class FleetResource
{
	private ?\Closure $onChange;
	private ?\Closure $maxCountProvider;

	public function __construct(
		private int $ID,
		private string $name,
		private float $count = 0.0,
		?callable $onChange = null,
		?callable $maxCountProvider = null,
	) {
		$this->onChange = $onChange !== null ? \Closure::fromCallable($onChange) : null;
		$this->maxCountProvider = $maxCountProvider !== null
			? \Closure::fromCallable($maxCountProvider)
			: null;
	}
	/* Получить ID ресурса */
	public function getID(): int
	{
		return $this->ID;
	}
	/* Получить имя ресурса */
	public function getName(): string
	{
		return $this->name;
	}
	/* Получить количество ресурса */
	public function getCount(): float
	{
		return $this->count;
	}
	/* Установить количество ресурса вернет количество, которое не удалось установить */
	public function setCount(float $count): float
	{
		$count = max(0, $count);

		if ($count <= $this->count) {
			if ($this->count === $count) {
				return 0.0;
			}

			$this->count = $count;
			$this->changed($count);

			return 0.0;
		}

		$notFittedCount = 0.0;

		if ($this->maxCountProvider !== null) {
			$maxCount = max(0, ($this->maxCountProvider)());
			$notFittedCount = max(0, $count - $maxCount);
			$count = min($count, $maxCount);
		}

		if ($this->count === $count) return $notFittedCount;

		$this->count = $count;
		$this->changed($count);
		return $notFittedCount;
	}
	/* Добавить количество ресурса вернет количество, которое не удалось добавить */
	public function add(float $amount): float
	{
		return $this->setCount($this->count + $amount);
	}
	/* Уменьшить количество ресурса вернет количество, которое не удалось уменьшить */
	public function subtract(float $amount): float
	{
		$amount = max(0, $amount);
		$notSubtractedAmount = max(0, $amount - $this->count);

		$this->setCount($this->count - $amount);

		return $notSubtractedAmount;
	}

	private function changed(float $value): void
	{
		if ($this->onChange !== null) {
			($this->onChange)('resource_' . $this->name, $value);
		}
	}
}
