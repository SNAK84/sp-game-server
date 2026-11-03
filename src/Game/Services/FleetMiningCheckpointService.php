<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Game\Repositories\{MiningCheckpoints as Journal, OrbitalResourceSites,
    ResourceSurveys, SurveyResults, Fleets, FleetsShips, FleetCommands};

/** Применение подготовленных сервером экономических after, не обработчик миссии. */
final class FleetMiningCheckpointService
{
    private const REPOSITORIES = ['site' => OrbitalResourceSites::class, 'knowledge' => ResourceSurveys::class,
        'fleet' => Fleets::class, 'command' => FleetCommands::class, 'ships' => FleetsShips::class, 'survey' => SurveyResults::class];

    /** Экономическое закрытие последней общей фазы; отдельной дельты расхода нет. */
    public static function closePhase(int $siteId, int $ownerId, string $token, array $fleetIds, string $reason): array
    {
        return Journal::worldLocked(static function () use ($siteId, $ownerId, $token, $fleetIds, $reason): array {
            self::recoverPending();
            $previous = Journal::locked($siteId, static fn(): array => Journal::load($siteId));
            sort($fleetIds, SORT_NUMERIC);
            $fingerprint = hash('sha256', json_encode([$siteId, $ownerId, $fleetIds, $reason], JSON_THROW_ON_ERROR));
            if ($previous['head']['token'] === $token) {
                $meta = json_decode($previous['head']['metadata'], true, 512, JSON_THROW_ON_ERROR);
                if (($meta['closeRequest'] ?? null) !== $fingerprint) throw new \LogicException('Повтор выхода изменяет исходный запрос.');
                return $previous;
            }
            if ($previous['head']['owner_id'] !== $ownerId) throw new \LogicException('Другой владелец фазы.');
            $rows = [];
            foreach ($previous['entries'] as $entry) {
                $row = self::REPOSITORIES[$entry['kind']]::findExistingById($entry['id']);
                if ($row !== null) $rows[$entry['kind']][$entry['id']] = $row;
                // Донор прошлого batch мог уже завершить маршрут/быть объединён.
                // close сам потребует все строки текущих носителей carry.
            }
            $plan = FleetMiningPhaseService::close($previous, $rows, $fleetIds, $reason);
            return self::commit($siteId, $ownerId, $previous['head']['revision'], $token,
                (float)$previous['head']['checkpoint_time'], $plan['entries'], $plan['metadata'] + ['closeRequest' => $fingerprint]);
        }, true);
    }

    /** Внутренний вход будущего обработчика: source берётся из runtime под EX. */
    public static function commitPhase(int $siteId, int $ownerId, string $token, array $workers,
        float $from, float $until, array $metadata = []): array
    {
        if (isset($metadata['miningCheckpoint']) || isset($metadata['phaseRequest'])) {
            throw new \InvalidArgumentException('Служебные поля фазы нельзя переопределять.');
        }
        return Journal::worldLocked(static function () use ($siteId, $ownerId, $token, $workers, $from, $until, $metadata): array {
            self::recoverPending();
            $previous = Journal::locked($siteId, static fn(): ?array => Journal::head($siteId) === null ? null : Journal::load($siteId));
            $canonical = static function (mixed $value) use (&$canonical): mixed {
                if (!is_array($value)) return $value;
                if (!array_is_list($value)) ksort($value, SORT_STRING);
                return array_map($canonical, $value);
            };
            $fingerprint = hash('sha256', json_encode($canonical([$siteId, $ownerId, $workers, $from, $until, $metadata]),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            if (($previous['head']['token'] ?? null) === $token) {
                $oldMeta = json_decode($previous['head']['metadata'], true, 512, JSON_THROW_ON_ERROR);
                if (($oldMeta['phaseRequest'] ?? null) !== $fingerprint) throw new \LogicException('Повтор фазы изменяет её исходный запрос.');
                return $previous;
            }
            $snapshot = ['site' => OrbitalResourceSites::findExistingById($siteId),
                'knowledge' => ResourceSurveys::findByIndex('owner_site', [$ownerId, $siteId]), 'fleets' => [], 'commands' => []];
            if ($snapshot['site'] === null || $snapshot['knowledge'] === null) throw new \LogicException('Нет объекта/обследования фазы.');
            foreach ($workers as $id => $config) {
                $snapshot['fleets'][$id] = Fleets::findExistingById((int)$id)
                    ?? throw new \LogicException('Нет флота фазы.');
                $command = isset($config['commandId']) ? FleetCommands::findExistingById((int)$config['commandId'])
                    : FleetCommands::getActiveCommand((int)$id);
                $snapshot['commands'][$id] = $command ?? throw new \LogicException('Нет команды добывающего флота.');
            }
            $plan = FleetMiningPhaseService::build($snapshot, $workers, $from, $until, $previous);
            return self::commit($siteId, $ownerId, (int)($previous['head']['revision'] ?? 0), $token,
                $plan['phase']['state']['time'], $plan['entries'], $plan['metadata'] + ['phaseRequest' => $fingerprint] + $metadata);
        }, true);
    }

    /** Не допускаем обычные действия/flush до завершения уже опубликованной операции. */
    public static function world(callable $operation): mixed
    {
        return Journal::worldLocked(static function () use ($operation): mixed {
            self::recoverPending();
            return $operation();
        });
    }

    public static function recoverPending(): void
    {
        // SH держится также во время проверки: публикация EX не может проскочить
        // между SELECT pending и допуском обычного обработчика.
        Journal::worldLocked(static function (): void {
            $pending = array_unique(array_merge(array_column(Journal::pending(), 'site_id'), Journal::protectedSites()));
            if ($pending === []) return;
            Journal::worldLocked(static function () use ($pending): void {
                foreach ($pending as $siteId) Journal::locked((int)$siteId, static function () use ($siteId): void {
                    $batch = Journal::load((int)$siteId);
                    if ($batch['head']['phase'] === 'prepared') self::apply($batch);
                    // ACK мог сохраниться, а последующий SELECT/cleanup — оборваться.
                    // Уже applied не переигрываем поверх дальнейшей жизни флота.
                    else Journal::unprotect((int)$siteId);
                });
            }, true);
        });
    }

    /**
     * До публикации проверяет все строки и фиксирует durable before.
     * После публикации только roll-forward того же token; rollback after запрещён.
     * SQL-представимость after — обязательное условие, не скрытое округление.
     */
    public static function commit(int $siteId, int $ownerId, int $revision, string $token,
        float $time, array $entries, array $metadata = []): array
    {
        return Journal::worldLocked(static fn(): array => Journal::locked($siteId,
            static function () use ($siteId, $ownerId, $revision, $token, $time, $entries, $metadata): array {
                Journal::encodeEntries($entries);
                self::validate($siteId, $ownerId, $entries);
                $head = Journal::head($siteId);
                foreach (Journal::pending() as $pending) if ((int)$pending['site_id'] !== $siteId) {
                    throw new \LogicException('Сначала требуется восстановить предыдущий checkpoint мира.');
                }
                if (($head['token'] ?? null) !== $token) {
                    if ($head !== null && ($head['phase'] !== 'applied' || (int)$head['revision'] !== $revision)) {
                        throw new \LogicException('Предыдущий checkpoint не завершён или версия устарела.');
                    }
                    // Проверить ВСЕ runtime before раньше первого baseline flush.
                    foreach ($entries as $entry) self::expectBefore(self::runtime($entry), $entry);
                    foreach ($entries as $entry) {
                        $repository = self::REPOSITORIES[$entry['kind']];
                        self::expectBefore($repository::flushCheckpointRow($entry['id']), self::storageEntry($entry));
                    }
                }
                $batch = Journal::prepare($siteId, $ownerId, $revision, $token, $time, $entries, $metadata);
                if ($batch['head']['phase'] === 'applied') return $batch; // Старый retry не откатывает новый мир.
                return self::apply($batch);
            }), true);
    }

    private static function validate(int $siteId, int $ownerId, array $entries): void
    {
        $sites = $knowledge = 0; $fleets = [];
        foreach ($entries as $entry) {
            $repository = self::REPOSITORIES[$entry['kind']];
            foreach (['before', 'after', 'guard'] as $side) $repository::assertCheckpointValues($entry[$side], $side === 'after');
            self::storageEntry($entry); // Границы SQL-типа проверяются до публикации.
            if ($entry['kind'] === 'site') {
                if ($entry['id'] !== $siteId) throw new \LogicException('Другой объект checkpoint.');
                ++$sites;
            } elseif ($entry['kind'] === 'knowledge') {
                if (($entry['guard']['owner_id'] ?? null) !== $ownerId || ($entry['guard']['site_id'] ?? null) !== $siteId) {
                    throw new \LogicException('Другое обследование checkpoint.');
                }
                ++$knowledge;
            } elseif ($entry['kind'] === 'fleet') {
                if (($entry['guard']['owner_id'] ?? null) !== $ownerId) throw new \LogicException('Другой владелец флота checkpoint.');
                $fleets[$entry['id']] = true;
            } elseif ($entry['kind'] === 'survey') {
                foreach (['before', 'after'] as $side) {
                    $result = json_decode($entry[$side]['state'], true, 512, JSON_THROW_ON_ERROR);
                    if (($result['mining']['siteId'] ?? null) !== $siteId || ($result['mining']['ownerId'] ?? null) !== $ownerId
                        || ($result['knowledge']['owner_id'] ?? null) !== $ownerId || ($result['knowledge']['site_id'] ?? null) !== $siteId
                        || (json_decode($result['knowledge']['state'] ?? '{}', true)['lastToken'] ?? null) !== ($entry['guard']['token'] ?? null)) {
                        throw new \LogicException('Результат обследования другого объекта/владельца.');
                    }
                }
            }
        }
        if ($sites !== 1 || $knowledge !== 1 || $fleets === []) throw new \LogicException('Неполный состав экономического checkpoint.');
        foreach ($entries as $entry) if (in_array($entry['kind'], ['command', 'ships'], true)
            && (!isset($fleets[$entry['guard']['fleet_id'] ?? 0])
                || $entry['kind'] === 'ships' && ($entry['guard']['owner_id'] ?? null) !== $ownerId)) {
            throw new \LogicException('Команда/корабли не принадлежат флоту checkpoint.');
        }
    }

    private static function runtime(array $entry): array
    {
        $repository = self::REPOSITORIES[$entry['kind']];
        return $repository::findExistingById($entry['id'])
            ?? throw new \LogicException('Отсутствует runtime-сущность checkpoint.');
    }

    private static function expectBefore(array $row, array $entry): void
    {
        $identity = $entry; $identity['after'] = $entry['before'];
        if (FleetMiningService::recoveryPatch($row, $identity) !== null) throw new \LogicException('Изменился baseline checkpoint.');
    }

    private static function expectAfter(array $row, array $entry): void
    {
        if (self::recoveryPatch($row, $entry) !== null) throw new \LogicException('Не подтверждено сохранение after checkpoint.');
        if ($entry['kind'] === 'command' && array_key_exists('execute_time', $entry['after'])) {
            // Не подтверждать неверный индекс (B006), но общий bucket близких
            // float-времён — штатный контракт BaseRepository, не повреждение.
            FleetCommands::assertTimeIndex($entry['id'], (float)$entry['before']['execute_time'], (float)$entry['after']['execute_time']);
        }
        if ($entry['kind'] === 'command' && array_key_exists('command_type', $entry['after'])) {
            $contains = static fn(array $rows): bool => in_array($entry['id'], array_column($rows, 'id'), true);
            if (!$contains(FleetCommands::findByIndex('command_type', [$entry['after']['command_type']]) ?? [])
                || $entry['before']['command_type'] !== $entry['after']['command_type']
                    && $contains(FleetCommands::findByIndex('command_type', [$entry['before']['command_type']]) ?? [])) {
                throw new \LogicException('Не подтверждён индекс типа прерванной команды добычи.');
            }
        }
    }

    private static function storageEntry(array $entry): array
    {
        $repository = self::REPOSITORIES[$entry['kind']];
        foreach (['before', 'after', 'guard'] as $side) $entry[$side] = $repository::checkpointStorageValues($entry[$side]);
        return $entry;
    }

    private static function recoveryPatch(array $row, array $entry): ?array
    {
        try { return FleetMiningService::recoveryPatch($row, $entry); }
        catch (\LogicException $rawMismatch) {
            // После restart runtime загружен из SQL. Допустим только точный
            // образ той же before/guard по схеме; любые третьи значения запрещены.
            return FleetMiningService::recoveryPatch($row, self::storageEntry($entry));
        }
    }

    private static function apply(array $batch): array
    {
        $head = $batch['head']; $entries = $batch['entries'];
        Journal::protect((int)$head['site_id'], $entries);
        $result = Journal::writing(static fn(): array => self::applyProtected($head, $entries));
        Journal::unprotect((int)$head['site_id']); // При исключении защита остаётся до успешного retry.
        return $result;
    }

    private static function applyProtected(array $head, array $entries): array
    {
        self::validate((int)$head['site_id'], (int)$head['owner_id'], $entries);
        // Смешанный durable before/after допустим. Неизвестная строка — отказ
        // ДО первого эффекта, а не молчаливое затирание стороннего изменения.
        $patches = [];
        foreach ($entries as $entry) {
            $repository = self::REPOSITORIES[$entry['kind']];
            $durable = $repository::checkpointDurableRow($entry['id'])
                ?? throw new \LogicException('Отсутствует durable-сущность checkpoint.');
            FleetMiningService::recoveryPatch($durable, self::storageEntry($entry));
            $patches[] = self::recoveryPatch(self::runtime($entry), $entry);
        }
        try {
            foreach ($entries as $i => $entry) {
                $repository = self::REPOSITORIES[$entry['kind']];
                if ($patches[$i] !== null) $repository::update($patches[$i]);
                self::expectAfter(self::runtime($entry), $entry);
            }
        } finally {
            foreach ($entries as $entry) if ($entry['kind'] === 'fleet') FleetsData::getInstance()->refresh($entry['id']);
        }
        foreach ($entries as $entry) {
            $repository = self::REPOSITORIES[$entry['kind']];
            self::expectAfter($repository::flushCheckpointRow($entry['id']), $entry);
        }
        foreach ($entries as $entry) self::expectAfter(self::runtime($entry), $entry);
        return Journal::acknowledge((int)$head['site_id'], $head['token']);
    }
}
