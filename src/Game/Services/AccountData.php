<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Techs;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Builds;
use SPGame\Game\Repositories\Resources;

use SPGame\Core\Logger;
use ArrayAccess;

class AccountData implements ArrayAccess
{
    private int $accountId;

    private Data $Account;
    private Data $User;
    private Data $Techs;

    /** @var array<int, array{Planet: Data, Builds: Data, Resources: Data}> */
    private array $Planets = [];

    private int $WorkPlanet = 0;

    private bool $AccountDirty = false;
    private bool $UserDirty = false;
    private bool $TechsDirty = false;

    private array $PlanetsDirty = [];
    private array $BuildsDirty = [];
    private array $ResourcesDirty = [];

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;

        $this->Account = new Data(Accounts::findById($accountId), $this->AccountDirty);
        $this->User = new Data(Users::findByAccount($accountId), $this->UserDirty);
        $this->WorkPlanet = $this->User['current_planet'] ?? 0;
        $this->Techs = new Data(Techs::findById($this->User['id'] ?? 0), $this->TechsDirty);
    }

    // --- ArrayAccess ---
    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return match ($offset) {
            'Account', 'User', 'Techs' => true,
            'Planet', 'Builds', 'Resources' => !empty($this->WorkPlanet),
            'WorkPlanet' => true,
            default => false,
        };
    }

    #[\ReturnTypeWillChange]
    public function &offsetGet($offset): mixed
    {
        // Используем ручной switch, а не match — match не поддерживает ссылки
        if ($offset === 'Account') {
            return $this->Account;
        } elseif ($offset === 'User') {
            return $this->User;
        } elseif ($offset === 'Techs') {
            return $this->Techs;
        } elseif ($offset === 'WorkPlanet') {
            return $this->WorkPlanet;
        } elseif (in_array($offset, ['Planet', 'Builds', 'Resources'], true)) {
            $planetData = &$this->getData();
            return $planetData[$offset];
            return $ref;
        }

        $null = null;
        return $null; // обязательно возвращаем переменную по ссылке
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if ($offset === 'WorkPlanet') {
            $this->WorkPlanet = (int)$value;
            return;
        }

        match ($offset) {
            'Account'   => $this->Account = $value,
            'User'      => $this->User = $value,
            'Techs'     => $this->Techs = $value,
            'Planet'    => $this->getData()['Planet'] = $value,
            'Builds'    => $this->getData()['Builds'] = $value,
            'Resources' => $this->getData()['Resources'] = $value,
            default => null,
        };
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        match ($offset) {
            'Account', 'User', 'Techs' => $this->$offset = new Data([], $this->{$offset . 'Dirty'}),
            'Planet', 'Builds', 'Resources' => $this->Planets = [],
            default => null,
        };
    }

    // --- Доступ к данным планеты ---
    public function &getData(?int $planetId = null): array
    {
        $planetId ??= $this->WorkPlanet;
        if (!$planetId) {
            throw new \RuntimeException('Нет текущей планеты');
        }

        if (!isset($this->Planets[$planetId])) {
            $this->PlanetsDirty[$planetId] = false;
            $this->BuildsDirty[$planetId] = false;
            $this->ResourcesDirty[$planetId] = false;

            $this->Planets[$planetId] = [
                'Planet'    => new Data(Planets::findById($planetId) ?: [], $this->PlanetsDirty[$planetId]),
                'Builds'    => new Data(Builds::findById($planetId) ?: [], $this->BuildsDirty[$planetId]),
            ];

            $this->Planets[$planetId]['Resources'] = new Data(Resources::get($this) ?: [], $this->ResourcesDirty[$planetId]);
        }

        // ✅ фикс: создаём ссылку на элемент массива
        $planetRef = &$this->Planets[$planetId];
        return $planetRef;
        return $this->Planets[$planetId];
    }

    // --- Сохранение ---
    public function save(): void
    {
        foreach ($this->Planets as $pid => $data) {
            $arr = [];
            foreach ($data as $key => $block) {
                $arr[$key] = $block instanceof Data ? $block->toArray() : $block;
            }
            if ($this->PlanetsDirty[$pid]) {
                Planets::update($arr['Planet'] ?? []);
                $this->PlanetsDirty[$pid] = false;
            }
            if ($this->BuildsDirty[$pid]) {
                Builds::update($arr['Builds'] ?? []);
                $this->BuildsDirty[$pid] = false;
            }
            if ($this->ResourcesDirty[$pid]) {
                Resources::updateByPlanetId($pid, $arr['Resources'] ?? []);
                $this->ResourcesDirty[$pid] = false;
            }
        }

        if ($this->UserDirty) {
            Users::update($this->User->toArray());
            $this->UserDirty = false;
        }
        if ($this->TechsDirty) {
            Techs::update($this->Techs->toArray());
            $this->TechsDirty = false;
        }
        if ($this->AccountDirty) {
            Accounts::update($this->Account->toArray());
            $this->AccountDirty = false;
        }
    }

    // --- Для старого кода ---
    public function toArray(): array
    {
        $pid = $this->WorkPlanet;
        $data = $this->Planets[$pid] ?? new Data([], $dirty = false);

        return [
            'Account'   => $this->Account->toArray(),
            'User'      => $this->User->toArray(),
            'Techs'     => $this->Techs->toArray(),
            'Planet'    => $data['Planet']->toArray(),
            'Builds'    => $data['Builds']->toArray(),
            'Resources' => $data['Resources']->toArray(),
        ];
    }
}


class Data implements ArrayAccess
{
    private array $data;
    private bool $dirty;

    public function __construct(?array $data, bool &$dirty)
    {
        $this->data = $data ?: [];
        $this->dirty = &$dirty;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function &offsetGet($offset)
    {
        if (!isset($this->data[$offset])) {
            $this->data[$offset] = new Data([], $this->dirty);
        } elseif (is_array($this->data[$offset])) {
            $this->data[$offset] = new Data($this->data[$offset], $this->dirty);
        }
        $data = &$this->data[$offset];
        return $data;
        return $this->data[$offset];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if (
            !isset($this->data[$offset]) ||
            $this->data[$offset] !== $value
        ) {
            $this->dirty = true;
        }

        // Если присваиваем массив — оборачиваем в Data автоматически
        if (is_array($value) && !($value instanceof Data)) {
            $value = new Data($value, $this->dirty);
        }

        $this->data[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
        $this->dirty = true;
    }

    public function toArray(): array
    {
        $result = [];
        foreach ($this->data as $key => $value) {
            $result[$key] = $value instanceof Data ? $value->toArray() : $value;
        }
        return $result;
    }
}
