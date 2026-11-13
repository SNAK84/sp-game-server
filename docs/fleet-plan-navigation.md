# B041 — завершение ожидания навигации в редакторе маршрута

## Причина

`requestSystem` снимал `row.pending` после invalid NavigationSystem/onError,
но вызывал только updateSubmit. Наличие pending WS=0 не обновляло
aria-busy панели, missionPending и общий fuel notice. Эти поля обновляются
через существующие refresh → requestMissions / paint / updateFuelNotice.
Зависимые этапы тоже могли сохранять состояние прежнего pending origin.

На основном dev до исправления воспроизведено в настоящем SPA, QA37:
galaxy0/system6, серверный status=ok и NavigationSystem.valid=false /
«Система недоступна.», pending WS0, aria-busy первого этапаtrue,
fuel notice «Расчёт расхода всех перелётов…». Submit был правильно disabled.

## Изменение

Только production-файл SPA `js/pages/FleetsPageClass.js`, requestSystem:

- Начало запроса обновляет всю проекцию pending через существующий refresh.
- Invalid navigation и onError также вызывают refresh после смены состояния.
- Валидный ответ по-прежнему проходит apply → refresh ровно один раз.
- Сохраняются navigationError, запрет submit, revision/alive/rows guards,
  проверка доступности миссий и последующего origin. Никаких обходов валидации.

DOM не пересоздаётся. Backend, схема, API, transport, протокол и выполнение
игровых событий не меняются. Это не исправление отложенного B040/logout.

## Проверки

```bash
python3 tests/FleetPlanNavigationLiveTest.py --dev
# Дополнительно прежняя регрессия B011 на desktop/mobile:
python3 tests/FleetPlanNavigationLiveTest.py --dev --resource-regression
```

Тест создаёт/закрывает свою Selenium-сессию, использует основной сервер,
обычную авторизацию QA qaown_260924 и существующий базирующийся флот37.
Новые игровые объекты, маршруты и события не создаются; SetFleetCommands /
SendMission запрещены тестом. Credentials не печатаются и остаются в RAM.

Основная матрица:41 проверка PASS на1440×900 и390×844:

- Реальные nav-ответы на galaxy0 и system0, снятие aria-busy/«Расчёт…» у
  всего маршрута. При неверной цели причина видна, отправка всё ещё запрещена.
- Исправление координаты запускает прежний нормальный preview; после ответа
  расчёт доступен и submit снова enabled.
- Настоящий read-запрос с контролируемым клиентским timeout350мс: только
  доставка его реального ответа задержана в тестовом браузере. Штатный timer
  WS вызывает onError; проверяется снятие ожидания и сохранение блокировки.
  Это не имитация нового server response и не реальное отключение провайдера.
- Доставка позднего ответа после timeout не снимает ошибку: действует прежняя
  защита ignoredRequestIds. Новая корректная координата восстанавливает расчёт.
- Ссылки на editor, panel, input и submit сохранены после invalid/error/recovery;
  все действия read-only, runtime JS errors0.

`--before` предназначен только для воспроизведения исходного зависания до
правки:4 проверки PASS. На исправленном клиенте ожидание зависания не пройдёт.
Изолированный игровой мир и restart основного main не нужны.

Финальный повтор01.11.2026:41/41 основной матрицы и по17/17 прежнего B011
на desktop/mobile — всего75 проверок PASS. В каждом ресурсном прогоне24
настоящих GetAvailableMissions; pending предыдущей системы, поздний ответ,
полный resource-origin, POINT-origin и возврат проверены. JS errors0,
игровых записей0. Python syntax и точечный diff-check PASS.
В свежем Docker-логе с15:26 UTC WARNING/ERROR/CRITICAL0; основной main работает
без restart (StartedAt2026-11-01T15:08:11Z). Своя Selenium-сессия закрыта.

## Файлы

- `/var/www/spa.snak84.ru/js/pages/FleetsPageClass.js` — production-исправление.
- `/var/www/spa.snak84.ru/tools/check_fleet_resource_preview.py` — прежний B011
  теперь также проверяет снятие aria-busy и топливного ожидания при ошибке.
- `tests/FleetPlanNavigationLiveTest.py` — основная browser-регрессия.
- `tests/README.md`, `docs/found-bugs.md`, `docs/fleet-resource-preview.md`
  и этот файл — запуск, история и результаты.
