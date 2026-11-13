# B001 — владелец общего Content и отложенные callbacks

Исправление27.10.2026. Runtime изменён только в текущем SPA:
`/var/www/spa.snak84.ru/js/BasePageClass.js`.

## Причина и доказательство до изменения

Все экземпляры страниц работают с одним DOM-корнем `#Content`.
`ShowPage.Show()` вызывает Hide предыдущей страницы, но не ждёт завершения
её fadeOut(250). Новый конструктор очищает Content и начинает render.
Позднее callback старого Hide снова вызывает Content.empty().

В настоящем SPA на основном dev перехвачен именно этот вызов: текущая
FleetsPageClass активна, Content содержит4 дочерних блока, но empty вызван из
`HTMLDivElement.<anonymous>` / `BasePageClass.Hide` (прежняя строка650).
После вызова Content пуст и `Page.$FlyingFleets[0].isConnected=false`.
Аналогично отсоединена карта Galaxy. Это не потеря server state и не B026.

## Минимальное исправление

- initContent назначает нового `pageOwner` до завершения прежней анимации,
  затем останавливает её и готовит содержимое нового контекста.
- ownsContent проверяет, принадлежит ли общий корень этому экземпляру.
- isRenderCurrent проверяет owner вместе с pageActive/renderGeneration.
- Show игнорирует вызов для уже заменённого экземпляра; завершения fadeIn
  не трогают Content/Overlay, если их поколение больше не актуально.
- Hide по-прежнему инвалидирует render, отменяет request scope, очищает
  owned timers и отключает TopNav ticker. Очистить/скрыть Content он может
  только пока остаётся его владельцем и не был снова показан.

Не изменялись ShowPage/WS routing, requestId, сервер, схема БД, игровые
команды, reconnect transport, simulation ангара и authoritative baseline.
Не добавлены задержки отправки запросов или ожидание окончания анимации.
Обычные Page/ActualData продолжают обновлять существующий DOM (контракт B026).

## Повторяемая проверка

Используется существующая собственная авторизованная Selenium-сессия и
основной dev-сервер. Скрипты не получают credentials, не запускают второй
игровой сервер и не выполняют отправку флотов/строительство/другие игровые
команды.

```sh
python3 /var/www/spa.snak84.ru/tools/check_page_lifecycle.py \
  --session <своя-session> --reconnect
python3 /var/www/spa.snak84.ru/tools/check_page_lifecycle.py \
  --session <своя-session> --phone --reconnect
python3 /var/www/spa.snak84.ru/tools/check_incremental_ui.py \
  --session <своя-session> --seconds 23
```

check_page_lifecycle выполняет10 нормальных переходов меню с настоящими
server responses. Затем отдельно делает8 быстрых UI-переходов на уже
полученных настоящих DTO и возвращается к свежему ответу сервера. UI-replay
не выдаётся за новые WebSocket responses. Сохранённые поздние callbacks
старых страниц вызываются повторно; они не должны трогать новое содержимое.
Проверяются видимый присоединённый DOM, owner, отсутствие старых timers/scopes,
остановка RAF/zoom старой Galaxy, JS errors и штатный reconnect.

Мобильный прогон390×844 прошёл:23 вызова Show за окно теста,16 выведенных
из работы экземпляров,8 UI-replay переходов (минимальный интервал210.8мс),
64 старых callback не изменили новый DOM. Late empty=0, JS errors=0;
старые timers/scopes/TopNav ticker очищены, старые Galaxy animation/zoom
остановлены. После reconnect видимая карточка флота осталась в document.

Desktop-прогон тоже прошёл:23 Show,17 retired instances,8 UI-replay переходов,
68 повторно вызванных старых callbacks без изменения нового DOM; late empty=0,
JS errors=0, reconnect завершён. Проверка timers/scopes/RAF прошла.

После исправления отдельно проверены фоновые обновления fleets, galaxy,
shipyard и defense: по23 секунды, всего9 ActualData в успешных проверках;
элементы/owner/menu/focus сохранены, JS errors=0. Для fleets выполнен повтор:
первый прогон дал focus=false без удаления DOM. В тест добавлены ожидание
окончания анимации layer и отдельная фиксация initialFocus; повтор дал
initialFocus=true/focus=true. Причина первого расхождения focus отдельно
не инструментировалась и не объявляется доказанным новым багом игры.

Настоящий planetselect текущей собственной планеты27 получил новый snapshot/
renderGeneration: экземпляр Page и корневой дочерний блок сохранены,
owner/visible/isConnected=true, pending requests=0. Это проверка обработчика
planetselect, а не выданное за неё переключение между разными телами.

## Файлы

- SPA `js/BasePageClass.js` — owner/generation guards и завершение старой
  анимации до создания нового содержимого.
- SPA `tools/check_page_lifecycle.py` — browser-регрессия B001.
- SPA `tools/check_incremental_ui.py` — существующая регрессия B026 теперь
  также требует непустой Content и актуального владельца: отсоединённая
  модель страницы не может дать ложный PASS.
- Backend `docs/found-bugs.md` — статус B001 и сохранённая история.
- Backend `docs/client-incremental-updates.md` — ссылка на последующее
  исправление B001 без изменения результатов предыдущего этапа B026.
- Backend `docs/client-page-lifecycle.md` — этот отчёт.

## Границы

B002 (mode=none) и B012 (гонка закрытия/создания Dialog) отдельные; этим
исправлением не закрываются. Новые игровые сущности создаются при смене
контекста; фоновое обновление прежней страницы не пересоздаёт её элементы.

В процессе отдельно подтверждён B037: ActualData/render может отменить
callback Overlay.Show до отправки перехода меню. При этом DOM/owner исправны,
а запрос не отправлен. Два ожидания целевого раздела в диагностических
прогонах завершились таймаутом с прежним видимым mode; это не выдано за PASS.
Повторные полные прогоны выше прошли. Для B037 есть отдельное воспроизведение
UI-replay и запись в реестре; этот этап не меняет общий Overlay/WS pipeline.

Для настоящего переключения между двумя своими телами есть флаг
`--planet-switch`. У использованных QA-аккаунтов по одной планете; живой
прогон планета↔луна здесь не выполнен, тела/ресурсы ради UI-проверки не создавались.
