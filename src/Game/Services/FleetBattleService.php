<?php

declare(strict_types=1);

namespace SPGame\Game\Services;

use SPGame\Core\Logger;
use SPGame\Game\Repositories\PlanetBattles;
use SPGame\Game\Repositories\SpaceBattles;
use SPGame\Game\Repositories\PlayerQueue;
use SPGame\Game\Repositories\Queues;
use SPGame\Game\Repositories\Users;
use SPGame\Game\Repositories\WorkerMailbox;

/**
 * Координатор раундов: gameplay меняет только игровой цикл, TaskWorker только считает.
 * Это НЕ обработчик ATTACK: списание потерь/добыча требуют отдельного commit адаптера.
 */
final class FleetBattleService
{
    public const ROUND_SECONDS = 1.0;
    public const JOB_TIMEOUT = 60.0;
    public const MAILBOX_CAPACITY = 32;
    private const MAX_ATTEMPTS = 3;
    // В памяти координатора максимум 8 исходных JSON по 256 КиБ и их заголовки.
    private const HEADER_CACHE_SLOTS = 8;
    private static array $headerCache = [];
    private static ?WorkerMailbox $results = null;
    private static ?\Closure $dispatch = null;
    private static int $workerLimit = 2;

    /** Вызывать ДО Server::start(), чтобы mailbox был общим для процессов. */
    public static function initializeWorkers(callable $dispatch, int $workers = 2): void
    {
        if ($workers < 1 || $workers > 8) throw new \InvalidArgumentException('Число боевых процессов должно быть от 1 до 8.');
        self::$dispatch = \Closure::fromCallable($dispatch);
        self::$workerLimit = $workers;
        self::$headerCache = [];
        self::$results = new WorkerMailbox(self::MAILBOX_CAPACITY, PlanetBattles::MAX_STATE_BYTES);
    }

    /** Backend API: группы уже подготовлены с технологиями/опытом на момент вступления. */
    public static function begin(
        int $planetId, array $attackers, array $defenders, float $startedAt,
        array $rapidfire = [], array $fleetIds = [], array $mission = []
    ): string {
        if ($planetId <= 0) throw new \InvalidArgumentException('Некорректная планета боя.');
        self::assertPlanetAvailable($planetId);
        return self::beginAt(PlanetBattles::class, $planetId, $attackers, $defenders, $startedAt, $rapidfire, $fleetIds, $mission);
    }

    public static function beginSpace(int $battleKey, array $attackers, array $defenders, float $startedAt,
        array $rapidfire = [], array $fleetIds = [], array $mission = []): string
    {
        if ($battleKey <= 0 || SpaceBattles::state($battleKey) !== null) {
            throw new \LogicException('ID checkpoint боя уже занят.');
        }
        foreach ($fleetIds as $id) self::assertFleetAvailable($id);
        return self::beginAt(SpaceBattles::class, $battleKey, $attackers, $defenders, $startedAt, $rapidfire, $fleetIds, $mission);
    }

    private static function beginAt(string $repository, int $key, array $attackers, array $defenders, float $startedAt,
        array $rapidfire, array $fleetIds, array $mission): string
    {
        self::validTime($startedAt);
        $combat = FleetCombatService::startBattle($attackers, $defenders, $rapidfire);
        $state = ['id' => bin2hex(random_bytes(16)), 'phase' => 'running', 'startedAt' => $startedAt,
            'seed' => bin2hex(random_bytes(32)), 'combat' => $combat, 'pending' => [], 'admissions' => [],
            'fleets' => self::fleetIds($fleetIds), 'job' => null, 'attempts' => 0];
        if ($mission !== []) $state['mission'] = $mission;
        if (FleetCombatService::isFinished($combat)) $state['phase'] = 'finished';
        $repository::store($key, $state);
        return $state['id'];
    }

    /**
     * arrivalId — стабильный ID события прибытия. Повтор того же события идемпотентен.
     * Ровно на границе раунда: входит, если snapshot раунда ещё не отправлен.
     */
    public static function reinforce(
        int $planetId, string $battleId, string $arrivalId, string $side,
        array $groups, float $arrivedAt, array $fleetIds = [], ?array $participant = null
    ): int {
        return self::reinforceAt(PlanetBattles::class, $planetId, $battleId, $arrivalId, $side, $groups, $arrivedAt, $fleetIds, $participant);
    }

    public static function reinforceSpace(int $key, string $battleId, string $arrivalId, string $side,
        array $groups, float $arrivedAt, array $fleetIds = [], ?array $participant = null): int
    {
        foreach ($fleetIds as $id) {
            $existing = SpaceBattles::forFleet($id);
            if ($existing !== null && ($existing['key'] !== $key || $existing['state']['id'] !== $battleId)) {
                throw new \LogicException('Подкрепление уже участвует в другом бою.');
            }
            if ($existing === null) self::assertFleetAvailable($id);
        }
        return self::reinforceAt(SpaceBattles::class, $key, $battleId, $arrivalId, $side, $groups, $arrivedAt, $fleetIds, $participant);
    }

    private static function reinforceAt(string $repository, int $planetId, string $battleId, string $arrivalId, string $side,
        array $groups, float $arrivedAt, array $fleetIds, ?array $participant): int
    {
        self::validTime($arrivedAt);
        $state = self::requireBattle($planetId, $battleId, $repository);
        if ($arrivalId === '' || !in_array($side, ['attacker', 'defender'], true) || $groups === []) {
            throw new \InvalidArgumentException('Некорректное подкрепление.');
        }
        $fleetIds = self::fleetIds($fleetIds);
        $fingerprint = hash('sha256', serialize([$side, $groups, $arrivedAt, $fleetIds]));
        if (isset($state['admissions'][$arrivalId])) {
            $previous = $state['admissions'][$arrivalId];
            if ($previous['fingerprint'] !== $fingerprint) throw new \LogicException('Изменены данные уже принятого подкрепления.');
            return $previous['round'];
        }
        if ($state['phase'] !== 'running' || $arrivedAt < $state['startedAt']) {
            throw new \LogicException('Подкрепление не может вступить в этот бой.');
        }
        $round = count($state['combat']['rounds']) + ($state['job'] === null ? 1 : 2);
        if ($round > $state['combat']['roundLimit']) throw new \LogicException('В этом бою больше не осталось раундов.');
        // Проверяем все pending вместе, чтобы не принять повторную группу до её активации.
        $probe = $state['combat'];
        foreach ($state['pending'] as $pending) {
            $probe = FleetCombatService::addReinforcements($probe, $pending['side'], $pending['groups']);
        }
        FleetCombatService::addReinforcements($probe, $side, $groups);
        $state['pending'][] = ['side' => $side, 'groups' => $groups, 'round' => $round, 'arrivedAt' => $arrivedAt];
        $state['admissions'][$arrivalId] = ['fingerprint' => $fingerprint, 'round' => $round];
        $state['fleets'] = array_values(array_unique(array_merge($state['fleets'], $fleetIds)));
        if ($participant !== null) {
            $participant['joinedRound'] = $round;
            $state['mission']['participants'][(int)$participant['fleetId']] = $participant;
        }
        $repository::store($planetId, $state);
        return $round;
    }

    /** После due Fleet arrivals; возвращает аккаунты для обычного ActualData при смене публичного состояния. */
    public static function tick(float $now): array
    {
        self::validTime($now);
        if (self::$results === null || self::$dispatch === null) return [];
        $changedOwners = [];
        // Общий busy нужен до первого dispatch. Сохраняем порядок и короткие
        // заголовки, а не все combat/rounds/pending каждого боя одновременно.
        $active = []; $refresh = []; $busy = 0; $knownTokens = []; $unreadable = false;
        $runningKeys = []; $cacheSlots = [];
        foreach ([PlanetBattles::class, SpaceBattles::class] as $repository) {
            $keys = $repository::runningKeys();
            $runningKeys[$repository] = array_fill_keys($keys, null);
            foreach (array_slice($keys, 0, self::HEADER_CACHE_SLOTS - count($cacheSlots)) as $key) {
                $cacheSlots[$repository . ':' . $key] = null;
            }
        }
        self::$headerCache = array_intersect_key(self::$headerCache, $cacheSlots) + $cacheSlots;
        foreach (self::battleHeaders($runningKeys, $unreadable) as $entry) {
            if ($entry['token'] !== null) {
                ++$busy;
                $knownTokens[$entry['token']] = true;
            }
            $active[] = $entry;
        }
        unset($entry, $runningKeys, $keys, $cacheSlots);
        // Worker не создаёт резервы, только публикует в уже существующие.
        // Если каждый резерв известен из running, остальные phase не владеют
        // неизвестным ответом и их большие JSON для расчёта раундов не нужны.
        $unknownTokens = array_diff_key(self::$results->tokens(), $knownTokens);
        if ($unknownTokens !== []) {
            $nonRunningKeys = [];
            foreach ([PlanetBattles::class, SpaceBattles::class] as $repository) {
                $nonRunningKeys[$repository] = array_fill_keys($repository::nonRunningKeys(), null);
            }
            foreach (self::battleHeaders($nonRunningKeys, $unreadable) as $entry) {
                // Только валидация: нечитаемая строка может скрывать владельца
                // неизвестного резерва. Не сохраняем её state или заголовок.
            }
            unset($entry, $nonRunningKeys);
        }
        // Нечитаемый checkpoint мог владеть ещё работающим job. Его резерв
        // учитываем дополнительно, но известные job не считаем второй раз.
        if ($unreadable) $busy += count($unknownTokens);
        unset($unknownTokens);
        foreach ($active as $entry) {
            ['repository' => $repository, 'key' => $planetId] = $entry;
            try {
                // Плохой заголовок (readyAt=null) проверяет прежний catch боя.
                if (!$entry['running'] || ($entry['token'] === null && $entry['readyAt'] !== null
                    && ($now < $entry['readyAt'] || $busy >= self::$workerLimit))) continue;
                // Во время tick checkpoint меняет лишь координатор; TaskWorkers
                // пишут только mailbox. Для retain перепроверяем затронутые строки,
                // а не разбираем второй раз все неизменившиеся combat JSON.
                $refresh[$repository][$planetId] = $entry['token'];
                $reply = null;
                if ($entry['token'] !== null && $entry['waitUntil'] !== null) {
                    $reply = self::$results->read($entry['token']);
                    if ($reply === null && $now < $entry['waitUntil']) {
                        // Нет ответа и timeout ещё не наступил: checkpoint не
                        // меняется, его исходный token остаётся в knownTokens.
                        unset($refresh[$repository][$planetId]);
                        continue;
                    }
                }
                $state = $repository::state($planetId);
                if ($state === null) throw new \LogicException('Потеряна запись текущего боя.');
                if ($state['phase'] !== 'running') continue;
                $job = $state['job'];
                if ($job !== null) {
                    if ($entry['waitUntil'] === null || $job['token'] !== $entry['token']) {
                        $reply = self::$results->read($job['token']);
                    }
                    if ($reply !== null) {
                        $reply = json_decode($reply, true, 512, JSON_THROW_ON_ERROR);
                        if (isset($reply['error'])) throw new \RuntimeException($reply['error']);
                        if (count($reply['combat']['rounds'] ?? []) !== $job['round']) {
                            throw new \LogicException('Боевой процесс вернул неверный номер раунда.');
                        }
                        $state['combat'] = $reply['combat'];
                        $state['job'] = null;
                        $state['attempts'] = 0;
                        if (FleetCombatService::isFinished($state['combat'])) $state['phase'] = 'finished';
                        $repository::store($planetId, $state);
                        self::$results->release($job['token']);
                        --$busy;
                        if ($state['phase'] !== 'running') {
                            self::noteProgress($state, $changedOwners);
                            continue;
                        }
                    } else {
                        if ($now < $job['submittedAt'] + self::JOB_TIMEOUT) continue;
                        self::$results->release($job['token']);
                        $state['job'] = null;
                        --$busy;
                        Logger::getInstance()->error('Истёк срок расчёта раунда боя', ['scope' => $repository, 'battleKey' => $planetId, 'round' => $job['round']]);
                        if ($state['attempts'] >= self::MAX_ATTEMPTS) $state['phase'] = 'failed';
                        $repository::store($planetId, $state);
                        self::noteProgress($state, $changedOwners);
                        continue;
                    }
                }
                $round = count($state['combat']['rounds']) + 1;
                if ($now < $state['startedAt'] + $round * self::ROUND_SECONDS || $busy >= self::$workerLimit) continue;
                $token = bin2hex(random_bytes(16));
                // Заполненный mailbox — backpressure, а не неудачный раунд.
                if (!self::$results->reserve($token)) continue;
                try {
                    $waiting = [];
                    foreach ($state['pending'] as $pending) {
                        if ($pending['round'] <= $round && ($pending['arrivedAt'] ?? 0) <= $now) {
                            $state['combat'] = FleetCombatService::addReinforcements($state['combat'], $pending['side'], $pending['groups']);
                        } else $waiting[] = $pending;
                    }
                    $state['pending'] = $waiting;
                    $state['job'] = ['token' => $token, 'round' => $round, 'submittedAt' => $now];
                    ++$state['attempts'];
                    // Сначала snapshot фиксируется в runtime, потом доставляется чистому вычислителю.
                    $repository::store($planetId, $state);
                    ++$busy;
                    if (!(self::$dispatch)(['kind' => 'combat_round', 'token' => $token,
                        'combat' => $state['combat'], 'seed' => hash_hmac('sha256', (string)$round, $state['seed'])])) {
                        throw new \RuntimeException('Не удалось передать раунд боевому процессу.');
                    }
                } catch (\Throwable $error) {
                    self::$results->release($token);
                    throw $error;
                }
                self::noteProgress($state, $changedOwners);
            } catch (\Throwable $error) {
                // Fail closed: не снимаем защиту и не применяем частичный результат.
                // После неудачной записи берём последний принятый checkpoint,
                // а не потенциально переполненный/частично подготовленный результат.
                try {
                    $state = $repository::state($planetId);
                    if ($state === null) throw new \LogicException('Потеряна запись текущего боя.');
                    $state['phase'] = 'failed';
                    $state['error'] = substr($error->getMessage(), 0, 512);
                    try { $repository::store($planetId, $state); }
                    catch (\OverflowException) {
                        // Даже сообщение ошибки не должно мешать fail-closed marker.
                        unset($state['error']);
                        $repository::store($planetId, $state);
                    }
                    self::noteProgress($state, $changedOwners);
                }
                catch (\Throwable $saveError) {
                    Logger::getInstance()->critical('Не удалось сохранить ошибку боя', ['scope' => $repository, 'battleKey' => $planetId, 'message' => $saveError->getMessage()]);
                }
                Logger::getInstance()->error('Расчёт раунда боя остановлен', ['scope' => $repository, 'battleKey' => $planetId, 'message' => $error->getMessage()]);
            }
        }
        unset($active, $entry, $state, $reply);
        // Поздний ответ старого задания не может перезаписать новую попытку.
        $tokens = $knownTokens;
        foreach ($refresh as $changed) foreach ($changed as $oldToken) {
            if ($oldToken !== null) unset($tokens[$oldToken]);
        }
        foreach (self::battleHeaders($refresh, $unreadable, false) as $entry) {
            if ($entry['token'] !== null) $tokens[$entry['token']] = true;
        }
        // При неполном чтении нельзя отличить orphan от резерва повреждённого
        // боя. Отложим только GC mailbox, не расчёт исправных боёв.
        if (!$unreadable) self::$results->retain($tokens);
        // Доставка остаётся в EventLoop; поиск аккаунтов не относится к расчёту
        // раунда и не должен переводить сам бой в failed при ошибке уведомления.
        $accounts = [];
        foreach (array_keys($changedOwners) as $ownerId) {
            $user = Users::findExistingById((int)$ownerId);
            if ((int)($user['account_id'] ?? 0) > 0) $accounts[(int)$user['account_id']] = true;
        }
        return array_keys($accounts);
    }

    private static function noteProgress(array $state, array &$owners): void
    {
        $mission = $state['mission'] ?? [];
        foreach ([$mission['attackerId'] ?? 0, $mission['defenderId'] ?? 0,
            ...array_column($mission['participants'] ?? [], 'ownerId'),
            ...array_column($mission['waiting'] ?? [], 'ownerId')] as $ownerId) {
            if ((int)$ownerId > 0) $owners[(int)$ownerId] = true;
        }
    }

    /** Запускается только в TaskWorker; не читает gameplay repositories. */
    public static function executeTask(array $task): void
    {
        if (($task['kind'] ?? null) !== 'combat_round' || !is_string($task['token'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $task['token'])) {
            throw new \InvalidArgumentException('Некорректное задание боевого процесса.');
        }
        try {
            $seed = hex2bin($task['seed']);
            $random = new \Random\Randomizer(new \Random\Engine\Xoshiro256StarStar($seed));
            $reply = ['combat' => FleetCombatService::calculateRound($task['combat'],
                static fn(int $min, int $max): int => $random->getInt($min, $max))];
            $encoded = json_encode($reply, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($encoded) > PlanetBattles::MAX_STATE_BYTES) throw new \OverflowException('Результат раунда превышает вместимость mailbox.');
        } catch (\Throwable $error) {
            $encoded = json_encode(['error' => substr($error->getMessage(), 0, 512)], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (self::$results === null) throw new \LogicException('Mailbox боевых процессов не инициализирован.');
        self::$results->publish($task['token'], $encoded);
    }

    /** Вызывать ТОЛЬКО после успешного, идемпотентного gameplay commit адаптера ATTACK. */
    public static function releaseAfterCommit(int $planetId, string $battleId): void
    {
        self::releaseAt(PlanetBattles::class, $planetId, $battleId);
    }

    public static function releaseSpaceAfterCommit(int $key, string $battleId): void
    {
        self::releaseAt(SpaceBattles::class, $key, $battleId);
    }

    private static function releaseAt(string $repository, int $planetId, string $battleId): void
    {
        $state = self::requireBattle($planetId, $battleId, $repository);
        if ($state['phase'] === 'applied') return;
        if (!in_array($state['phase'], ['finished', 'settled'], true)) throw new \LogicException('Нельзя снять защиту незавершённого боя.');
        $state['phase'] = 'applied';
        $repository::store($planetId, $state);
    }

    public static function assertPlanetAvailable(int $planetId): void
    {
        $row = PlanetBattles::findById($planetId);
        if ($row !== null && $row['phase'] !== 'applied') {
            throw new \LogicException('На планете идёт бой: изменение очередей и вывоз ресурсов недоступны.');
        }
    }

    public static function assertFleetAvailable(int $fleetId, bool $allowMining = false): void
    {
        \SPGame\Game\Repositories\MiningCheckpoints::assertMutable('fleets', $fleetId);
        FleetExpeditionService::assertAvailable($fleetId);
        FleetSurveyService::assertAvailable($fleetId);
        if (!$allowMining) FleetMiningRuntimeService::assertAvailable($fleetId);
        if (PlanetBattles::forFleet($fleetId) !== null) throw new \LogicException('Флот участвует в бою.');
        if (SpaceBattles::forFleet($fleetId) !== null) throw new \LogicException('Флот участвует в бою в пространстве.');
    }

    /** Проверяется и при приёме request, и при исполнении ранее принятого PlayerQueue event. */
    public static function assertQueueAction(int $planetId, ?int $queueId = null): void
    {
        self::assertPlanetAvailable($planetId);
        if ($queueId !== null) {
            $queue = Queues::findExistingById($queueId);
            if ($queue !== null) self::assertPlanetAvailable((int)$queue['planet_id']);
        }
    }

    public static function isQueueAction(string $action): bool
    {
        return in_array($action, [PlayerQueue::ActionQueueUpgarde, PlayerQueue::ActionQueueDismantle,
            PlayerQueue::ActionQueueCancel, PlayerQueue::ActionQueueHangarAdd, PlayerQueue::ActionQueueHangarCancel], true);
    }

    public static function assertPlayerMutation(string $action, int $planetId, array $data): void
    {
        if (self::isQueueAction($action)) {
            self::assertQueueAction($planetId, isset($data['QueueId']) ? (int)$data['QueueId'] : null);
        }
        if (in_array($action, [PlayerQueue::ActionFleetToOrbit, PlayerQueue::ActionFleetMoveShip,
            PlayerQueue::ActionFleetDisband, PlayerQueue::ActionFleetSendMission], true)) {
            self::assertPlanetAvailable($planetId);
            foreach (['FleetId', 'fleetId', 'fromFleetId', 'toFleetId'] as $key) {
                if ((int)($data[$key] ?? 0) > 0) self::assertFleetAvailable((int)$data[$key]);
            }
        }
    }

    private static function battleHeaders(array $selected, bool &$unreadable, bool $logErrors = true): \Generator
    {
        foreach ([PlanetBattles::class, SpaceBattles::class] as $repository) foreach (($selected[$repository] ?? []) as $key => $_) {
            try {
                $row = $repository::findExistingById($key);
                if ($row === null || $row['phase'] === 'applied') continue;
                $slot = $repository . ':' . $key;
                $cached = self::$headerCache[$slot] ?? null;
                if ($cached !== null && $row['phase'] === 'running' && $row['state'] === $cached['raw']) {
                    $header = $cached['header'];
                } else {
                    $header = self::decodeBattleHeader($row);
                    if (array_key_exists($slot, self::$headerCache)) {
                        self::$headerCache[$slot] = $header['running'] ? ['raw' => $row['state'], 'header' => $header] : null;
                    }
                }
            } catch (\Throwable $error) {
                $unreadable = true;
                if ($logErrors) Logger::getInstance()->error('Нечитаемый checkpoint боя пропущен',
                    ['scope' => $repository, 'battleKey' => $key, 'exception' => $error::class]);
                continue;
            }
            unset($row, $cached);
            yield ['repository' => $repository, 'key' => $key] + $header;
        }
    }

    private static function decodeBattleHeader(array $row): array
    {
        $state = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !in_array($state['phase'] ?? null, ['running', 'finished', 'settled', 'failed'], true)
            || $state['phase'] !== $row['phase']) {
            throw new \UnexpectedValueException('Некорректный заголовок checkpoint боя.');
        }
        $running = $state['phase'] === 'running';
        $job = $running ? ($state['job'] ?? null) : null;
        if ($running && (!array_key_exists('job', $state) || ($job !== null
            && (!is_array($job) || !is_string($job['token'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $job['token']))))) {
            throw new \UnexpectedValueException('Некорректный заголовок checkpoint боя.');
        }
        $readyAt = null;
        if ($running && $job === null && is_numeric($state['startedAt'] ?? null) && is_array($state['combat']['rounds'] ?? null)) {
            $readyAt = $state['startedAt'] + (count($state['combat']['rounds']) + 1) * self::ROUND_SECONDS;
        }
        $deadline = is_numeric($job['submittedAt'] ?? null) ? (float)$job['submittedAt'] + self::JOB_TIMEOUT : null;
        return ['running' => $running, 'readyAt' => $readyAt, 'token' => $job['token'] ?? null,
            'waitUntil' => $deadline !== null && is_finite($deadline) ? $deadline : null];
    }

    private static function requireBattle(int $planetId, string $battleId, string $repository = PlanetBattles::class): array
    {
        $state = $repository::state($planetId);
        if ($state === null || !hash_equals($state['id'], $battleId)) throw new \LogicException('Бой недоступен или уже заменён новым.');
        return $state;
    }

    private static function fleetIds(array $ids): array
    {
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Некорректный ID участника боя.');
        }
        return array_values(array_unique($ids));
    }

    private static function validTime(float $time): void
    {
        if (!is_finite($time) || $time <= 0) throw new \InvalidArgumentException('Некорректное время события боя.');
    }
}
