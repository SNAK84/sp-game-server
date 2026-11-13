# SP-Game protocol v2 — текущий игровой API

03.11.2026: **игра целиком переключена на v2**. Сервер не принимает v1,
основной SPA и штатный Runner бота используют v2. Параллельного игрового
режима v1 и автоматического fallback нет. После обновления нужна перезагрузка
старой вкладки. API кабинета не менялся.

## Контракт и каталог

- JSON Schema 2020-12: [request](request.schema.json),
  [response](response.schema.json), [common](common.schema.json).
  URN-ссылки регистрируются локально, без сетевой загрузки схем.
- Источник списка операций: [ProtocolOperations.php](/opt/sp-game/src/Game/Services/ProtocolOperations.php).
  19 запросов ресурсов, 30 команд и запрос command.status.get.
- Адаптеры существующих UI/сценариев: GameOperations.js и app/operations.py.
  Их mode/action — **локальные имена виджетов**, не поля сетевого протокола.
  В сеть уходят только именованные query/command/subscribe.
- Все работающие экраны переключены: обзор, постройки, исследования, верфь,
  оборона, галактика, флот, сообщения и друзья. Также справка, доклады,
  архив, предпросмотр миссий/маршрутов и сканирование фалангой.
- Чат, несуществующие разделы меню и новые игровые возможности этим
  переключением не реализуются и не объявляются в capabilities.

| Ресурсные запросы | Команды |
| --- | --- |
| player.planets.get, player.status.get, localization.get | planet.select, planet.rename |
| overview.get, buildings.get | building.upgrade, building.dismantle, building.cancel |
| research.get | research.start, research.cancel |
| shipyard.get, defense.get | shipyard.build/cancel, defense.build/cancel |
| galaxy.system.get | phalanx.scan |
| fleets.get, fleet.missions.get, fleet.navigation.get | fleet.create, fleet.ships.transfer, fleet.disband, fleet.rename |
| fleet.commands.get, fleet.missions.catalog.get | fleet.send, fleet.recall, fleet.stop, fleet.resume, fleet.route.set, fleet.sensors.set, fleet.group.invite |
| messages.get | messages.read, messages.delete |
| friends.get | friend.request, friend.accept, friend.remove |
| information.get, combat.report.get, combat.reports.get | combat.report.save, combat.report.unsave |

### Страницы сообщений

`messages.get` (query и subscribe) принимает необязательные `data.page`
(целое1–1 000 000, по умолчанию1) и `data.category` (число из MessageType,
по умолчанию99 — все). Сервер возвращает максимум20 тел в `allMessages`,
`pagination={page,size,pages,total,category}` и `messageCounts` со счётчиками
`{total,unread}` для **всей** почты по каждой категории, включая99.
Строки упорядочены по time DESC,id DESC; равное время не перемешивает страницы.
Номер за последней страницей ограничивается последней, для пустой категории
возвращается страница1 с пустым списком. read/delete сохраняют текущие параметры
подписки SPA. По решению пользователя вся живая история за7 дней остаётся
в RAM: компактные метаданные/inline и ограниченный пул тел, MySQL для
долговечности. Старые сообщения удаляются порциями из обоих хранилищ;
сохранённые BattleReports не удаляются вместе с письмами. SQL cold fallback
не добавлен. Физическая раскладка не меняет этот wire-контракт; размеры,
проверки и ограничения — [в плане стабилизации](../capacity-hardening-plan.md).

### Страницы истории флотов

С11.11.2026 `fleets.get` (query/subscribe) принимает необязательный объект
`data.historyPages`, например `{"37":2}`. Ключ — ID собственного флота,
значение — целое1–1 000 000; максимум32 ключа. Чужой существующий флот даёт
`resource.unavailable`; уже удалённый/объединённый ID игнорируется, чтобы
сохранённая подписка продолжала работать. Без номера выбирается страница
с первой незавершённой командой, либо последняя, если всё выполнено.

В карточке `commands` содержит не более50 команд, `commands_page` —
`{page,pages,size:50,total}`. Номер за концом ограничивается последней страницей;
для пустой истории page/pages=1. `commands_total`, `commands_left`,
`has_pending_move`, `control.version`, этапы редактора и прогресс относятся
ко всему рейсу, а не выбранной странице. Отменённые команды по-прежнему
не выводятся, но остаются в authoritative RAM-истории и участвуют в версии.

SPA меняет параметры существующей подписки, сохраняет страницу при push,
собственных командах и reconnect; карточка/пагинатор не пересоздаются.
Нового опроса или отдельного канала истории нет. Тела команд остаются в RAM
до штатного завершения/очистки рейса. MySQL — загрузка при старте и снимки
saver, без cold-read при перелистывании. Метки прогресса ограничены256,
детализация текущего плеча —50 сегментами; итоговая доля/число сегментов
точные, все отдельные команды доступны через страницы истории.

`fleet.route.set.data.plan` — объект `{version,steps,fuel?,resources?}`, не
список. `version` — SHA256 текущей цепочки; предметная проверка этапов,
технологий, миссий и топлива выполняется сервером при исполнении команды.

## Соединение и авторизация

Один прежний WSS endpoint, JSON, максимум входного пакета 16 KiB.
Первым пакетом обязателен hello с v=2 и data.versions=[2], затем auth с
единственным data.gameToken. Пароли, PIN, кабинетские access/refresh и ключ
межсерверного API в игровом соединении не передаются.

В auth/world validation используется существующий CabinetWorldService.
Actor определяется разрешением кабинета, не клиентскими playerId/accountId.
На общий аккаунт остаётся одна игровая сессия во всех мирах. Новый явный вход
в мир завершает предыдущую; reconnect с тем же grant не создаёт новую сессию.

Все запросы: v, type, UUID requestId, worldId; hello допускает отсутствие
worldId. Каждый ответ: v, type, requestId, worldId, connectionId, serverEpoch,
serverTime. У фонового события requestId=null. connectionId/epoch — 32 hex,
serverTime/observedAt — Unix seconds с дробной частью.

hello объявляет полные списки queries/commands/subscriptions,
authentication=cabinet.gameToken, snapshotOrdering=resource.revision,
idType=decimal-string, replay=false. authResult содержит state=authenticated,
accountId/sessionId UUID и playerId decimal string.

ping → pong; logout → session.loggedOut и закрытие. Отзыв/истечение разрешения
даёт session.ended и закрытие. Клиент не борется за сессию новым game/start.

До авторизации соединение закрывается1008/auth_timeout через20с; frame-limit
20/с до auth и240/с после, превышение закрывает1008/rate_limit. Это не заменяет
отдельные бюджеты дорогих операций. На явный временный отказ auth SPA и бот
могут повторить только тот же grant; игровые команды автоматически не повторяют.

## Ресурсы и контекст

query имеет name, context и data. context.planetId — каноническая положительная
decimal string. Если контекст не указан, сервер читает текущую собственную
планету/луну. Чтение не меняет Users.current_planet. Чужие/удалённые объекты
недоступны. Только planet.select меняет выбранную базу.

player.status.get — глобальный ресурс игрока: выбранная база, список планет,
ресурсы верхней панели и язык. Его подписка следует текущему выбору.
Остальные подписки привязаны к явному контексту, выбранному при создании.
Для смены базы/системы клиент заменяет свою подписку.

queryResult.data, subscribed.data и resource.data — полные проекции:

```json
{
  "resource":"buildings","full":true,"revision":"42","observedAt":1793700000.5,
  "context":{"playerId":"9","planetId":"26"},"parameters":{},
  "value":{"BuildList":{},"QueueList":{}}
}
```

Данные value используют существующие игровые расчёты и DTO. Сетевой обёртки
Page, HTML и приказа открыть страницу нет. Общие данные панели/планет выделены
из экранных проекций в player.status. Числовые ссылки id/qid/*Id/*_id и
выбранная/домашняя база передаются строками; координаты, уровни, количества,
физические величины и значения enum не перенумерованы. Локальный SPA-адаптер
передаёт старым виджетам число только в пределах Number.isSafeInteger;
иначе отказывает явно, без округления BIGINT.

revision относится к конкретной проекции, её контексту и параметрам в текущем
epoch. Одинаковые данные сохраняют revision. При изменении назначается
следующая общая монотонная версия; вытеснение записи из ограниченного кеша
не возвращает нумерацию назад. Это не SQL transaction ID и не долговечная
версия игрового мира. После нового epoch требуется свежий полный снимок.

## Подписки и обновления

subscribe: name из объявленного списка, UUID subscriptionId, context, data.
Ответ subscribed содержит первоначальный полный снимок. Изменения приходят
type=resource с теми же name/subscriptionId. unsubscribe → unsubscribed.
Не более 8 подписок соединения; дескриптор ограничен 2 KiB. Ресурсная таблица
версий ограничена 16384 записями, таблица подписок — 8192.

Штатный SPA держит глобальную подписку player.status и одну на открытый экран.
Закрытие диалога/страницы снимает локальное владение запросом, не отменяет
принятую игровую команду. Поздние ответы прежнего контекста/подписки не
изменяют текущий экран. Фоновые resource обновляют существующую страницу и
DOM-узлы, не запускают навигацию и не пересоздают меню/селекторы.

Используются **существующие события EventLoop::sendActualData**, та же очередь
и тот же WebSocket. Событие помечает подписки dirty; игровой цикл доставляет
изменившиеся проекции и сохраняет недоставленную отметку до следующего tick.
Нового клиентского polling, канала доставки или брокера нет.

Разрыв сети уничтожает подписки этого соединения. SPA переподключается с
прежним grant, заново читает данные и подписывается. Мутации из старого
соединения не переотправляются. Временный занятый world-lock допускает
ограниченный повтор только read/subscribe, но не command.

## Команды, результат и дедупликация

command: name, обязательный context.planetId, data, serverEpoch из hello и
issuedAt с поправкой по serverTime. Для нового намерения возраст до 300 секунд,
опережение часов до 30 секунд. requestId — неизменяемый ID намерения;
нельзя менять его payload/контекст/время/epoch или выдавать новый ради повтора.

1. Проверка формата/владения → durable reservation в MySQL → подготовка
   существующим PageActionService без исполнения → одна PlayerQueue.
2. commandAccepted — команда принята, но ещё не применена. Это **не**
   завершение стройки/исследования/полёта.
3. EventLoop применяет существующий игровой обработчик и сохраняет runtime.
   Только затем commandResult: applied / rejected / unknown.
   Молчаливые отказы очередей и переноса кораблей возвращают явный результат.
4. На приёме бизнес-отказ также может вернуть commandResult rejected без
   публикации. Ошибка структуры/прав до reservation возвращает type=error.
5. result может опередить ACK из другого процесса. Tracker не понижает уже
   подтверждённый applied/rejected ни поздним ACK, ни старым ответом статуса.

command.status.get: query с data.commandId UUID и без контекста.
Ответ queryResult.data: commandId, name (если известна операция), state,
createdAt/expiresAt для найденной записи, outcomeDurability=runtime, outcome.
outcome содержит result, error={code,message} либо reason.
Чужой ID неотличим от неизвестного: unknown/not_found.
Если reservation accepted, но события в очереди уже/ещё нет, исход обозначается
unknown/execution_unconfirmed — это не отмена и не разрешение повторить команду.

Служебная InnoDB-таблица protocol_commands, UNIQUE(world_id,player_id,request_id).
Fingerprint канонический: операция, контекст, payload, issuedAt и epoch;
порядок ключей JSON не важен. Повтор того же намерения возвращает статус без
повторной публикации. Другой fingerprint — command.id_conflict. Разные UUID
одинакового payload — разные намерения.

Завершённые receipts хранятся минимум 24 часа, очищаются порциями в существующем
игровом цикле; accepted автоматически не удаляются. Credentials и исходный
gameToken в receipts не хранятся. Старый пакет после очистки не проходит issuedAt.

**Граница долговечности:** reservation и эффект в Swoole — не одна SQL
транзакция. После любого нового epoch старый receipt даёт
unknown/runtime_changed, даже если раньше был applied. Нужно перечитать
ресурс. Штатное сохранение и восстановление ожидающей PlayerQueue сохраняются;
ожидавшее событие может выполниться после restart. Crash exactly-once,
durable applied и автоматический replay **не обещаются**. Эти ограничения
относятся ко всем командам, а не означают сохранённый игровой режим v1.

## Ошибки

type=error, error={code,message,fatal,retryable}; SQL/stack/credentials в ответ
не попадают. Основные группы: protocol.*, auth.*, world.*, query.*,
command.*, subscription.*, resource.unavailable, service.unavailable.
fatal завершает WebSocket. retryable не разрешает автоматически переиграть
мутацию. Неопределённый результат требует проверки статуса/ресурса.

## Реализация и проверки

Backend: GameProtocolV2, Connect, WSocket, ProtocolOperations,
ProtocolResourceService, ProtocolReadService, ProtocolCommandService,
ProtocolResources, ProtocolSubscriptions, ProtocolCommands, PlayerQueue,
EventLoop. Общие правила имени — PlanetRenameService. Игровые обработчики
QueuesServices/FleetsServices сохраняют механику и явно сообщают исход.

SPA: GameProtocolV2.js — транспорт и session-level command tracker;
GameSessionV2.js — единственное игровое подключение; GameOperations.js —
локальное соответствие существующих виджетов; base.js — только локальный
lifecycle запросов и навигация, без прежнего WebSocketClass.

Бот: GameClient и Runner используют v2; app/operations.py сохраняет сценарии
без изменения wire. ProtocolV2Client — низкоуровневый именованный клиент.
Кабинет не менялся. Схема игровых сущностей/enum не менялась; в служебном
protocol_commands добавлено имя операции.

```bash
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
node --test /var/www/spa.snak84.ru/tools/test_protocol_v2.cjs
python3 /opt/sp-game-bot/qa/check_protocol_v2.py --execute
python3 /opt/sp-game-bot/qa/check_protocol_v2.py --execute --restart-game
python3 /opt/sp-game-bot/qa/check_protocol_v2_commands.py --execute
python3 /var/www/spa.snak84.ru/tools/check_protocol_v2_live.py --execute --commands
```

При сбое внешнего DNS baseline допускает явный --direct к существующему
локальному dev endpoint; публичный WSS отдельно проверяет Selenium.
Это не fallback протокола и не второй игровой сервер.

Контроль 03.11.2026 на текущем dev: полный baseline — 206 проверок,
153 ответа и 92 запроса проверены по JSON Schema; v1 отвергнут,
запуск/отмена строительства и исследования выполнены штатными командами.
Отдельный прогон с настоящим перезапуском также прошёл: новый epoch,
прежняя кабинетская сессия, сохранённые данные и отсутствие replay.
PHP lint и PHPStan — без ошибок; клиент — 10 тестов, бот — 42 теста.
Итоговый браузерный прогон завершён03.11: девять экранов, команды,
сохранение раскрытого селектора/DOM, смена планеты на луну и обратно,
создание/отмена строительства, reconnect с прежним sessionId, вытеснение
старой сессии новым входом, logout и полный reload без старого разрешения.
Существующее исследование snak1 не трогалось: этот browser-шаг пропущен;
запуск/отмена исследования прошли отдельно на qaown_260924 в полном baseline
(206 проверок, 151 ответ, 92 запроса, две реальные очереди).
Реальный QA-рейс: отправка → остановка/удержание → продолжение → reconnect →
отзыв → базирование без потери кораблей; сообщение возврата прочитано и удалено.
B002/B040 закрыты с доказательствами в found-bugs.md.

Дополнительные команды:

```bash
python3 /var/www/spa.snak84.ru/tools/check_protocol_v2_live.py --execute --commands --flows --login snak1
python3 /opt/sp-game-bot/qa/check_protocol_v2_flight.py --execute
```

При подготовке QA-рейса исправлены предположения самого harness: отправленный
флот находится в FlyingFleets, ручная остановка с топливом является holding,
а OBSERVE-preview не включает будущий ручной возврат. После штатного stop
сервера только QA-флоту37 добавлено1000 топлива для возврата прежнего теста;
это не изменение формулы или балансов других игроков. Флоты37/47/71 вернулись
на базы, status=0/mission=0. Ранние неуспешные прогоны не выданы за PASS.
