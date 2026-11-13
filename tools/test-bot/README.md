# SP-Game Test Bot

Отдельный тестовый клиент SP-Game. Бот имитирует игрока через WebSocket и
управляется HTTP API. Production-код не импортируется, доступ к MySQL и Swoole
Tables отсутствует.

Игровой протокол — только v2, включая штатные GameClient и Runner.
`app/operations.py` адаптирует существующие сценарии к именованным операциям;
mode/action остаются локальным форматом сценариев, не отправляются в сеть.
Низкоуровневый `app/protocol_v2.py` (ProtocolV2Client), методы
`connect/login`, `query/read_planets`, `ping/logout`; mode/action запрещены.
Для command добавлены `prepare_command`,
`submit_command`, `command_status`.
`python3 qa/check_protocol_v2_commands.py --execute [--restart-game]` проверяет
настоящую очередь, дедупликацию, потерю ответа и unknown после смены epoch;
меняет имя одной QA-планеты и восстанавливает его.
`python3 qa/check_protocol_v2.py --execute` — актуальный полный baseline:
проверяет запросы, все 30 барьеров владения командами, подписки, запрет v1,
штатный Runner-клиент и JSON-схемы на qaown_260924;
`--restart-game` дополнительно перезапускает текущий dev-сервер и проверяет
новый epoch при сохранении сессии. Явный --direct допускает существующий
локальный endpoint для QA при проблемах внешнего DNS; публичный WSS проверяет
Selenium SPA. Нужен установленный на хосте jsonschema;
новая runtime-зависимость бота не добавлялась.

T005: оба клиента используют общий ограниченный retry при явном ответе
`error/service.unavailable`, `retryable=true`, `fatal=false`. Для auth, query,
command, subscribe/unsubscribe — максимум6 попыток с паузами50/100/200/400/800мс.
Всё намерение фиксируется до первой отправки: requestId, issuedAt, epoch,
context и data одинаковы. Принятые/завершённые команды, transport timeout,
потеря ACK, завершение сессии и смена поколения соединения не повторяются.
Hello/ping/logout автоматически не повторяются. Новый запрос после неизвестного
исхода по-прежнему требует проверки состояния, не нового UUID вслепую.

```bash
docker exec sp_game_qa_bot python -m unittest discover -s tests -p test_protocol_v2_retry.py -v
python3 qa/check_protocol_v2_retry.py --execute
```

Вторая проверка использует основной dev и настоящий world barrier без копии
мира/backend-hook. Временно удерживает барьер до busy-ответа (максимум2с),
проверяет query/command у обоих клиентов и восстанавливает имя QA-планеты.
Служебные токены передаются через stdin, не argv/логи/публичные файлы.
T006: restart-baseline ждёт до240с по monotonic clock; успех требует auth и
непустого нового epoch. Истёкшее ожидание не считается удачным переподключением;
отозванную сессию не пытается автоматически заменить новым входом.

## Запуск

~~~bash
cp .env.example .env
# заполнить SP_GAME_LOGIN, SP_GAME_CABINET_EMAIL и SP_GAME_PASSWORD
docker compose up -d --build
~~~

API по умолчанию доступен только на 127.0.0.1:8095.
Для примеров ниже BOT_API_TOKEN должен быть задан в окружении shell;
используйте свой настроенный ключ, не публикуйте его значение.

~~~bash
curl -H "X-Bot-Token: $BOT_API_TOKEN" http://127.0.0.1:8095/health
~~~

Пароль и token не попадают в JSONL-логи: чувствительные поля автоматически
заменяются на <redacted>.

Вход выполняется через HTTPS API кабинета (`CABINET_API_URL`, по умолчанию
`https://cabinet.sp-game.ru`) и `GAME_WORLD_ID=sp-game-dev`. Пароль в WebSocket
больше не отправляется. `SP_GAME_LOGIN` сохраняет метку прежнего аккаунта,
`SP_GAME_CABINET_EMAIL` — email его подтверждённой учётной записи кабинета.

Каждый новый прогон явно входит в мир и заменяет предыдущую игровую сессию
этого аккаунта. При сетевом reconnect/restart существующий прогон использует
то же разрешение из закрытого файла `data/sessions/<runId>.json` (0600),
не повторяет вход с паролем и не вытесняет другой клиент автоматически.
При отзыве/истечении разрешения прогон останавливается; нужен новый запуск.
Не публиковать каталог `sessions`; он исключён из Git. Access/refresh кабинета
там не сохраняются, а токены кабинета/игры скрываются в журнале.

## Создание прогона

Read-only smoke-сценарий:

~~~bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -H "X-Bot-Token: $BOT_API_TOKEN" \
  http://127.0.0.1:8095/runs \
  -d '{"scenario":"smoke","duration":60}'
~~~

Сценарий с собственным списком действий:

~~~json
{
  "scenario": "manual",
  "duration": 300,
  "repeat": false,
  "steps": [
    {"command": "page", "mode": "buildings"},
    {"command": "build", "elementId": 6},
    {"command": "wait", "seconds": 2},
    {"command": "page", "mode": "buildings"},
    {"command": "cancel", "mode": "buildings", "queueId": 123}
  ]
}
~~~

Поддерживаются команды:

- page — загрузка страницы;
- request — произвольный WebSocket request в рамках игрового API;
- build, dismantle, research;
- shipyard, defense;
- cancel;
- planetselect;
- wait/sleep;
- reconnect;
- repeat.

Ошибочные ответы сервера можно проверять через expectStatus:

~~~json
{"command":"build","elementId":999999,"expectStatus":"error"}
~~~

По умолчанию ответ с ошибкой записывается в статистику, но не прерывает
сценарий. Для остановки сценария используется failOnError=true.

## Мониторинг

~~~bash
curl -H "X-Bot-Token: $BOT_API_TOKEN" http://127.0.0.1:8095/runs
curl -H "X-Bot-Token: $BOT_API_TOKEN" http://127.0.0.1:8095/runs/<run-id>
curl -H "X-Bot-Token: $BOT_API_TOKEN" http://127.0.0.1:8095/runs/<run-id>/logs
curl -X POST -H "X-Bot-Token: $BOT_API_TOKEN" \
  http://127.0.0.1:8095/runs/<run-id>/stop
~~~

## Надёжность и вопросы оператору

Состояние задач сохраняется в `/app/data/state.json` (version 2; version 1
читается). Checkpoint фиксирует текущий шаг, итерации вложенных `repeat`,
переменные/снимки и выбранную планету. Перед шагом сохраняется `pending`, после
подтверждения — следующий индекс и контекст одним atomic replace с `fsync`.
Завершённые действия после reconnect/restart не запускаются заново.
Переключение планеты считается законченным только после подтверждающего
`ActualData`, а не при получении ACK. Checkpoint привязан к login: если сценарий
переключался на другой аккаунт, продолжение под настроенным по умолчанию
аккаунтом запрещено; нужно остановить run и использовать бот нужного аккаунта.
`wait/sleep` сохраняет время окончания, а не начинает полный таймер повторно.
У `build_targets` каждая внутренняя build-команда также имеет durable-маркер:
после разрыва сначала проверяются очередь/уровень, повторная отправка не
разрешается по одному только пустому снимку очереди.

Безопасное чтение/ожидание продолжается автоматически, даже если ранее в
сценарии были мутации. У `page` с нестандартным action нет статуса безопасного
чтения. `resumeOnRestart: false` сохраняет явный запрет автопродолжения.
Поле `resumable` описывает декларативность всего сценария, а фактическое
продолжение теперь определяется checkpoint текущего шага.

Если ответ на `build`, `cancel`, `request` (в том числе `SendMission/Recall`)
или другую мутацию потерян, повторная отправка запрещена. Необязательный
`postcondition` проверяется через `ActualData`: он должен **не выполняться до**
отправки и выполняться после неё. Если результат не доказан, run переходит в
`waiting_for_instruction`. Пример условия для переименования конкретного флота:

~~~json
{
  "command": "request", "mode": "fleets", "action": "RenameFleet",
  "data": {"FleetId": 37, "Name": "QA unique run marker"},
  "postcondition": {
    "mode": "fleets",
    "path": ["data", "Page", "FleetList", "37", "name"],
    "equals": "QA unique run marker"
  }
}
~~~

Вместо `equals` разрешено `exists: true/false`. Условие должно однозначно
относиться к данной операции: изменение ресурсов или существующий чужой флот
не доказывают новую отправку. Ложное условие после разрыва **не доказывает**,
что команда не была применена. Неполный/ошибочный snapshot не считается
доказательством удаления. Это не новый игровой протокол и не гарантия
exactly-once для произвольного запроса без проверяемого результата.

~~~bash
curl -H "X-Bot-Token: $BOT_API_TOKEN" http://127.0.0.1:8095/questions
curl -X POST -H 'Content-Type: application/json' \
  -H "X-Bot-Token: $BOT_API_TOKEN" \
  http://127.0.0.1:8095/questions/<question-id>/resolve \
  -d '{"action":"retry","instruction":"condition checked; continue"}'
~~~

Действия оператора:

- `retry` — продолжить с checkpoint; неопределённая мутация только проверяет
  `postcondition`, но не отправляется повторно;
- `confirm_applied` — оператор проверил применение; пропустить текущую мутацию;
- `confirm_not_applied` — оператор доказал отсутствие применения; разрешить
  повтор только текущего шага;
- `stop` — остановить сценарий.

Оба `confirm_*` требуют непустой `instruction` с результатом проверки; решение
сохраняется до пробуждения runner. Старый недекларативный run без checkpoint
нельзя возобновить: только остановить и создать список оставшихся действий.
При истечении срока неподтверждённый или незавершённый одноразовый сценарий
становится `failed`, а не успешным `completed`.
Push-sequence относится к соединению, наследуется вложенными repeat и после reconnect обновляется; потерянный
push не подменяется snapshot автоматически. Credentials в checkpoint
скрываются (redaction), источником авторизации остаются переменные окружения.

Текст `instruction` не интерпретируется как игровая команда: если требуется
другой план, остановите run и создайте новый сценарий. Бот не может сам отправить сообщение
в чат Codex без отдельного внешнего webhook-интегратора; очередь `/questions`
является надёжной точкой, откуда оператор или Codex может получить вопрос.

Проверки: `python -m unittest discover -s tests`; живой regression P002 —
[`qa/checkpoint_live.py`](qa/checkpoint_live.py), результаты и порядок
запуска — [`qa/P002.md`](qa/P002.md).

Процесс запускается через локальный supervisor. Он периодически вызывает
аутентифицированный `/health`; если приложение перестало отвечать несколько
раз подряд, supervisor посылает ему SIGTERM и завершает контейнер с ошибкой.
`restart: unless-stopped` запускает контейнер заново. При штатной остановке
SIGTERM передаётся приложению, поэтому активный декларативный run остаётся в
durable state и возобновляется после следующего запуска.

`build_targets` по умолчанию считает элемент `4` способом восстановления
энергии. Когда энергия ниже `minEnergy`, он сначала строит целевую
энергостанцию, а не останавливается до её попытки. Для другой конфигурации
можно передать `energyRecoveryElements`, например `[4]`.

Перед каждой build-командой бот использует серверный прогноз
`BuildList[element].Prod.Next[911]`: обычная постройка не будет отправлена,
если после её завершения энергия опустится ниже `minEnergy`. После `status=ok`
бот также ждёт появления нужного элемента в очереди (или изменения уровня).
Если это не произошло за `ackTimeoutSeconds` (по умолчанию не менее 60 секунд),
создаётся вопрос оператору вместо повторной отправки потенциально дублирующей
команды.

## Ограничения первого этапа

1. GameClient повторяет текущий SPA wire-format и коррелирует ответы по
   requestId.
2. Initial connect/handshake без token допускает текущий ответ missing_token и
   продолжает login.
3. Built-in smoke только читает страницы. Mutating queue-сценарии требуют
   явного списка steps.
4. Fleet-specific DSL пока не добавляется: для него используется request до
   отдельной фиксации актуального client-server pipeline.
