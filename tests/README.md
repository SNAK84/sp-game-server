# Проверки SP-Game

## B045: нулевые значения Environment

```bash
php tests/EnvironmentTest.php
python3 -m unittest discover -s tests -p test_log_isolation.py
vendor/bin/phpstan analyse --memory-limit=1G
```

EnvironmentTest проверяет настоящий класс с синтетическими env-map/$_ENV/
process-env и собственным временным env-файлом, без чтения рабочего .env,
БД, Swoole Tables или игрового мира. Зафиксированы приоритет источников,
строка'0', пустые/отсутствующие/null-значения, getters/require и parser/reload.
Пустая строка сохраняет прежний default; её не подменяют нижним источником.
Изоляция логов проверяется также с LOG_ECHO=0, не только LOG_ECHO=false.
Подробности: [environment-zero-values.md](../docs/environment-zero-values.md).

## T002: отдельные логи тестовых процессов

```bash
python3 -m unittest discover -s tests -p test_log_isolation.py
python3 -m unittest discover -s tests -p test_flight_audit_runner.py
python3 tests/run_flight_audit.py --only FleetPush
php -d auto_prepend_file=tests/bootstrap.php tests/CredentialLoggerProbe.php
```

CLI `tests/bootstrap.php` задаёт process-only SP_GAME_TEST_LOG_FILE: новый
`/tmp/sp-game-test-*` либо явно переданный файл артефакта. Значение можно
получить через `getenv('SP_GAME_TEST_LOG_FILE')`; в stdout оно не печатается,
поэтому JSON-экспорты не ломаются. Повторная загрузка .env не меняет этот путь.

Для host-наборов runner сохраняет `SUITE.runtime.log` рядом с `SUITE.log`,
для host-экспорта — `SUITE.export.runtime.log`. Native CLI получает свой
временный файл внутри контейнера: host-путь через docker exec не передаётся.
При включённой ротации возможны дополнительные файлы этого же runtime-log.
Диагностика не выключается, реальные игровые логи не очищаются.

Скрипты без общего bootstrap можно запускать с показанным auto_prepend_file
или явно заданным SP_GAME_TEST_LOG_FILE. Интеграционные события, действительно
исполняемые работающим main, по-прежнему логируются самим main — этот флаг
не переносит их в log диагностического CLI. Подробности и границы:
[test-log-isolation.md](../docs/test-log-isolation.md).

## T001: детерминированный исход шпионажа в проверке маршрута

```bash
php tests/SpyRandomContractTest.php
docker exec -w /var/www/html swoole_app php tests/FleetPlanSwooleTest.php
docker exec -w /var/www/html swoole_app php tests/ResearchResourcesIsolationTest.php
vendor/bin/phpstan analyse --memory-limit=1G
```

Это точечная проверка исправления самого теста, не новый тестовый сервер.
Первый скрипт проверяет чистый расчёт/RNG без Tables/SQL/аккаунтов. Native
CLI-проверки используют существующий fixture с запретом SQL; runtime Tables
работающего main не затрагиваются. Игровые рейсы, restart и browser-тест здесь
не нужны: production-код и SPA не меняются.

В FleetPlanSwooleTest только вокруг «шпионаж → доставка → возврат» задаются
броски0/100. В Nova пара0/0 тоже уничтожает зонды при counterLimit0; это правило
не изменено. Подмена действует только на FleetSpyService в данном PHP-процессе,
не расходуется другими сервисами и снимается в finally. Случайное исчезновение
ожидаемой доставки больше не маскируется повторами всего теста. Отдельно
актуализирована схема уникального индекса общего fixture, без изменения
производственного BaseRepository или phpstan-baseline.neon. Подробности:
[spy-test-determinism.md](../docs/spy-test-determinism.md).

## P001: отзыв с удержания и сенсорного наблюдения

При остановленном основном main guarded
`migrations/20261101_observe_recall_qa.php --prepare` создаёт только три новых
QA P001 у базы26 существующего qaown_260924. Выполнить административный CLI
тем же способом, что подготовку B034, обязательно запустить main в finally.
Повтор существующей сцены запрещён; старые флоты/ресурсы не сбрасываются.

```bash
python3 tests/ObserveRecallLiveTest.py --dev --session ID --phase before
# После установки исправления и штатного restart основного main:
python3 tests/ObserveRecallLiveTest.py --dev --session ID --phase after
# После ещё одного штатного restart:
python3 tests/ObserveRecallLiveTest.py --dev --session ID --phase reload
python3 tests/ObserveRecallLiveTest.py --dev --session ID --phase launch
python3 tests/ObserveRecallLiveTest.py --dev --session ID --phase returned
```

Нужна собственная Selenium-сессия; фазы используют её sessionStorage.
before доказывает отказ старого кода. after кликает настоящую кнопку,
проверяет точный расход, позицию, DOM, отключение сенсоров, повторный отзыв
и rollback при нехватке топлива. Малотопливный QA возвращается штатным
ResumeFleet с дозаправкой из трюма. launch дополнительно отправляет новую
OBSERVE на50% и проверяет её сохранённую скорость, затем отзывает из MOVE.
returned ждёт фактического STAY и обычного saver. Раунды/время не ускоряются,
второй игровой мир не создаётся. Сессию после проверки закрывает оператор.
Подробности: [observe-recall.md](../docs/observe-recall.md).

## B044: фоновые раунды планетарного и пространственного боя

```bash
# Одноразовая исходная сцена, только ДО установки исправления:
python3 tests/BattleRoundPushLiveTest.py --dev --phase before --prepare
# Финальная сцена после исправления:
python3 tests/BattleRoundPushLiveTest.py --dev --phase after3 --prepare
```

Это stateful live-проверка основного main, не автономный тестовый мир.
`--prepare` штатно останавливает main, guarded migration готовит только
QA-сцену и запускает main в finally. Существующие фазы before/after/after2/after3
уже использованы01.11.2026; их повторная подготовка намеренно запрещена.
Для нового прогона сначала нужна новая явно ограниченная QA-сцена и её фаза,
а не удаление/сброс прежних данных. Без `--prepare` можно подключиться только
к ещё не стартовавшей подготовленной сцене; завершённый бой повторно не идёт.

Настоящий SPA и пассивные WS второго владельца подключаются до первого
раунда. Во время боя нет чтений страниц/reload/polling: проверяются штатные
ActualData, round1–10 у обоих владельцев, UI, сохранность DOM и доклады.
Отдельный контроль QA-планеты восстанавливает исходный выбор. Проверка индекса
команд сравнивает четыре выборки с полным проходом реальных сохранённых строк
при административной подготовке; отдельного EventLoop не запускает.
Собственная Selenium-сессия закрывается. Подробности и границы:
[battle-round-push.md](../docs/battle-round-push.md).

## B043: zoom и фоновая отрисовка галактики

```bash
python3 tests/GalaxyZoomLiveTest.py --dev
python3 tests/GalaxyZoomLiveTest.py --dev --navigation-regression
```

Собственная Selenium-сессия, основной dev и существующий QA. Настоящий
ActualData приходит во время штатного начального fit1200мс; проверяются
достижение масштаба, движение планеты и кадры, wheel/drag, сохранность камеры
и DOM, устаревший callback, смена системы и Hide в zoom/fadeIn. Игровых
команд нет. Дополнительный флаг запускает прежний B013 на двух ширинах;
`--before` предназначен для исходного зависания до установки правки.
Подробности: [galaxy-zoom-lifecycle.md](../docs/galaxy-zoom-lifecycle.md).

## B041: ошибка навигации в редакторе маршрута

```bash
python3 tests/FleetPlanNavigationLiveTest.py --dev
python3 tests/FleetPlanNavigationLiveTest.py --dev --resource-regression
```

Собственная Selenium-сессия и основной dev; существующий QA qaown_260924 и
базирующийся флот с редактором. Проверяются invalid NavigationSystem,
контролируемый timeout настоящего read, поздний ответ, восстановление,
индикаторы и сохранность DOM на desktop/mobile. Игровых записей нет.
Дополнительный флаг запускает прежний B011 в той же сессии на двух ширинах;
`--before` применим только до установки правки. Подробности:
[fleet-plan-navigation.md](../docs/fleet-plan-navigation.md).

## B037: переход меню, сообщения и смена планеты

```bash
python3 tests/ClientNavigationLiveTest.py --dev
```

Собственная Selenium-сессия, основной main и существующий QA `qa_b018_home`
с двумя планетами и действующим токеном. Токен читается только в RAM, не
печатается и не заменяется. На desktop/mobile проверяются настоящие клики,
wire/ответы, UI-replay исходного DTO во время ожидания ответа и применение
planetselect. Отдельно — настоящие ActualData, сохранность DOM/widget и
раскрытого списка. Исходная планета должна восстановиться; игровых команд
тест не отправляет. `--before` применим только до установки правки.
Подробности: [client-navigation.md](../docs/client-navigation.md).

## B034: скорость перехвата в редакторе

Guarded `migrations/20261101_interception_plan_speed_qa.php --prepare`
при остановленном main создаёт только QA B034 и поднимает компьютерную
технологию QA9 до20 для активных слотов. Основной main запускается в finally;
он сам обнаруживает цель штатным сенсором. Второй игровой мир не создаётся.

```bash
python3 tests/InterceptionPlanSpeedLiveTest.py --dev --session ID --phase before
# После правки и штатного restart main:
python3 tests/InterceptionPlanSpeedLiveTest.py --dev --session ID --phase after
python3 tests/InterceptionPlanSpeedLiveTest.py --dev --session ID --phase new
python3 tests/InterceptionPlanSpeedLiveTest.py --dev --session ID --phase priority
python3 tests/InterceptionPlanSpeedLiveTest.py --dev --session ID --phase cleanup
# После restart:
python3 tests/InterceptionPlanSpeedLiveTest.py --dev --session ID --phase final
```

before отправляет14472 кнопкой SPA на50% и фиксирует неправильные100%.
after действительно сохраняет маршрут и проверяет неизменность активной MOVE.
new проверяет новую ATTACK14473, priority — явные70% будущего этапа. Повторное
открытие ждёт применения PlayerQueue/нового Page, не только ACK. cleanup
возвращает только четыре новых QA и выключает сенсор.111/112 только читаются.
Нужна собственная Selenium-сессия; этапы связаны sessionStorage. Повторная
подготовка существующей сцены запрещена. Подробности/границы:
[interception-plan-speed.md](../docs/interception-plan-speed.md).

## B033: бой базирующегося защитника

При остановленном основном main guarded migration
`migrations/20261101_based_fleet_battle_qa.php --prepare` создаёт только
новые QA B033 и свободную ресурсную базу4. Запускать CLI тем же образом,
что административную подготовку B031; обязательно запустить main в finally.
Раунды и результат считает основной сервер, второй мир не создаётся.

```bash
python3 tests/BasedFleetBattleLiveTest.py --dev --session ID --phase during
# После фактических раундов, без reload и смены страницы:
python3 tests/BasedFleetBattleLiveTest.py --dev --session ID --phase complete
# После штатного restart основного main:
python3 tests/BasedFleetBattleLiveTest.py --dev --session ID --phase reload
```

Нужна принадлежащая тесту браузерная сессия QA9. during сохраняет ссылки
на DOM-узлы и наблюдает штатные WS-обновления; complete проверяет их
сохранность, снятие блокировки и один реальный доклад. Повтор уже созданной
сцены запрещён; проверки привязаны к ней до следующих игровых действий.
Подробности: [based-fleet-battle-projection.md](../docs/based-fleet-battle-projection.md).

## B031: pending-подкрепление обычного перехвата

```bash
# Создаёт новые QA; останавливает/запускает основной main и на9с замедляет
# только два TaskWorker. Не запускать параллельно с чужой проверкой боя.
python3 tests/SpacePendingReinforcementLiveTest.py --prepare before2
python3 tests/SpacePendingReinforcementLiveTest.py --verify before2
# После установки исправления:
python3 tests/SpacePendingReinforcementLiveTest.py --prepare after
python3 tests/SpacePendingReinforcementLiveTest.py --verify after
python3 tests/SpacePendingReinforcementLiveTest.py --browser after --session ID
# После restart main, пока защитник ещё ожидает:
python3 tests/SpacePendingReinforcementLiveTest.py --verify after
python3 tests/SpacePendingReinforcementLiveTest.py --browser resume --session ID
```

Только новые QA user9/10 основного dev; повтор уже существующей сцены запрещён.
before2 применим до адресного восстановления этих QA-команд. `--prepare`
проверяет реальные joinedRound2/finished round1, `--verify` — сохранность
состава/груза/топлива/XP и следующий маршрут. После штатного удаления applied
checkpoint используется готовый доклад, не требуется вечный checkpoint.
`--browser` использует принадлежащую тесту сессию QA9; resume действительно
нажимает штатную кнопку продолжения. Проверки стадии ожидания после этого
уже неприменимы. Без отдельного игрового сервера и изолированного боя.
Сцены/точные результаты: [space-pending-reinforcement.md](../docs/space-pending-reinforcement.md).

## B030: остановленный MOVE в общем ресурсном бою

```bash
python3 tests/ResourcePausedMoveLiveTest.py --dev --session ID --phase before
# После установки правки и restart основного main:
python3 tests/ResourcePausedMoveLiveTest.py --dev --session ID --phase send
# После фактического боя и сохранения результата, также после restart:
python3 tests/ResourcePausedMoveLiveTest.py --dev --session ID --phase verify
python3 tests/ResourcePausedMoveLiveTest.py --dev --session ID --phase defender
```

Только основной dev и свои QA; требуется подготовленная сцена8328–8331/site1.
Guarded migration отказывает при работающем main, занятом site1 или существующих
QA B030. before сохраняет числовую/игровую сцену в собственной browser-сессии,
не credentials; send использует штатную кнопку SPA. defender переключает
авторизацию с qafor_260924 на qaown_260924. Проверки привязаны к этой QA-сцене
до её последующего изменения, не являются вечным baseline всех аккаунтов.
Подробности/результат/тайм-аут worker: [resource-paused-move-battle.md](../docs/resource-paused-move-battle.md).

## B029: долговечность ID на основном dev

```bash
docker exec -w /var/www/html swoole_app php tests/RepositoryIdRangesLiveProbe.php --dev-reserve
python3 tests/RepositoryIdRangesLiveTest.py --dev --session ID --phase cycle --mission
# Штатный restart основного сервера, затем повторить cycle или выполнить verify:
python3 tests/RepositoryIdRangesLiveTest.py --dev --session ID --phase verify
```

Проба резервирует реальные диапазоны в dev-БД, намеренно оставляя пропуски,
но не создаёт/не удаляет игровые строки и не запускает второй мир.
SPA-cycle требует подготовленного QA qaown_260924, планеты26, одного малого
транспорта, свободного слота базы и restart перед каждым cycle. Режим bootstrap
нужен только для одноразового переноса наблюдаемого верхнего ID старого main;
он использует существующий QA132 и сохраняет только числовой floor в браузере.
Credentials/токены не выводятся. Обязательная подготовка, результаты и границы:
[repository-id-ranges.md](../docs/repository-id-ranges.md).

## Быстрая проверка контрактов

```bash
php tests/run.php
```

`CoreContracts`: 178 проверок текущих Coordinate/FleetTarget (включая обязательный
siteId для ресурсных объектов), полярной геометрии,
локального перемещения планета/луна/обломки, FleetShip/FleetResource, callbacks,
составного ключа и разбиения длинного индекса (0–8192 ID, int64, размер части), чистой арифметики checkpoint расхода стоянки
(резерв, повтор, разбиение интервала, невалидный ввод), расчётного ядра добычи
(G/K, мощность корпусов, общая фаза, трюм/бак, границы, смесь, неизменность
входных данных,2000 воспроизводимых сочетаний), codec/целостности checkpoint
и before/after решения восстановления, явной SQL-представимости чисел,
а также статического startup/API wiring. Не требует PHPUnit,
MySQL, Swoole, авторизации или перезапуска сервера. JSON проверяется после
восстановления типизированного Coordinate: JSON сам по себе не различает 90 и 90.0.

B036: добавлены пять чистых проверок одноразового restart-снимка PlayerQueue:
короткий/большой payload, идентичность и типы, недопустимые поля/ready,
TTL/байтовые границы и порядок колонок JSON. Основной dev, непустой снимок,
повторный restart и настоящий SPA: [player-queue-restart.md](../docs/player-queue-restart.md).

B006: точечная native-диагностика записи на SELECT-снимке текущей dev-БД:

```bash
docker exec -w /var/www/html swoole_app php tests/RepositoryWriteProbe.php --dev-readonly
```

SQL-транзакция READ ONLY; saver и второй игровой сервер не запускаются.
Проверяются main set, unique/non-unique индексы и их части, dirty marker,
ошибки после записи, FleetControl rollback и retry; сравниваются также индексы.
QA-guard требует существующих аккаунта16/Fleet56/команды190; при изменении
этой сцены проверка должна отказать, а не создавать другой игровой мир.
Живые отправка/Stop/план/Resume/restart/возврат и границы:
[repository-write-consistency.md](../docs/repository-write-consistency.md).

B038: составной ключ и компенсация записи EntitySettings:

```bash
docker exec -w /var/www/html swoole_app php tests/EntitySettingsWriteProbe.php --dev-readonly
python3 tests/EntitySettingsLiveTest.py --dev --session ID --efficiency 100
```

Первый запуск читает текущую dev-БД в READ ONLY транзакции и проверяет24 отказа
native Table плюс5 успешных сценариев; SQL-записи и второй игровой сервер не
запускаются. Для live-проверки нужны основной сервер, QA-контейнер с учётными
данными qaown_260924 и НОВАЯ пустая Selenium-сессия: raw-login меняет токен QA.
Сам live-тест данные не подготавливает: QA-планета26, эффективности рудников1/2
должны быть ожидаемыми70/100 или100/100. Он выполняет вход, read-запросы и UI;
обычный сервер при отсутствии строки может создать default-настройку.
Результаты и ограничения: [entity-settings-write.md](../docs/entity-settings-write.md).

B039: проверяемое удаление/восстановление строки, индексов и DELETE marker:

```bash
docker exec -w /var/www/html swoole_app php tests/RepositoryDeleteProbe.php --dev-readonly
python3 tests/RepositoryDeleteLiveTest.py --dev --session ID --phase lifecycle
python3 tests/RepositoryDeleteLiveTest.py --dev --session ID --phase verify --fleet-id FLEET_ID --expected-ships 0
```

Native-проба читает SQL в READ ONLY транзакции и проверяет160 сценариев в
Table-копиях, без EventLoop/второго игрового сервера. Требуются прежние
qafor_260924/Fleet56/команда190: изменившуюся QA-сцену не создавать автоматически.
Размеры Table-копий зависят от снимка, не резервируют32768 строк на каждый case.

Live lifecycle требует новую пустую Selenium-сессию, подготовленный qaown_260924
на планете26 с одним малым транспортом и свободным слотом. Только этот транспорт
создаёт временный флот, затем расформировывает его обычными кнопками и ждёт saver.
Verify выполняется после restart/восстановления fixture в той же собственной
сессии, только читает и проверяет отсутствие указанного ID. Возвращаемый корабль
сравнивается с expected-ships; fixture тест сам не выдаёт и не отзывает.
Результаты/границы: [repository-delete-consistency.md](../docs/repository-delete-consistency.md).

B042: временная очередь не должна копить SQL-отметки событий:

```bash
docker exec -w /var/www/html swoole_app php tests/PlayerQueueSyncProbe.php --dev-native
php migrations/20261101_player_queue_sync_qa.php --inspect
python3 tests/PlayerQueueSyncLiveTest.py --dev --session ID --phase stress --events 2200
python3 tests/PlayerQueueSyncLiveTest.py --dev --session ID --phase verify
```

Native-проба проверяет21 условие,5000 коротких/100 больших событий, native-отказы
записи/удаления и restart-codec. Только компоненты очереди в CLI: без SQL,
EventLoop и второго игрового сервера. Ёмкости и схема не расширяются.

Live stress требует новую пустую собственную Selenium-сессию и основной dev.
QA-сцена: qaown_260924, планета26, флот122 с именем `QA B042 large` после
исполнения контролируемого restart-fixture. Тест сам fixture не готовит:
при иной сцене отказывает. Выполняет2200 штатных выборов той же планеты и44
переименования-барьера, затем UI возвращает `QA B004 source`, ждёт saver/reload.
Для отдельного завершения после последнего barrier есть `--phase finish`.
Raw-канал закрывается перед UI, чтобы единственный Accounts.frame снова
принадлежал SPA. Verify запускается в той же сессии после restart, только читает.

Миграция `--prepare`/`--activate` разрешена только при остановленном main,
проверяет точный QA-контекст и не перезаписывает чужие pending-события. Это
не универсальная миграция и не тест crash/ACK-durability. Воспроизведение,
порядок подготовки и результаты: [player-queue-sync-markers.md](../docs/player-queue-sync-markers.md).

B015: прогноз неявного возврата после потери ресурсной базы:

```bash
php migrations/20261101_lost_base_projection_qa.php --inspect
python3 tests/FleetLostBaseProjectionLiveTest.py --dev --session ID --phase before
python3 tests/FleetLostBaseProjectionLiveTest.py --dev --session ID --phase after
python3 tests/FleetLostBaseProjectionLiveTest.py --dev --session ID --phase progress
python3 tests/FleetLostBaseProjectionLiveTest.py --dev --session ID --phase complete
```

Основной dev + собственная Selenium-сессия QA, без изолированного игрового мира.
`before` предназначен для прежнего кода, `after` — для исправленного до отзыва
контрольного explicit. `progress` проверяет повторно взведённый implicit и общую
шкалу; `complete` — завершение/SQL/DOM, также после restart. Учитывается текущий
единственный Accounts.frame: raw-канал закрывается перед проверкой DOM.

Подготовка `--prepare`/`--rearm`/`--rearm-implicit` разрешена только при
остановленном main и точном QA-контексте. Создаются лишь два отдельных QA-флота
с искусственным активным checkpoint, не имитируется пользовательский ACK.
Существующие флоты не перезаписываются. После исполнения guards повторного
rearm должны отказать. Порядок, результаты и ограничения:
[fleet-lost-base-projection.md](../docs/fleet-lost-base-projection.md).

B016: реальный источник транспортного сообщения после смены базы:

```bash
php migrations/20261101_transport_source_qa.php --inspect
python3 tests/FleetTransportSourceLiveTest.py --dev --session ID --phase before
python3 tests/FleetTransportSourceLiveTest.py --dev --session ID --phase after --account owner
python3 tests/FleetTransportSourceLiveTest.py --dev --session ID --phase after --account target
python3 tests/FleetTransportSourceLiveTest.py --dev --session ID --phase verify --account target
python3 tests/FleetTransportSourceLiveTest.py --dev --session ID --phase verify --account owner
python3 tests/FleetTransportSourceLiveTest.py --dev --session ID --phase finish
```

Основной dev и собственная Selenium-сессия; `before` нужен прежнему коду,
остальные фазы исправленному. Логины обоих QA проходят обычную SPA-форму;
credentials берутся из своих QA-контейнеров в RAM, не из argv/репозитория.
Тот же token используется raw-диагностикой, перед DOM канал закрывается.
Для кликов ожидается окончание анимации. Finish читает fleets штатным запросом
без анимации меню: B037 этим не исправляется.

Миграция `--before`/`--after`/`--move-only` допускает подготовку только при
остановленном main. Guards проверяют точные QA15/16, планеты26/27, свободный
сектор19 и отсутствие новых имён; существующие флоты не перезаписываются.
Это активные checkpoint с заданными временем/грузом, не новый SendMission
и не тест боевого захвата/формул движения. Реальные due, UNLOAD, сообщения,
возврат и saver выполняет основной сервер. Порядок:
[fleet-transport-source.md](../docs/fleet-transport-source.md).

B018: направление новых планет в основном dev, без второго игрового мира:

```bash
php migrations/20261101_planet_direction_qa.php --inspect
python3 tests/PlanetDirectionLiveTest.py --dev --session ID --phase home
python3 tests/PlanetDirectionLiveTest.py --dev --session ID --phase send
python3 tests/PlanetDirectionLiveTest.py --dev --session ID --phase finish
python3 tests/PlanetDirectionLiveTest.py --dev --session ID --phase verify
python3 tests/PlanetDirectionLiveTest.py --dev --session ID --phase returned
```

Нужна своя Selenium-сессия QA `qa_b018_home`, первый вход — обычной формой SPA.
Одноразовая подготовка `--prepare-account` и после создания/сохранения home
`--prepare-flight` разрешена только при остановленном main. Зафиксированная
сцена: аккаунт18/user12/home35. Подготовка проверяет отдельный QA-логин,
одну домашнюю планету, отсутствие флотов/выданных кораблей; тест требует user12,
а returned — home35. Повторная выдача кораблей не допускается.
Пароль наследуется от тестового qaown_260924 только внутри БД; в тест берётся
из QA-контейнера в RAM, не выводится и не передаётся в argv.
Send создаёт флот из колонизатора/малого транспорта и нажимает отправку
колонизации на свободную орбиту1 той же системы. Finish запускать после
сохранения колонии, returned — после реального возврата и обычного saver.
Verify читает состояние после restart и ждёт окончания начального fit-zoom:
отдельная гонка B043 при этом не исправляется. Порядок/ограничения:
[planet-orbit-direction.md](../docs/planet-orbit-direction.md).

B019: имя цели-флота на реальном рейсе основного dev:

```bash
python3 tests/FleetTargetNameLiveTest.py --dev --session ID --phase start
python3 tests/FleetTargetNameLiveTest.py --dev --session ID --phase after
python3 tests/FleetTargetNameLiveTest.py --dev --session ID --phase cleared
python3 tests/FleetTargetNameLiveTest.py --dev --session ID --phase cleanup
python3 tests/FleetTargetNameLiveTest.py --dev --session ID --phase returned
```

Своя Selenium-сессия qaown_260924, планета26, свободные QA131/132 с одним
большим транспортом каждый; ресурсов достаточно для штатной загрузки.
Start предназначен **прежнему клиенту** в уже открытой сессии: отправляет131
в OBSERVE к POINT3000/0° и132 транспортом к131, проверяет настоящий raw WS/DOM.
After делает reload изменённого JS, затем переименовывает131 штатным действием.
Cleared очищает имя и делает reload; cleanup восстанавливает прежнее имя,
отзывает транспорт и завершает OBSERVE через штатное ResumeFleet/available.
Returned запускается после фактического возврата и обычного saver.
Не применять к произвольным чужим ID и не выдавать подготовку fixture за
реальную миссию.12 fixtures after меняют только синхронное клиентское
форматирование и восстанавливают Page в finally; SQL/второй мир не создаются.
Для слайдера тест отправляет input **и change** и ждёт preview выбранной
скорости: отсутствие change — ошибка автоматизации, не доказанный баг игры.
Состояние, результаты и ограничения: [fleet-target-name.md](../docs/fleet-target-name.md).

Чистые CoreContracts не проверяют игровой runtime и не заменяют аудит полётов.
Внутренние серверные
snapshot-координаты FLEET не являются форматом, который должен присылать клиент;
проверяется, что FleetTarget не переносит клиентский snapshot в цель запроса.

5В.10: четыре чистых контракта rehome удалённого MINING — нулевая экономика,
сохранение соседнего работника/carry, целые before/after строки восстановления,
новая граница топливного резерва и отказ чужого контекста. Основной dev/SPA:
[fleet-mining-home-loss.md](../docs/fleet-mining-home-loss.md).

B003: три проверки выбора аккаунтов EventLoop — online плюс опубликованные
offline-события, без дублирования и без прохода пустых offline-аккаунтов.
B032/B003/B035 проверены на основном сервере и в SPA; подготовка двух новых
QA-флотов выполняется только при остановленном main, `--verify` только читает
SQL. Доказательства и границы: [fleet-critical-bugs.md](../docs/fleet-critical-bugs.md).

5В.1: обновлён контракт close для resource-home/stay, добавлены явный отбор
платной послебоевой охраны (не любая pause) и сохранение её возвратного резерва.
Живые проверки — [fleet-resource-guards.md](../docs/fleet-resource-guards.md).

5Б.3: публичный builder MINING — возврат с выгрузкой бака, стоянка без
несуществующего склада, работа на своей ресурсной базе без LOAD/фиктивного
перелёта, отклонение неправильных целей и whitelist настроек. Живые рейсы
проверяются на основном dev-сервере через SPA:
[fleet-mining-public.md](../docs/fleet-mining-public.md).

5В.2: builder CAPTURE проверяет общую оборону отдельной командой ATTACK
перед занятием объекта. Ресурсный источник не добавляет фиктивный LOAD.
Две проверки цепочки не заменяют живой бой на основном dev-сервере.
Результаты SPA/боя/возврата: [fleet-resource-capture.md](../docs/fleet-resource-capture.md).

5В.3: пять чистых проверок допуска активного RESEARCH в общий бой: точный
siteId/тип объекта, server survey/hold, статус/пауза и согласованность часов.
Они не проверяют Swoole/receipt/сам бой; это проверяется на основном dev-сервере.
Ещё три проверки относятся к server-only отметке автоматического возврата
обследователя: обычные паузы не отзываются, исполненные команды не повторяются,
рабочие survey/hold не переносятся в возврат.

5В.4а: пять чистых проверок подкрепления атакующего у ресурсного объекта:
тот же владелец и точный siteId, запрет чужих сторон/секторов/FLEET,
фазы боя и следующего ещё не переданного вычислителю раунда. Живой бой двух
обычных CAPTURE проверяется на main/SPA, не отдельным Swoole-сервером.

5В.5: восемь чистых проверок ресурсной DEFEND — отличие от планетарного
снабжения, точные siteId/владелец/часы, резерв/срок удержания и одинаковый
бортовой бюджет в preview/send. Реальные отправка, бой и отзыв проверяются
на основном dev-сервере, не вторым Swoole-процессом. Резерв, restart и результаты:
[fleet-resource-friendly-defense.md](../docs/fleet-resource-friendly-defense.md).

5В.6: семь чистых проверок подкреплений дружественной DEFEND — точный объект,
дружба/прежний хозяин, отсутствие участника на обеих сторонах, незапущенный раунд
и сохранение слотов замороженных/pending гостей. Живой прогон main/SPA:
[fleet-resource-defense-reinforcements.md](../docs/fleet-resource-defense-reinforcements.md).

Расчётный preflight5Б на сохранённых данных текущего dev-сервера:

```bash
docker exec swoole_app php migrations/20261025_mining_preflight.php 19 9
docker exec swoole_app php migrations/20261025_mining_preflight.php 23 9
```

Только SELECT и расчёт на копиях: второй игровой/Swoole-сервер не запускается.
Проверяются G/K и неизменность исходных строк; два примера мощности1:3 —
не настоящие флоты, корабли аккаунту не выдаются. `--apply` запрещён.
Это не browser-тест/живой рейс добычи и не доказательство crash recovery.
Границы готовности5Б.0: [`fleet-mining.md`](../docs/fleet-mining.md).

Живой probe журнала5Б.1а (пишет только служебный journal в dev-БД):

```bash
docker exec swoole_app php migrations/20261025_mining_journal_probe.php --inspect
docker exec swoole_app php migrations/20261025_mining_journal_probe.php --prepare
# TOKEN взять из результата prepare; каждый вызов — отдельный PHP-процесс.
docker exec swoole_app php migrations/20261025_mining_journal_probe.php --verify TOKEN
docker exec swoole_app php migrations/20261025_mining_journal_probe.php --resume TOKEN
docker exec swoole_app php migrations/20261025_mining_journal_probe.php --resume TOKEN
```

Только identity before=after для QA9/site19/Fleet42. Изменения игровых строк
запрещены самим probe. Prepare создаёт две InnoDB-таблицы журнала, оставляет
prepared без ACK; resume после проверки подтверждает только этот fixture.
Чужой/реальный pending не перезаписывается. Это не тест ненулевого runtime
commit и не crash-тест основного игрового процесса. Не обходить manifest при отказе.

5Б.1б: `migrations/20261025_mining_runtime_probe.php --inspect` — read-only
показатели текущего QA checkpoint. `--verify TOKEN` сверяет applied и точные
after. `--prepare` с разрешения владельца перемещает12,5 металла QA9/site19
в Fleet42 через journal и ОСНОВНОЙ runtime, а не создаёт второй сервер.
Это изменяющий данные сценарий: не запускать как автоматический smoke.
`--prepare-partial` требует остановленного основного сервера и готовит
ненулевой durable fixture (+6,25, только site уже after). Выполняется CLI
с тем же образом/томами, без запуска main.php/Swoole Tables, после чего
основной сервер обязан быть запущен и проверен. Подробные фактические результаты,
границы теста бака/commit и остаточные риски — docs/fleet-mining.md/5Б.1б.
Старый identity-probe больше не применим поверх нового ненулевого QA-журнала:
его purpose/sourceHash guard намеренно откажет, не обходить проверку.

5Б.2а: baseline дополнен связкой фаза→journal, сохранением дробных G/бака
и точных часов,100 последовательными checkpoint против одной фазы,1000
образцами больших количеств, границей трюма и защитой контекста. Большие
timestamp проверяются через fixed-scale представление, не PHP round().
Точечный flush теперь сам задаёт этот SQL-образ before; recovery не применяет
epsilon к неизвестному current. Расчётный builder не активирует миссию.
`migrations/20261025_mining_phase_probe.php --inspect` читает текущий журнал.
`--prepare` — изменяющий QA-fixture со свежим raw WS-снимком по stdin:
нужны сохранённый пассивно приостановленный QA-рейс и проверенный свежий before.
Обычный StopFleet включает платное удержание, не пассивную паузу; fixture требует
отдельной подготовки без параллельного OBSERVE-расхода. У FlyingFleets raw clock
передан как start_time (FleetsPage берёт getCurrentPositionTime), а capacity/
consumption передаются только у FleetList: сначала проверить фактический состав.
25.10 пользователь дал постоянное разрешение на подготовку dev-аккаунтов.
Три ненулевые фазы применены основным сервером: revision5/6/7, restart между6/7,
raw WS совпал с MySQL, SPA и reload сохранили те же значения. Подробности и
ограничения — docs/fleet-mining.md/5Б.2а; fixture не доказывает игровую миссию.

5Б.2б.1: закрытие экономической фазы материализует fuelCarry в unscaled бак,
не меняя груз/G/K/позицию и не начисляя второй интервал. Добавлены проверки
повторов/ошибочных контекстов, отдельного выхода одного из двух участников,
повторного входа после закрытия и точности ordinary saver для epoch.
`mining_phase_probe.php --close` требует такой же свежий WS input/сохранённую
manual-паузу и уже расширенный SQL fuel_current; пишет только journal для main.
Живые close8→phase9→close10, обычный RenameFleet/save и restart прошли.
MINING handler, UI, возврат/стоянка и первичная runtime-ветка closePhase этим
прогоном не проверены. Подробности — docs/fleet-mining.md/5Б.2б.1.

5Б.2б.2: baseline дополнен правилами runtime options, округлением due-времени
вверх, переключением смеси без заимствования мощности другого флота и закрытием
MINING без преждевременного executed=1 и регрессией положительной sub-ULP границы:
due продвигается на SQL-квант, но груз начисляется только за точный остаток.
Живой основной сервер отработал двух
добытчиков47/48 с разными моментами входа и заполнения трюма; отдельный рабочий
цикл47 пережил штатный restart и отзыв кнопкой настоящего SPA;48 автоматически
закончил с finish=return. Общий bucket близких float-времён не принимается
за повреждение индекса; prepared восстановлен основным startup. Состояния и
численные результаты — docs/fleet-mining.md/5Б.2б.2. Это не доказательство
публичной SendMission/редактора добычи: их подключение остаётся следующим этапом.
`mining_runtime_qa.php --inspect` только читает SQL. Режимы --prepare, --repeat,
--finish-return изменяют ТОЛЬКО QA9, требуют остановленного main и не являются
smoke-тестами. Повторная начальная подготовка запрещена. Не запускать на других
аккаунтах и не использовать старые journal/phase probes поверх нового журнала.

5Б.2в.1: передача своему работающему добытчику одним journal batch: общий
settle до встречи, перебазирование логического бака без повторной добычи,
процент/свободный трюм, дейтерий в бак или груз, receipt отдельно от handoff.
Носители carry закрываются независимо от донора, уже продолжившего маршрут.
Четыре расчётные регрессии входят в91; они не заменяют живую отправку через SPA.
Живой рейс46→47 через окно SPA прошёл:40M/30C/3000D переданы один раз,
MINING продолжен; restart в пути/после доставки, reload, возврат46 и отзыв47
проверены. Обе очереди пусты, journal302/applied. Числа и границы покрытия —
docs/fleet-mining.md/5Б.2в.1; внутренние crash-window/ошибка handoff не инъецировались.
QA-режимы --delivery/--courier-capacity требуют остановленного main и изменяют
только сохранённые QA46/47. Выдачу тестового груза/корабля нельзя считать добычей.

5Б.2в.2: пять новых регрессий входят в96: ограниченные ship-части, объединение
груза/бака/XP без нового интервала, продолжение новыми мощностями, close без
удалённого донора, отказ replay/чужих участников/переполнения, resource-base
MERGE без фиктивного планетарного LOAD. Обычные проверки codec/recovery
сохраняют обратную совместимость ранее подготовленных parts.
Живые рейсы и границы доказанного описаны в docs/fleet-mining.md/5Б.2в.2.
Новый --merge в mining_runtime_qa.php требует остановленного main, меняет
только QA47/48 и запрещает повторную выдачу тестового XP. Это fixture,
не игровое начисление опыта. Не выполнять повторно после удаления донора.

5Б.2в.3: ещё6 регрессий (всего102): q/K раскрывается только по фактическому G
с carry; нулевой rebase одного/нескольких добытчиков; подготовка/применение
receipt-barrier, запрет replay/чужого owner/site/time и absolute recovery.
Живой QA47+51 проверил q0,25→1 при работающем MINING, один доклад, restart
до/после результата и отзыв с продолжением добычи по новому K. Числа/границы:
docs/fleet-mining.md/5Б.2в.3. Это не живой crash-injection каждого SQL-шага.
Новый mining_survey_qa.php требует остановленного main и затрагивает только
QA9: --prepare создаёт fixture; --repair предназначен только для первого
состояния B020 без базы/до начисления добычи, не для повторного запуска.

5Б.2в.4: ещё2 проверки (всего104) узкого приоритета begin MINING перед
потерей базы: точные время/цель/тип, исключение executed/pause/готового mining.
Не заменяют runtime: на основном сервере проверены единственный добытчик,
restart, отзыв и неверный mode с фактической потерей базы. Отзыв выявил B021
(промежуточная ошибка нулевого возврата), исправление отложено.
`mining_control_qa.php` работает только при остановленном main: --start переносит
QA51/52 на собственную планету26 без выдачи ресурсов/кораблей, --invalid задаёт
неверную команду47, --restore восстанавливает только проверенный отрицательный
fixture и удаляет две его команды. После отрицательного сценария сначала
дождаться обычного saver и подтвердить в SQL отсутствие базы19/home47=26:
сам `docker stop` не является подтверждением сохранения runtime.

5Б.2в.5: ещё4 проверки (всего108) порядка нулевого ресурсного возврата
MOVE→STAY, исключения вылета/ненулевого MOVE/чужой цели/pause/старого события
и сохранения строгой проверки prepareRecallFlight на нулевую длительность/
неверный fuelCost. Негативные случаи здесь расчётные, не живые рейсы.
Живой отзыв QA47 без иной охраны подтвердил start=end MOVE, IDLE, отсутствие
новых ошибок и сохранение базы/груза после saver/restart/reload.
`mining_control_qa.php --repeat` подготавливает новый MINING только для
свободного QA47, когда он один приписан к19; ничего не выдаёт и не перемещает
51/52 повторно. Как остальные режимы, требует остановленного main.

5Б.2в.6: ещё3 проверки (всего111): резерв=max(возврат домой, эвакуация),
не сумма; округление/невалидные бюджеты; остановка140,6→140 через67,5с
при32/ч и отсутствие повторной добычи на достигнутом резерве.
Резерв добычи/слияния теперь учитывает тот же путь к fallback, что и стоянка.
Живой QA47 проверил естественный close по топливу, освобождение базы,
начало эвакуации и restart в пути; во время5Б.2в.7 он естественно прибыл на26:
IDLE, груз/бак0, очередь пустая, сообщение о прибытии сохранено.
`mining_control_qa.php --fuel-test` требует остановленного main и единственного
IDLE QA47 у19 с известным резервом140. Искусственно выставляет его бак141,
не меняет груз/корабли/технологии; выводит старое/новое топливо для аудита.
После этого сервер сам расходует топливо/считает границу/эвакуирует флот.
Не повторять этот режим, когда47 уже улетел на26; не подменять таймеры рейса.

5Б.2в.7: ещё4 проверки (всего115): экономический close/battle одновременно
переводит MINING61 в paused OBSERVE43; absolute recovery включает тип команды;
выход одного добытчика не останавливает/не дублирует второго; ресурсная приписка
и finish=stay пока отклоняются. Проверка индексов типа/времени выполняется
самим main при применении journal, не только расчётным double.
На основном сервере прошли реальный бой по мобильному контакту, независимость
двух добытчиков, restart, reload, ручное продолжение первого и отзыв второго
через SPA. Это не crash-injection каждого окна и не публичный SendMission MINING.
`migrations/20261026_mining_battle_qa.php --prepare` требует остановленного main,
готовит только QA9/10 и выдаёт перечисленные в выводе тестовые корабли/топливо.
`--repair-attack` предназначен только для неначатой шестикомандной цепочки этого
fixture; после состоявшегося боя неприменим. Финальный подготовщик использует
обычный MOVE→ATTACK и серверный snapshot FLEET. Не запускать как smoke/повторно,
не обходить guard и не изменять main-БД во время работы сервера. Числа, ошибки
двух первых попыток подготовки и границы покрытия: docs/fleet-mining.md/5Б.2в.7.

5В.0: ещё4 проверки (всего119) общего close двух добытчиков, точной identity
сектора, исключения отсутствующего/улетевшего флота и подтверждённой охраны.
Живой основной сервер проверил один общий бой64 против58/61/62 у сектора19;
сосед59 и отсутствующий60 не вошли. Общая оборона ещё ограничена фазами,
неподдержанный защитник блокирует всю атаку. Контроль paused добытчиков после
гибели IDLE-охраны — B023. Режим mining_battle_qa.php --shared-belt требует
остановленного main и свободной базы19, выдаёт перечисленные в выводе активы
только QA9/10, не запускается как smoke. Детали: docs/fleet-resource-battles.md.

Ресурсное базирование/SPY: ещё3 чистые проверки (всего122) маскировки IDLE,
MINING/охраны/удержания у газового гиганта и сектора, реального MOVE с
сохранённым OBJECT начала и отличия свободной POINT-стоянки. Живой основной
сервер и SPA подтвердили скрытые53/54, видимый контроль65, SPY67 по гиганту23,
общий снимок1 транспорта+2 добытчиков, штатный возврат20 зондов и регрессию SPY
планеты. Детали/границы: docs/fleet-resource-spy.md.
`migrations/20261026_resource_spy_qa.php --prepare` — одноразовая offline-
подготовка только QA9/10 при остановленном main, не smoke и не второй сервер.
Фактические миссии после подготовки отправляются обычным SPA.

Сенсорный маршрут3А.2: ещё3 проверки (всего125) точного порога score8,
identity текущих OBJECT, запрета утечки другого Fleet ID/lastKnownPosition,
невалидных времён и межсистемного endpoint. Настоящий браузер:
`python3 tools/check_sensor_route.py` в SPA-проекте —5 read-only fixture-проверок
движения/заморозки/tier/cleanup и1440/390/320px с wheel/pan. Это не новая
отправка флота. Существующий FleetSensorSwooleTest актуализирован под route
на tier3, но по указанию пользователя изолированный Swoole-набор не запускался.
`migrations/20261026_sensor_route_qa.php --prepare` требует остановленного main,
готовит только QA9/планету26:2 зонда во Fleet «QA Sensor route». Не запускать
как smoke/повторно; --repair-capacity — только исправление исходного1-зондового
неначатого fixture. Живой сценарий и границы: docs/fleet-sensor-route.md.

Существующий `php tests/MessagesTest.php` дополнен регрессией B014:320 сообщений,
длинные to/to_read/to_type, чтение/удаление на границах частей, перенос владельца,
удаление пустых частей и обнаружение отказа записи с восстановлением индекса.
Host-double теперь соблюдает реальный лимит2048, а не прежние8192 байта.
Он не заменяет проверку настоящего dev-сервера/SPA после rebuild индексов.

23.10.2026 прежний baseline с 25 ожидаемыми падениями заменён. Удалены обращения
к отсутствующим FleetMovement/FleetPosition, устаревший конструктор FleetShip,
Reflection-конструирование Fleet со старыми полями и проверки удалённого API.
Рабочие проверки индекса, FleetTarget, FleetResource, API и startup сохранены
и уточнены. Игровой код для подгонки под старые тесты не менялся.

## Движение, runtime и сохранение

При изменении текущих контрактов обновлять соответствующие проверки и этот
baseline в том же этапе. Не добавлять разрешённые падения вместо исправления
устаревшего ожидания. Число прошедших проверок само по себе не доказывает полёт.

По указанию пользователя от 23.10.2026 дальнейшие проверки разработки проводить
прежде всего на текущем dev-сервере: обычные WS-команды, настоящий SPA,
runtime snapshots и свежие логи. Новые изолированные наборы для этапа перехвата
не создаются; существующие контракты поддерживаются актуальными.

Старые сценарии не следует восстанавливать через фиктивные поля Fleet:

| Область | Актуальные отдельные наборы |
| --- | --- |
| Время, геометрия 2D, переходы, локальные объекты | FleetStage3, FleetSpeed |
| Расход топлива, загрузка, возвращение | FleetFuelConsumption, FleetTransport, FleetFuelSwoole |
| Состав, груз, владение, registry, dirty/delete | FleetManagement, FleetExperiencePersistence, FleetPlanSwoole |
| Удаление временного PHP-объекта не удаляет флот | FleetSensorSwoole |
| Пространственный бой и checkpoint/POINT в MySQL | FleetSpaceBattleSwoole, SpaceBattlePersistenceMysql |

Host-наборы используют изолированные Table doubles. Native-наборы запускаются
в отдельных процессах контейнера с настоящими Swoole Tables. SQL-проверки аудита
используют только TEMPORARY таблицы соединения. Это разные уровни доказательства;
ни один из них не заменяет настоящий browser-тест SPA.

## Runner аудита полётов

```bash
python3 tests/run_flight_audit.py --only CoreContracts
python3 tests/run_flight_audit.py --only FleetStage3
python3 tests/run_flight_audit.py --only FleetSpaceBattleSwoole
```

Без `--only` запускаются все зарегистрированные наборы аудита полётов.
Это не автоматическое обнаружение всех тестов проекта. На стенде native-наборы
выполнять последовательно (`--jobs=1`, по умолчанию), не одновременно с рестартом.

Любой провал даёт ненулевой exit code runner. Список LEGACY_FAILURES и допуск
`expected_legacy_baseline` удалены; `LegacyBaseline` заменён на `CoreContracts`.
SKIP также не считается пройденной проверкой. Логи каждого набора и results.json
сохраняются в указанном runner каталоге `/tmp/sp-game-flight-audit-*`.

Регрессии самого runner, без запуска Docker/игровых тестов:

```bash
python3 tests/test_flight_audit_runner.py
```

Проверяются success, прежний результат 5 passed / 25 failed как ошибка, FAIL при
нулевом exit code, SKIP, timeout с частичным выводом и ненулевой exit без FAIL-строки.
Исторические отчёты в docs сохраняют реальные результаты прежних запусков;
упоминание в них старого baseline не является текущим разрешением ошибок.
