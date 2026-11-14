<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{Accounts, Users, Planets, Techs, Friends, Messages, PlayerQueue,
    Builds, Ships, Defenses, EntitySettings, Queues, GalaxyOrbits, PlanetResources, UserResources};
use SPGame\Game\Services\{AccountData, FriendsService, Notification};

/** До запуска сети/saver основного dev. Только чтение/отклоняемые действия;
 * новый мир, Table, аккаунт, игрок или SQL-строка не создаются.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $qa = Accounts::findByLogin('qaown_260924');
    $actor = $qa === null ? null : Users::findByIndex('account', (string)$qa['id']);
    $orphan = Users::findExistingById(2);
    if ($actor === null || ($orphan !== null && (int)$orphan['account_id'] !== 9)
        || Accounts::findExistingById(9) !== null) throw new LogicException('Не подтверждены QA и отсутствие account9.');
    $repositories = [Accounts::class, Users::class, Planets::class, Techs::class,
        Friends::class, Messages::class, PlayerQueue::class, Builds::class, Ships::class,
        Defenses::class, EntitySettings::class, Queues::class, PlanetResources::class, UserResources::class];
    $snapshot = static function () use ($repositories): array {
        $state = [];
        foreach ($repositories as $repository) {
            $sync = (new ReflectionProperty($repository, 'syncTable'))->getValue();
            $state[$repository] = [$repository::getAll(), iterator_to_array($sync)];
        }
        return $state;
    };
    // Полные before-строки сравниваются только в памяти; credentials не пишутся в результат.
    $before = $snapshot();
    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new RuntimeException('B077: ' . $label);
        ++$checks;
    };
    if ($orphan === null) {
        foreach ([Planets::class, Builds::class, Ships::class, Defenses::class, PlanetResources::class] as $repository) {
            $check($repository::findExistingById(12) === null, 'Удалённая планета/её данные не восстановлены.');
        }
        $check(Techs::findExistingById(2) === null, 'Технологии удалённого игрока не восстановлены.');
        $check(UserResources::findExistingById(2) === null, 'Нет балансов удалённого игрока.');
        $check(array_filter(EntitySettings::getAll() ?? [], static fn(array $r): bool => (int)$r['planet_id'] === 12) === [],
            'Нет настроек удалённой планеты.');
        $check(array_filter(Queues::getAll() ?? [], static fn(array $r): bool => (int)$r['user_id'] === 2 || (int)$r['planet_id'] === 12) === [],
            'Нет старых очередей B046.');
        $orbit = GalaxyOrbits::findByIndex('galaxy_system_orbit', [1, 1, 4]);
        $check($orbit !== null && (int)$orbit['type'] === GalaxyOrbits::EMPTY
            && (int)$orbit['distance'] === 3107, 'Орбита B046 свободна, её геометрия прежняя.');
    }
    foreach ([0, -1, 9, 2147483646] as $id) {
        $check(Accounts::findExistingById($id) === null, 'Тестовый ID действительно отсутствует.');
        $check(Accounts::findById($id) === null, 'Чтение аккаунта не создаёт default-строку.');
        $check(Users::findByAccount($id) === null, 'Нет lazy-игрока для отсутствующего аккаунта.');
        $check(Accounts::getResendCooldownEmail($id) === 0, 'Отсутствующий cooldown не создаёт аккаунт.');
        foreach ([static fn() => new AccountData($id), static fn() => Accounts::getAccountData($id)] as $load) {
            try { $load(); throw new LogicException('Пустая identity принята.'); }
            catch (OutOfBoundsException) { ++$checks; }
        }
        foreach (['generateToken', 'generateEmailPin'] as $method) {
            try { Accounts::$method($id); throw new LogicException('Выданы credentials отсутствующей identity.'); }
            catch (RuntimeException $error) {
                if ($error instanceof LogicException) throw $error;
                ++$checks;
            }
        }
    }
    try {
        FriendsService::change((int)$actor['id'], 'request', 2, 'B077 unavailable recipient');
        throw new LogicException('Принята заявка игроку без аккаунта.');
    } catch (InvalidArgumentException) { ++$checks; }
    Notification::sendFriendshipChange((int)$actor['id'], 2, 'request', 'B077');
    $check(Users::findExistingById(2) === $orphan, 'Состояние B046 не изменено проверкой.');
    $valid = new AccountData((int)$qa['id']);
    $check((int)$valid['User']['id'] === (int)$actor['id'], 'Существующий QA загружается штатно.');
    $check(Accounts::findById((int)$qa['id']) === $qa, 'Обычный read существующей identity прежний.');
    $check(Users::findByAccount((int)$qa['id']) === $actor, 'Обычная привязка игрока прежняя.');
    $check($snapshot() === $before, 'Все строки и dirty markers проверенных репозиториев неизменны.');
    $check(Accounts::findExistingById(9) === null, 'Фантомный аккаунт B046 не создан.');
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks,
        'orphanDeleted' => $orphan === null, 'orphanUserUnchanged' => true,
        'rowsAndDirtyMarkersUnchanged' => true], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
