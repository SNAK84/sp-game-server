# T002: тестовые ошибки не попадают в игровой log

Исправлено02.11.2026 в текущем backend `/opt/sp-game`. SPA и старые версии
не менялись; игровые логи не очищались и не переименовывались этим этапом.

## Причина и безопасный повтор

FleetPushTest использует настоящий Logger через FleetTransportTest/bootstrap.
До правки такой Logger получал из игрового .env путь ./logs/game.log,
rotation=size, fileEnabled=true. Ожидаемый отказ тестовой transition-команды
поэтому выглядел в игровом log как ошибка реального Fleet1/command7.

Просто назначить свойство logFile через Reflection недостаточно: перед
записью updateLogFile снова читает LOG_FILE. Environment::get отдаёт приоритет
своему .env-map; обычный LOG_FILE из процесса его не перекрывает. Повторный
Environment::init ещё раз загружает игровые настройки.

Репродукция выполнена на двух искусственных .env и surrogate-log с sentinel
в собственном временном каталоге. Все12 сочетаний cold/warm Logger,
автоматического/явного пути, none/daily/size меняли surrogate-игровые файлы
до правки. Это не намеренная запись контрольной ошибки в реальный game.log.
Контроль без bootstrap и без override сохранял обычный путь Logger.

## Изменения

- Logger.php: единый private resolveLogFile используется в constructor и
  updateLogFile. Только в CLI учитывается process-env SP_GAME_TEST_LOG_FILE;
  при его отсутствии остаётся прежний Environment::get('LOG_FILE',default).
  Флаг не читается из JSON/WS или игрового .env. Ротация и redaction сохранены.
- tests/bootstrap.php: выделяет tempnam-файл для прямого CLI-запуска, если
  runner/родитель не назначил отдельный путь. При невозможности выделения
  выбрасывается исключение, не происходит fallback в игровой log. Bootstrap
  не печатает путь или служебные сообщения в stdout и не отключает диагностику.
- run_flight_audit.py: host-процесс получает SUITE.runtime.log в каталоге
  артефактов, host-экспорт — SUITE.export.runtime.log. Docker exec не наследует
  этот host-путь: native bootstrap выделяет временный файл своего filesystem.
- test_flight_audit_runner.py: проверены отдельный путь и сохранение JSON stdin
  экспорта; строгая трактовка failure/skip/nonzero/timeout осталась прежней.
- test_log_isolation.py: настоящие Logger/Environment в коротких PHP-процессах,
  без игрового мира/БД; проверяются файлы и их ротации, не только поле модели.

## Проверки

- 12 сочетаний после правки: обе контрольные записи найдены в отдельном
  runtime-log; содержимое и список surrogate-игровых файлов не изменились.
  Повторный .env, уже созданный Logger и size/daily-ротация проверены.
  Дополнительный контроль без bootstrap/override подтвердил прежний Logger.
- 7/7 unit-проверок runner, включая отдельный log экспорта и неизменённый JSON.
- Настоящий host FleetPush: прямой запуск и аудит `--only FleetPush` прошли.
  21 строка OK включает inherited fixtures, это не21 уникальный сценарий.
  Ожидаемый ERROR «У активной transition-команды отсутствует материализованная
  цель» для fleetId1/commandId7 присутствует в собственном runtime-log.
- Артефакт аудита: /tmp/sp-game-t002.08DgQN/fleet-push/FleetPush.runtime.log,
  18582 байт в снятой проверке; results.json:1 suite PASS/0 FAIL.
  Прямой запуск получил другой /tmp/sp-game-test-* и тот же ожидаемый ERROR.
- Реальный FleetAttackTest --export-fixture выдал корректный JSON31855 байт;
  отдельный экспортный log создан, служебный текст в JSON не добавлен.
- CredentialLoggerProbe с auto_prepend bootstrap:7/7 условий на5 уровнях и
  24 чувствительных полях. Секреты скрыты, входные данные не изменены,
  диагностические поля и безопасный trace сохранены. Log только в /tmp.
- Регрессия T001: SpyRandomContractTest711 PASS.
- PHP lint Logger/bootstrap, Python py_compile и scoped diff --check пройдены.
  PHPStan level3 с существующим baseline: No errors. Baseline не менялся.

Основной main не перезапускался: флага SP_GAME_TEST_LOG_FILE в его окружении
нет, новые тестовые CLI уже используют исправление. Main Running=true,
OOMKilled=false, StartedAt2026-11-02T13:53:57.982066773Z на проверке.
Игровые аккаунты и сохранённые игровые данные не изменялись.

## Границы и отдельные находки

Скрипт, который не использует bootstrap и не получил process override,
сознательно остаётся на обычных правилах Logger. Для диагностического CLI
показан безопасный auto_prepend_file в [tests/README.md](../tests/README.md).
Реальные игровые события main не должны исчезать из его игрового log.
Флаг не является API отключения логирования или универсальной песочницей.
Временные/артефактные файлы оставляются для анализа; автоочистка не добавлялась.
Существующие исторические записи тестов из game.log не удалялись.

При подготовке обнаружен отдельный B045: Environment::get теряет строку'0'
из-за `?: $default`. Проверка env-map: getInt('ZERO_VALUE',7) вернул7,
getBool('LOG_ECHO',true) при'0' вернулtrue. В surrogate используется
LOG_ECHO=false, эта существующая ошибка не исправляется скрыто в T002.
Протокол, P002 и пересмотр механики полётов в этап не включались.

Позднее02.11.2026 B045 исправлен отдельно: LOG_ECHO=0 корректно отключает
console echo. Матрица регрессии расширена до24 сочетаний+контроль, всё PASS.
Это не меняет исторический результат T002 выше; подробности:
[environment-zero-values.md](environment-zero-values.md).

## Файлы

- src/Core/Logger.php
- tests/bootstrap.php
- tests/run_flight_audit.py
- tests/test_flight_audit_runner.py
- tests/test_log_isolation.py
- tests/README.md
- docs/found-bugs.md
- docs/test-log-isolation.md
