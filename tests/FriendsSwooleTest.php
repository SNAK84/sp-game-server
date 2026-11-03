<?php

declare(strict_types=1);

define('SP_GAME_DEFEND_FIXTURE_ONLY', true);
require __DIR__ . '/FleetDefendSwooleTest.php';

use SPGame\Core\{Database, Message};
use SPGame\Game\Pages\{FriendsPage, GalaxyPage};
use SPGame\Game\Repositories\{Friends, Messages, OrbitalResourceSites};
use SPGame\Game\Services\{FriendsService as Service};

function friendsFixture(): void
{
    defendFixture();
    Friends::update(['id' => 1, 'status' => Friends::REMOVED]);
}

researchCase('Заявка: текст, координаты, роль и состояние пары без раскрытия посторонним', static function (): void {
    friendsFixture();
    $text = "Привет 👋\nПредлагаю дружбу <script>не HTML</script>";
    Service::change(1, 'request', 2, $text);
    $out = Service::list(1)[0]; $in = Service::list(2)[0];
    researchAssert($out['text'] === $text && $in['text'] === $text, 'Текст потерян или исполнен как HTML.');
    researchAssert(!$out['incoming'] && $in['incoming'] && !$out['accepted'] && !$in['accepted'], 'Перепутаны стороны.');
    researchAssert($out['coordinates'] === ['galaxy' => 1, 'system' => 1, 'orbit' => 2], 'Неверные координаты.');
    researchAssert($out['createdAt'] > 0 && $out['online'] === null, 'Заявка раскрывает активность друга до принятия.');
    researchAssert(Service::relationship(1, 2) === ['id' => 1, 'state' => 'outgoing']
        && Service::relationship(2, 1) === ['id' => 1, 'state' => 'incoming'], 'Нет состояния для галактики.');
    researchAssert(Service::relationship(3, 2) === ['state' => 'none'] && Service::list(3) === [], 'Раскрыта чужая заявка.');
    researchAssert(Service::relationship(1, 1) === ['state' => 'self'], 'Есть заявка самому себе.');
    researchAssert(Friends::getPendingSync()['update'] === [1], 'Нет dirty marker текста.');
});

researchCase('Валидация текста до мутации: длинный, неверный тип, NUL; допустимые 500 символов', static function (): void {
    friendsFixture();
    $row = Friends::findExistingById(1);
    foreach ([str_repeat('я', 501), true, 123, [], "x\0y", "\xFF"] as $text) {
        defendRejected(static fn() => Service::change(1, 'request', 2, $text));
        researchAssert(Friends::findExistingById(1) === $row && Messages::getAll() === [], 'Невалидный текст создал побочные эффекты.');
    }
    Service::change(1, 'request', 2, str_repeat('я', 500));
    researchAssert(mb_strlen(Service::list(2)[0]['text']) === 500, 'Допустимый текст обрезан.');
});

researchCase('Дубликат и встречная заявка не создают новые строки или уведомления', static function (): void {
    friendsFixture(); Service::change(1, 'request', 2, 'Первый текст');
    $row = Friends::findExistingById(1); $messages = Messages::getAll();
    defendRejected(static fn() => Service::change(1, 'request', 2, 'Дубль'));
    defendRejected(static fn() => Service::change(2, 'request', 1, 'Встречная'));
    researchAssert(count(Friends::getAll()) === 1 && Friends::findExistingById(1) === $row
        && Messages::getAll() === $messages, 'Дубликат имеет побочный эффект.');
});

researchCase('Только адресат принимает: уведомления request/accepted и доступность обороны', static function (): void {
    friendsFixture(); Service::change(1, 'request', 2, 'Давай дружить');
    $message = array_values(Messages::getAll())[0];
    researchAssert($message['to_id'] === 2 && $message['sample'] === 'FriendshipChange'
        && $message['subject'] === 'subject.Friends.request', 'Нет уведомления адресату.');
    defendRejected(static fn() => Service::change(1, 'accept', 1));
    defendRejected(static fn() => Service::change(3, 'accept', 1));
    researchAssert(!Friends::areFriends(1, 2), 'Заявка уже разрешает оборону.');
    Service::change(2, 'accept', 1);
    researchAssert(Friends::areFriends(1, 2) && Service::relationship(1, 2)['state'] === 'friends', 'Нет принятой дружбы.');
    $messages = array_values(Messages::getAll());
    researchAssert(count($messages) === 2 && $messages[1]['to_id'] === 1
        && $messages[1]['subject'] === 'subject.Friends.accepted', 'Нет уведомления отправителю.');
});

researchCase('Отклонение, отмена, удаление: корректный получатель; повторное действие без спама', static function (): void {
    foreach (['rejected' => 2, 'cancelled' => 1, 'removed' => 2] as $event => $actor) {
        friendsFixture(); Service::change(1, 'request', 2);
        if ($event === 'removed') Service::change(2, 'accept', 1);
        defendRejected(static fn() => Service::change(3, 'remove', 1));
        Service::change($actor, 'remove', 1);
        $messages = array_values(Messages::getAll()); $last = end($messages);
        researchAssert($last['subject'] === 'subject.Friends.' . $event && $last['to_id'] !== $actor, 'Неверное уведомление: ' . $event);
        defendRejected(static fn() => Service::change($actor, 'remove', 1));
        researchAssert(Messages::getAll() === array_column($messages, null, 'id'), 'Повтор удаления создаёт спам.');
        Service::change(2, 'request', 1, 'Новая заявка');
        researchAssert(count(Friends::getAll()) === 1 && Service::list(1)[0]['text'] === 'Новая заявка'
            && Service::relationship(1, 2)['state'] === 'incoming', 'Повторная заявка наследует старое состояние.');
    }
});

researchCase('Friends Page: сырой Message сохраняет текст, reconnect читает список', static function (): void {
    friendsFixture(); $account = new \SPGame\Game\Services\AccountData(1);
    $message = (new Message())->setMode('friends')->setAction('request')->setData('id', 2)->setData('text', 'Текст из SPA');
    $page = (new FriendsPage($message))->render($account);
    researchAssert($page['Friends'][0]['text'] === 'Текст из SPA' && $page['FriendRequestMaxLength'] === 500, 'Page теряет текст/лимит.');
    $page = (new FriendsPage((new Message())->setMode('friends')->setAction('reconnect')))->render($account);
    researchAssert($page['Friends'][0]['text'] === 'Текст из SPA', 'Reconnect не восстановил текст.');
});

researchCase('Galaxy snapshot: свое/чужое состояние, без текста чужих заявок', static function (): void {
    friendsFixture(); Service::change(1, 'request', 2, 'Личное сообщение');
    researchRepository(OrbitalResourceSites::class, []);
    $account = new \SPGame\Game\Services\AccountData(1);
    $page = (new GalaxyPage((new Message())->setMode('galaxy')->setData('galaxy', 1)->setData('system', 1)))->render($account);
    $planets = array_column($page['Planets'], null, 'owner_id');
    researchAssert($planets[1]['Friendship'] === ['state' => 'self'], 'Есть кнопка дружбы с собой.');
    researchAssert($planets[2]['Friendship'] === ['id' => 1, 'state' => 'outgoing'], 'Нет статуса исходящей в галактике.');
    researchAssert($planets[3]['Friendship'] === ['state' => 'none'] && !isset($planets[2]['Friendship']['text']), 'Лишние данные в Galaxy.');
    researchAssert($page['FriendRequestMaxLength'] === 500, 'Galaxy не передаёт лимит текста.');
});

echo "RESULT {$passed} passed / {$failed} failed; SQL calls=" . Database::$calls . "\n";
exit($failed > 0 || Database::$calls > 0 ? 1 : 0);
