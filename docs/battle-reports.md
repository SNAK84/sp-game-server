# Архив боевых докладов

## Данные и доступ

- `PlanetBattles` — рабочее состояние боя и локального gameplay commit, не история.
- `battle_reports` — отдельный неизменяемый JSON snapshot версии 1, индексированный по существующему случайному `battleId` (128 бит).
- `battle_report_saves` — личные закладки `(report_id, user_id)`. Удаление сообщения не удаляет доклад.
- Ссылка: `https://sp-game.ru/?battle=<battleId>`. Любой **авторизованный** игрок со ссылкой может читать и сохранить доклад. Без авторизации доступ отсутствует. Публичного списка всех боёв нет.
- Публичный DTO включает участников, начальные характеристики с технологиями/опытом, состав после каждого раунда, выстрелы по группам, потери, восстановление, опыт, добычу и обломки именно этого боя. В нём нет seed, future commands и snapshot всех ресурсов планеты.
- Новые сообщения содержат `ReportVersion=1`, по нему клиент добавляет кнопку открытия. У старых сводок подробного архива нет; автоматически восстановить уже заменённые раунды невозможно.

Архив читается из MySQL по запросу, без `BaseRepository::loadAll()` и без полной истории в Swoole. Размер одного JSON ограничен 1 MiB, список закладок выдаёт только summary, по 30 записей. Игровые resources/queues/fleets по-прежнему используют Swoole Tables как runtime source of truth.

## Запись и повтор

1. `FleetAttackService::prepareResult()` формирует итог и сохраняет доклад с `ready=0` **до** runtime mutations. Ошибка SQL не применяет gameplay.
2. Существующий gameplay commit/rollback применяется без изменения боевых формул.
3. После `phase=settled` доклад публикуется (`ready=1`), затем возобновляются команды флотов.
4. Ошибка публикации оставляет settled-state для повторной публикации, без второго начисления добычи/опыта/сообщений.

Повтор подготовки может пересобрать только ещё не опубликованный snapshot после runtime rollback. Опубликованный payload не перезаписывается. Prepared-запись невидима игрокам; следующий бой не может заменить единственную копию готового доклада.

Это **не** делает независимое сохранение всех gameplay repositories транзакционным: crash между runtime commit и общим RepositorySaver flush остаётся отдельным риском существующего игрового persistence. Prepared-записи аварийно незавершённых боёв автоматически не удаляются — это диагностические записи, не доступные игрокам доклады.

## Срок и закладки

Срок — 7 × 86400 секунд от `finishedAt`, с округлением вверх до целой секунды. Чтение не продлевает срок. Просроченный несохранённый доклад недоступен немедленно, даже до физической очистки.

Хотя бы одна закладка любого игрока сохраняет общий доклад бессрочно. Снятие последней закладки возвращает исходный срок, не начинает новые 7 дней. Если срок уже прошёл, доклад становится недоступен сразу. Повторные save/unsave идемпотентны.

Закладка и `saved_count` записываются в одной локальной InnoDB transaction. Lock родительской строки сериализует сохранение и удаление по сроку. Очистка раз в минуту выбирает до 100 кандидатов, затем в отдельной transaction для каждого берёт PRIMARY row lock через SELECT FOR UPDATE, повторно проверяет **ready, unsaved, expired** и удаляет по ID. Это сохраняет порядок блокировок при конкурентной закладке и обновлении secondary expiry index; один WHERE id в DELETE не гарантирует выбор PRIMARY оптимизатором. RepositorySaver не меняется.

## WebSocket

Вспомогательный `mode=battlereport` не меняет `Account.mode`, Page или получателя ActualData. Использует обычные status/requestId/serverTime/errors проекта.

- `action=get, data={id}` → `data.Page={report,savedByMe,expiresAt}`;
- `action=save|unsave, data={id}` → обновлённые те же поля (report=null после снятия последней просроченной закладки);
- `action=list, data={page:1}` → `data.Page={page,hasMore,reports:[{id,createdAt,summary}]}`.

`user_id` берётся только из авторизованного AccountData, не из request. Несуществующий и просроченный ID дают одинаковую ошибку. Ссылка не содержит token аккаунта.

## Проверки

- `php tests/BattleReportPipelineTest.php` — изолированный настоящий бой, ошибки archive prepare/publish и gameplay rollback.
- `php tests/BattleReportsMysqlTest.php` — реальные SQL/закладки/TTL/validation/pagination и injected SQL failure. Создаёт две собственные `qa_battle_*_<random>` таблицы и удаляет только их.
- `php tests/FleetAttackTest.php --export-fixture | docker exec -i swoole_app php /var/www/html/tests/FleetAttackSwooleTest.php` — native Tables/TaskWorker, без живых аккаунтов/MySQL gameplay.
- Тот же native test с `--mysql-archive` — настоящий боевой commit и настоящий SQL архив в двух собственных временных QA-таблицах. Игровые таблицы MySQL не изменяются.

Точные значения энергии залпа и поглощения щитами за раунд пока не сохраняются отдельно: интерфейс не восстанавливает их приблизительно из количества выстрелов. Случайный разброс и перехват куполами не позволяют такое восстановление.

## Изменённые файлы этого этапа

Backend:

- `src/Game/Repositories/BattleReports.php` — архив, личные закладки и expiry cleanup.
- `src/Game/Services/BattleReportService.php` — публичный snapshot версии 1.
- `src/Game/Pages/BattleReportPage.php` — read/save/unsave/list API.
- `src/Game/Services/FleetAttackService.php` — archive prepare/publish, снимки имён и новая ссылка в summary.
- `src/Game/Services/FleetBattleService.php` — номер раунда вступления подкрепления.
- `src/Game/Services/EventLoop.php`, `src/main.php` — периодическая очистка и создание архивных таблиц.
- `src/Game/PageBuilder.php`, `src/Core/Connect.php` — вспомогательный endpoint без смены Account.mode.
- `resources/lang/ru.json` — серверная локализация интерфейса.
- `src/Game/Enums/FleetMissionType.php` — восстановлены случайно отсутствовавшие объявления пяти рабочих миссий с прежними ID 10/11/12/15/20; новая механика не вводится.

Frontend `/var/www/spa.snak84.ru`:

- `js/BattleReports.js`, `css/battle-reports.css` — просмотр, прямая ссылка, закладки и адаптивные таблицы.
- `index.php` — подключение ресурсов.
- `js/BasePageClass.js` — одноразовое открытие ссылки после авторизации и Lang readiness.
- `js/pages/MessagesPageClass.js` — открыть доклад и список сохранённых.

Проверки: `tests/BattleReportPipelineTest.php`, `tests/BattleReportsMysqlTest.php`, `tests/fixtures/BattleReportsMemory.php`, `tests/FleetAttackTest.php`, `tests/FleetAttackSwooleTest.php`. Документ: `docs/battle-reports.md`.
