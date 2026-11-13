<?php

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\Accounts;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\Techs;
use SPGame\Game\Repositories\Planets;
use SPGame\Game\Repositories\Builds;
use SPGame\Game\Repositories\Ships;
use SPGame\Game\Repositories\Defenses;
use SPGame\Game\Repositories\Resources;
use SPGame\Core\Logger;
use ArrayAccess;

class AccountData implements ArrayAccess
{
    private int $accountId;
    private Data $Account;
    private Data $User;
    private Data $Techs;
    private FleetsData $FleetsData;
    private array $Planets = [];
    private int $WorkPlanet = 0;
    private bool $AccountDirty = false;
    private bool $UserDirty = false;
    private bool $TechsDirty = false;
    private array $PlanetsDirty = [];
    private array $BuildsDirty = [];
    private array $ShipsDirty = [];
    private array $DefensesDirty = [];
    private array $ResourcesDirty = [];

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;
        $this->FleetsData = FleetsData::getInstance();
        $this->Account = new Data(Accounts::findById($accountId), $this->AccountDirty);
        $this->User = new Data(Users::findByAccount($accountId), $this->UserDirty);
        $this->WorkPlanet = $this->User['current_planet'] ?? 0;
        $this->Techs = new Data(Techs::findById($this->User['id'] ?? 0), $this->TechsDirty);

        if ($this->User['current_planet'] === 0) {
            if ($this->User['main_planet'] === 0) {
                $lang = $this->Account['lang'];
                Logger::getInstance()->info('CreatePlanet', ['lang' => $lang, 'accountId' => $this->accountId]);
                $planet = GalaxyGenerator::CreatePlanet($this->User['id'], null, true);
                $this->User['main_planet'] = $planet['id'];
                $this->User['current_planet'] = $planet['id'];
                Users::update($this->User->toArray());
            }
            $this->User['current_planet'] = $this->User['main_planet'];
            $this->WorkPlanet = $this->User['current_planet'] ?? 0;
        }
    }

    public function getFleetsData(): FleetsData
    {
        return $this->FleetsData;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return match ($offset) {
            'Account', 'User', 'Techs' => true,
            'Planet', 'Builds', 'Ships', 'Defenses', 'Resources', 'PlanetFleets', 'Fleets' => !empty($this->WorkPlanet),
            'WorkPlanet', 'All_Builds' => true,
            default => false,
        };
    }

    #[\ReturnTypeWillChange]
    public function &offsetGet($offset): mixed
    {
        if ($offset === 'Account') return $this->Account;
        if ($offset === 'User') return $this->User;
        if ($offset === 'Techs') return $this->Techs;
        if ($offset === 'Fleets') {
            $fleets = $this->FleetsData->getForAccount((int) ($this->User['id'] ?? 0));
            return $fleets;
        }
        if ($offset === 'WorkPlanet') return $this->WorkPlanet;

        if (str_starts_with($offset, 'All_')) {
            $type = substr($offset, 4);
            if (!in_array($type, ['Planet', 'Builds', 'Ships', 'Defenses', 'Resources'], true)) {
                $null = null;
                return $null;
            }
            $allData = [];
            $planets = Planets::findByIndex('owner_id', $this->User['id'] ?? 0);
            foreach ($planets as $planet) {
                $planetData = $this->getData($planet['id']);
                $allData[$planet['id']] = $planetData[$type];
            }
            $dirtyFlag = false;
            $allDataObj = new Data($allData, $dirtyFlag);
            return $allDataObj;
        }

        if (in_array($offset, ['Planet', 'Builds', 'Ships', 'Defenses', 'Resources'], true)) {
            $planetData = &$this->getData();
            return $planetData[$offset];
        }

        $null = null;
        return $null; // обязательно возвращаем переменную по ссылке
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if ($offset === 'WorkPlanet') {
            $this->WorkPlanet = (int)$value;
            if (!isset($this->PlanetsDirty[$this->WorkPlanet]))   $this->PlanetsDirty[$this->WorkPlanet]   = false;
            if (!isset($this->BuildsDirty[$this->WorkPlanet]))    $this->BuildsDirty[$this->WorkPlanet]    = false;
            if (!isset($this->ShipsDirty[$this->WorkPlanet]))     $this->ShipsDirty[$this->WorkPlanet]     = false;
            if (!isset($this->DefensesDirty[$this->WorkPlanet]))  $this->DefensesDirty[$this->WorkPlanet]  = false;
            if (!isset($this->ResourcesDirty[$this->WorkPlanet])) $this->ResourcesDirty[$this->WorkPlanet] = false;
        } elseif ($offset === 'Account') {
            $this->Account = new Data($value ?: [], $this->AccountDirty);
        } elseif ($offset === 'User') {
            $this->User = new Data($value ?: [], $this->UserDirty);
        } elseif ($offset === 'Techs') {
            $this->Techs = new Data($value ?: [], $this->TechsDirty);
        } elseif ($offset === 'Planet') {
            $this->Planets[$this->WorkPlanet]['Planet'] = new Data($value ?: [], $this->PlanetsDirty[$this->WorkPlanet]);
        } elseif (in_array($offset, ['Builds', 'Ships', 'Defenses', 'Resources'], true)) {
            $this->Planets[$this->WorkPlanet][$offset] = new Data($value ?: [], $this->{$offset . 'Dirty'}[$this->WorkPlanet]);
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        match ($offset) {
            'Account', 'User', 'Techs' => $this->$offset = new Data([], $this->{$offset . 'Dirty'}),
            'Planet', 'Builds', 'Ships', 'Defenses', 'Resources' => $this->Planets = [],
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
            $this->PlanetsDirty[$planetId]   = false;
            $this->BuildsDirty[$planetId]    = false;
            $this->ShipsDirty[$planetId]     = false;
            $this->DefensesDirty[$planetId]  = false;
            $this->ResourcesDirty[$planetId] = false;

            $this->Planets[$planetId] = [
                'Planet'    => new Data(Planets::findById($planetId) ?: [], $this->PlanetsDirty[$planetId]),
                'Builds'    => new Data(Builds::findById($planetId) ?: [], $this->BuildsDirty[$planetId]),
                'Ships'     => new Data(Ships::findById($planetId) ?: [], $this->ShipsDirty[$planetId]),
                'Defenses'  => new Data(Defenses::findById($planetId) ?: [], $this->DefensesDirty[$planetId]),
            ];

            // Resources::get() читает Planet/Builds через WorkPlanet, в том числе
            // при загрузке другой планеты из All_Builds/All_Resources.
            $previousWorkPlanet = $this->WorkPlanet;
            try {
                $this->WorkPlanet = $planetId;
                $this->Planets[$planetId]['Resources'] = new Data(Resources::get($this) ?: [], $this->ResourcesDirty[$planetId]);
            } catch (\Throwable $e) {
                // Не оставляем частичный cache: следующая загрузка должна повторить расчёт.
                unset($this->Planets[$planetId]);
                throw $e;
            } finally {
                $this->WorkPlanet = $previousWorkPlanet;
            }

        }

        // ✅ фикс: создаём ссылку на элемент массива
        $planetRef = &$this->Planets[$planetId];
        return $planetRef;
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
            if ($this->ShipsDirty[$pid]) {
                Ships::update($arr['Ships'] ?? []);
                $this->ShipsDirty[$pid] = false;
            }
            if ($this->DefensesDirty[$pid]) {
                Defenses::update($arr['Defenses'] ?? []);
                $this->DefensesDirty[$pid] = false;
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

        $this->FleetsData->save();
    }

    public function deepCopy(): self
    {
        $copy = new self($this->accountId);

        // Копируем Account, User, Techs как независимые Data
        $copy['Account'] = $this->Account->toArray();
        $copy['User']    = $this->User->toArray();
        $copy['Techs']   = $this->Techs->toArray();

        $copy->WorkPlanet = $this->WorkPlanet;

        // Копируем планеты
        foreach ($this->Planets as $pid => $planetData) {

            $copy['WorkPlanet'] = $pid;

            $copy['Planet']    = $planetData['Planet']->toArray();
            $copy['Builds']    = $planetData['Builds']->toArray();
            $copy['Ships']     = $planetData['Ships']->toArray();
            $copy['Defenses']  = $planetData['Defenses']->toArray();
            $copy['Resources'] = $planetData['Resources']->toArray();


            // Флаги dirty
            $copy->PlanetsDirty[$pid]   = false;
            $copy->BuildsDirty[$pid]    = false;
            $copy->ShipsDirty[$pid]     = false;
            $copy->DefensesDirty[$pid]     = false;
            $copy->ResourcesDirty[$pid] = false;
        }

        // Флаги dirty
        $copy->AccountDirty = false;
        $copy->UserDirty = false;
        $copy->TechsDirty = false;

        return $copy;
    }


    // --- Для старого кода ---
    public function toArray(): array
    {
        $pid = $this->WorkPlanet;
        $dirty = false;
        $data = $this->Planets[$pid] ?? new Data([], $dirty);

        return [
            'Account'           => $this->Account->toArray(),
            'User'              => $this->User->toArray(),
            'Techs'             => $this->Techs->toArray(),
            'Planet'            => $data['Planet']->toArray(),
            'Builds'            => $data['Builds']->toArray(),
            'Ships'             => $data['Ships']->toArray(),
            'Defenses'          => $data['Defenses']->toArray(),
            'Resources'         => $data['Resources']->toArray(),
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

    public static function fromReference(array &$source, array $keys, bool &$dirty): self
    {
        $subset = [];
        foreach ($keys as $key) {
            if (isset($source[$key])) {
                $subset[$key] = &$source[$key]; // <-- ССЫЛКА!
            }
        }
        $obj = new self([], $dirty);
        $obj->data = &$subset;
        return $obj;
    }

    public function &getInternal(): array
    {
        return $this->data;
    }

    public function bindInternalReference(array &$source): void
    {
        // Переназначаем internal storage на ссылку на внешний массив
        $this->data = &$source;
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

    public function toString(): string
    {
        if (count($this->data) === 1 && is_scalar(reset($this->data))) {
            return (string) reset($this->data);
        }

        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
