<?php

declare(strict_types=1);

// Все Tables и аккаунты изолированы; MySQL и работающая игра не изменяются.
require __DIR__ . '/FleetSpecialMissionsTest.php';

use SPGame\Core\Message;
use SPGame\Game\Enums\FleetMissionType as Mission;
use SPGame\Game\Repositories\{Config, FleetAttackGroups as Groups, FleetCommands, Fleets, Messages, PlanetResources, PlayerQueue, Users};
use SPGame\Game\Services\{FleetCommandsService as Commands, FleetGroupAttackService as GroupAttack, PageActionService};

function inviteFixture(): array
{
    $f = specialFixture();
    foreach ([300 => 'Guest', 400 => 'Другой игрок'] as $id => $name) {
        $user = Users::findExistingById(100); $user['id'] = $id; $user['name'] = $name; $user['account_id'] = $id * 10;
        Users::add($user);
    }
    specialStart($f, Mission::GROUP_ATTACK, []);
    return [$f, array_values(Groups::rows())[0]];
}

$cases = [];
$cases['after departure: only creator invites non-friends; only users change'] = static function(): void {
    [$f, $group] = inviteFixture();
    $game = [Fleets::getAll(), PlanetResources::getAll(), FleetCommands::getAll()];
    $after = GroupAttack::invite(100, $group['id'], ' guest ', 101);
    transportAssert(json_decode($after['users'], true) === [100, 300], 'Приглашение без дружбы.');
    $after = GroupAttack::invite(100, $group['id'], 'Другой игрок', 102);
    transportAssert(json_decode($after['users'], true) === [100, 300, 400], 'Создатель может приглашать новых участников.');
    $expected = $group; $expected['users'] = $after['users'];
    transportAssert($expected === $after && $game === [Fleets::getAll(), PlanetResources::getAll(), FleetCommands::getAll()],
        'Не меняются deadline, флоты, топливо, ресурсы и команды.');
    $messages = array_values(Messages::getAll());
    transportAssert(count($messages) === 2 && array_column($messages, 'to_id') === [300, 400], 'По одному сообщению каждому новому игроку.');
    transportAssert(json_decode($messages[1]['data'], true)['PlayerName'] === Users::findExistingById(100)['name'],
        'В сообщении указан создатель группы.');
    $guestGroup = GroupAttack::listFor(400, 103)[0];
    transportAssert(count($guestGroup['invited']) === 3 && $guestGroup['canInvite'] === false,
        'Приглашённому видны группа и участники, но нет права приглашать.');
    transportAssert(GroupAttack::listFor(100, 103)[0]['canInvite'] === true, 'Только создателю передаётся право приглашать.');
    transportAssert(GroupAttack::listFor(999, 103) === [], 'Постороннему список не раскрывается.');
};

$cases['invalid names, duplicate, defender, outsider, expiry and user limit: no side effects'] = static function(): void {
    [$f, $group] = inviteFixture();
    GroupAttack::invite(100, $group['id'], 'Guest', 101);
    $requests = [[300, $group['id'], 'Другой игрок', 102], [400, $group['id'], 'Другой игрок', 102],
        [999, $group['id'], 'Другой игрок', 102], [100, 999, 'Другой игрок', 102], [100, '1', 'Другой игрок', 102],
        [100, $group['id'], 'Guest', 102], [100, $group['id'], Users::findExistingById(100)['name'], 102],
        [100, $group['id'], Users::findExistingById(200)['name'], 102],
        [100, $group['id'], 'Другой игрок', $group['execute_time']]];
    foreach ([null, [], 300, '', 'missing player', str_repeat('a', 33), "bad\0name", "\xff"] as $name) {
        $requests[] = [100, $group['id'], $name, 102];
    }
    foreach ($requests as $args) {
        $before = [Groups::rows(), Messages::getAll(), PlayerQueue::getAll()];
        try { GroupAttack::invite(...$args); throw new RuntimeException('Принято некорректное приглашение.'); }
        catch (InvalidArgumentException|LogicException) {}
        transportAssert($before === [Groups::rows(), Messages::getAll(), PlayerQueue::getAll()], 'Отказ без мутаций.');
    }
    Config::add(['name' => 'GroupAttackMaxUsers', 'value' => '2']);
    try { GroupAttack::invite(100, $group['id'], 'Другой игрок', 102); throw new RuntimeException('Превышен лимит игроков.'); }
    catch (LogicException) {}
    transportAssert(count(json_decode(Groups::findExistingById($group['id'])['users'], true)) === 2, 'Лимит включает создателя.');
};

$cases['cancelled empty group cannot invite or accept a new fleet'] = static function(): void {
    [$f, $group] = inviteFixture(); $second = attackClone($f, 2);
    Commands::recall($f['fleet'], $f['account'], 101);
    transportAssert(GroupAttack::listFor(100, 102) === [], 'Пустая отозванная группа не предлагается в UI.');
    try { GroupAttack::invite(100, $group['id'], 'Guest', 102); throw new RuntimeException('Приглашение в пустую группу.'); }
    catch (LogicException) {}
    try { specialStart($f, Mission::GROUP_ATTACK, ['id' => $group['id']], $second, null, 102); throw new RuntimeException('Присоединение к пустой группе.'); }
    catch (LogicException) {}
};

$cases['runtime write failure restores users; retry notifies once'] = static function(): void {
    [$f, $group] = inviteFixture();
    (new ReflectionProperty(Groups::class, 'table'))->getValue()->failedWrites = 1;
    try { GroupAttack::invite(100, $group['id'], 'Guest', 101); throw new RuntimeException('Failure injection не сработал.'); }
    catch (RuntimeException $error) { transportAssert(str_contains($error->getMessage(), 'runtime'), 'Ожидаемая ошибка записи.'); }
    transportAssert(Groups::findExistingById($group['id']) === $group && Messages::getAll() === [], 'Rollback без уведомлений.');
    GroupAttack::invite(100, $group['id'], 'Guest', 102);
    transportAssert(count(Messages::getAll()) === 1, 'Успешный повтор отправил одно приглашение.');
};

$cases['WS entry rejects invalid invite before PlayerQueue and correlates valid ACK'] = static function(): void {
    [$f, $group] = inviteFixture();
    Groups::update(['id' => $group['id'], 'execute_time' => microtime(true) + 3600]);
    foreach (['missing player', 'Guest'] as $name) {
        $message = new Message(['mode' => 'fleets', 'action' => 'InviteAttackGroup',
            'requestId' => 'invite-test', 'data' => ['groupId' => $group['id'], 'username' => $name]]);
        $response = (new Message())->setRequestId('invite-test');
        transportAssert(PageActionService::handle($message, $f['account'], 1000, $response), 'Действие обработано.');
        $raw = $response->source();
        transportAssert($raw['requestId'] === 'invite-test' && $raw['status'] === ($name === 'Guest' ? 'ok' : 'error'), 'Корреляция ACK/error.');
        transportAssert(count(PlayerQueue::getAll()) === ($name === 'Guest' ? 1 : 0), 'До очереди допускается только валидное приглашение.');
        transportAssert(Groups::findExistingById($group['id'])['users'] === '[100]', 'ACK ещё не меняет членство.');
    }
};

$cases['WS entry rejects invited non-creator before PlayerQueue without side effects'] = static function(): void {
    [$f, $group] = inviteFixture();
    Groups::update(['id' => $group['id'], 'execute_time' => microtime(true) + 3600]);
    GroupAttack::invite(100, $group['id'], 'Guest', microtime(true));
    $guestAccount = clone $f['account'];
    $guestAccount['User'] = Users::findExistingById(300);
    $before = [Groups::rows(), Messages::getAll(), PlayerQueue::getAll()];
    $message = new Message(['mode' => 'fleets', 'action' => 'InviteAttackGroup',
        'requestId' => 'guest-forbidden', 'data' => ['groupId' => $group['id'], 'username' => 'Другой игрок']]);
    $response = (new Message())->setRequestId('guest-forbidden');
    transportAssert(PageActionService::handle($message, $guestAccount, 3000, $response), 'Действие обработано.');
    $raw = $response->source();
    transportAssert($raw['status'] === 'error' && $raw['requestId'] === 'guest-forbidden'
        && $raw['error']['code'] === 'group_invitation_unavailable', 'Ручной запрос участника отклонён.');
    transportAssert($before === [Groups::rows(), Messages::getAll(), PlayerQueue::getAll()],
        'Не созданы событие, приглашение или сообщение.');
};

$cases['execution rechecks creator permission even for a previously accepted event'] = static function(): void {
    [$f, $group] = inviteFixture();
    GroupAttack::invite(100, $group['id'], 'Guest', 101);
    GroupAttack::prepareInvitation(100, $group['id'], 'Другой игрок', 102);
    // Имитируем устаревшую/внедрённую команду: users содержит actor, но он уже не owner.
    Groups::update(['id' => $group['id'], 'owner_id' => 300]);
    $before = [Groups::rows(), Messages::getAll(), PlayerQueue::getAll()];
    try { GroupAttack::invite(100, $group['id'], 'Другой игрок', 103); throw new RuntimeException('Старые полномочия приняты при исполнении.'); }
    catch (InvalidArgumentException) {}
    transportAssert($before === [Groups::rows(), Messages::getAll(), PlayerQueue::getAll()],
        'Повторная проверка owner_id отклонила команду до любых мутаций.');
};

foreach ($cases as $name => $test) { $test(); echo "OK invitation {$name}\n"; }
echo 'RESULT ' . count($cases) . " invitation cases passed\n";
