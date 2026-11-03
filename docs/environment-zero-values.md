# B045: Environment сохраняет строковый ноль

Исправлено02.11.2026 в текущем backend `/opt/sp-game`; основной сервер
штатно перезапущен. SPA, рабочий .env, БД-схема и WS-протокол не менялись.

## Причина и контракт

Завершающий `?: $default` в get() применялся ко всему результату выбора
`.env → $_ENV → getenv`. PHP считает'0' ложным: get терял значение,
getInt возвращал заданный default вместо0, getBool мог вернутьtrue вместоfalse,
а require отвергал корректный'0'. Это воспроизведено во всех трёх источниках.

Минимальная правка в Environment.php: сначала выбирается источник прежней
цепочкой `??`, затем default применяется только к false или пустой строке.

- .env остаётся выше $_ENV, а $_ENV выше process-env.
- '0' возвращается строкой; getInt даёт0, getBool — false, require принимает'0'.
- Пустая строка сохраняет прежний default. Если она выбрана сверху, значение
  нижнего источника не подставляется. Это важно для совместимости конфигурации.
- Отсутствующий ключ даёт default/null, require выбрасывает исключение.
- Null в map/$_ENV пропускается к следующему источнику как и раньше.
- Parser, init/reload, all(), правила преобразования строк и bool не переписаны.

## Проверки до/после

Новый EnvironmentTest использует только синтетические значения и собственный
временный файл. Тестовые переменные окружения/cache восстанавливаются finally;
рабочий .env и credentials в него не копируются. SQL0, Tables0, второго мира нет.

До правки:235 PASS/22 FAIL, только связанные с'0' сценарии. После257/257 PASS:
три источника, их приоритет, непустые строки/числа/булевы формы, get с разными
default, getInt/getBool/require, пустые/null/отсутствующие значения. Проверен
настоящий parser с0, "0", '0', пустыми значениями, inline-комментарием, all
и повторным init после изменения тестового файла.

T002-регрессия расширена на LOG_ECHO='0' и LOG_ECHO=false:24 сочетания
cold/warm Logger, автоматического/явного пути и none/daily/size ротации,
плюс контроль без bootstrap/override. Все25 вариантов PASS; оба marker
сохранены в тестовом log, игровые surrogate-log не изменены, stdout остаётся
корректным JSON. Это проверяет реальный потребитель getBool, не только getter.

Дополнительно:

- PHP lint Environment.php и EnvironmentTest.php PASS.
- PHPStan level3 с существующим baseline: No errors; baseline не менялся.
- Runner unit7/7, SpyRandomContract711 PASS.
- CredentialLoggerProbe через test-bootstrap:7/7, пять уровней Logger,
  24 чувствительных поля; redaction, диагностические поля и input сохранены.
- Python py_compile и scoped diff --check PASS.

## Применение в основном dev

Перед restart сравнение старого/нового get по allowlist несекретных настроек
не обнаружило изменений в действующем окружении контейнера. WS_PORT9501,
COMBAT_WORKERS2; незавершённых PlanetBattles/SpaceBattles и prepared mining0.
Это не вывод полного .env или проверка содержимого credentials.

Штатный manager stop завершился ExitCode0/OOMKilled=false; start выполнен
в finally. Main StartedAt2026-11-02T15:02:41.27171808Z, Running=true.
После запуска прежний сохранённый токен QA принят на настоящем WS:
overview0,125с, fleets0,342с, galaxy0,178с, все status=ok. Токен читался
только в RAM, не печатался и не менялся входом/выходом из аккаунта.

В настоящем game.log после18:02:41 МСК свежих WARNING/ERROR/CRITICAL0.
Логи не очищались. Browser-прогон не делался: UI не менялся; подтверждены
основной runtime, настоящая авторизация/страницы WS и отдельные CLI-контракты.

## Границы и файлы

getInt по-прежнему выполняет существующий cast, getBool использует прежний
список true-строк. Эта правка не вводит валидацию диапазонов каждой настройки:
ноль сохраняется как значение, но не объявляется допустимым портом/уровнем лога.
P002 и изменения протокола в этап не включались.

- src/Core/Environment.php
- tests/EnvironmentTest.php
- tests/test_log_isolation.py
- tests/README.md
- docs/found-bugs.md
- docs/test-log-isolation.md — ссылка из исторического T002.
- docs/environment-zero-values.md
