<?php
declare(strict_types=1);

use SPGame\Game\Repositories\{Accounts, Users, Planets, Techs, Friends, Messages, PlayerQueue};
use SPGame\Game\Services\{AccountData, FriendsService, Notification};

/** До запуска сети/saver основного dev. Только чтение/отклоняемые действия;
 * новый мир, Table, аккаунт, игрок или SQL-строка не создаются.
 */
return static function (string $path): void {
    if (file_exists($path)) return;
    $qa = Accounts::findByLogin('qaown_260924');
    $actor = $qa === null ? null : Users::findByIndex('account', (string)$qa['id']);
    $orphan = Users::findExistingById(2);
    if ($actor === null || (int)($orphan['account_id'] ?? 0) !== 9
        || Accounts::findExistingById(9) !== null) throw new LogicException('Не подтверждены QA и исходный B046.');
    $repositories = [Accounts::class, Users::class, Planets::class, Techs::class,
        Friends::class, Messages::class, PlayerQueue::class];
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
    $check(Users::findExistingById(2) === $orphan, 'Исходный Player9 не изменён.');
    $valid = new AccountData((int)$qa['id']);
    $check((int)$valid['User']['id'] === (int)$actor['id'], 'Существующий QA загружается штатно.');
    $check(Accounts::findById((int)$qa['id']) === $qa, 'Обычный read существующей identity прежний.');
    $check(Users::findByAccount((int)$qa['id']) === $actor, 'Обычная привязка игрока прежняя.');
    $check($snapshot() === $before, 'Все строки и dirty markers проверенных репозиториев неизменны.');
    $check(Accounts::findExistingById(9) === null, 'Фантомный аккаунт B046 не создан.');
    file_put_contents($path, json_encode(['ok' => true, 'checks' => $checks,
        'orphanUserUnchanged' => true, 'rowsAndDirtyMarkersUnchanged' => true], JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
};
