# B012 — lifecycle показа и закрытия диалога

29.10.2026. Минимальная production-правка только в SPA `js/Dialog.js`.
Backend, WebSocket-протокол, игровой мир и сохранённые команды не менялись.
Перезапуск основного сервера не требовался.

## Причина и воспроизведение

До исправления в настоящем основном SPA выполнен read-only сценарий:

1. `Dialog.alert(..., () => Page.showTaskFleet(37))` на существующем QA-флоте.
2. Настоящий WebDriver-клик встроенной кнопки вызывает `close()`, затем callback.
3. `close()` ставит `fadeOut(250)`; `create/show` готовит следующее окно на том же
   постоянном контейнере, не отменяя предыдущую FX queue.
4. После анимаций новый FleetTaskPanel присутствует, но у диалога `display:none`,
   у overlay — `display:block`. Ошибок JS нет.

Это специально собранный сценарий встроенного alert/callback, а не утверждение,
что найдена конкретная естественная игровая цепочка с таким alert. Он повторяет
исходное наблюдение `Dialog.close() → Page.showTaskFleet()` от24.10.

Дополнительная гонка: `create(); close()` до первого animation frame оставляет
запланированный показ. Его RAF после close снова включает overlay и подписывает
Esc, хотя диалог уже закрывается. До патча видимый overlay также воспроизведён.

## Исправление

- Сохраняется ID единственного ожидающего RAF; следующий show/close отменяет его
  и восстанавливает временно отключённый CSS transitionProperty.
- Перед новым переходом завершаются и очищаются старые jQuery-анимации диалога
  и overlay. Анимации больше не меняют состояние уже следующего окна.
- В актуальном RAF overlay явно включается или выключается по ShowOverlay,
  в том числе при переходе modal → nonmodal.
- Сохранены постоянные outer DOM-узлы, API/options, fadeIn300ms/fadeOut250ms,
  штатные кнопки, Esc, перетаскивание и resize. Прежняя замена содержимого при
  создании другого диалога не менялась; фоновые обновления сюда не добавлены.

## Проверки на основном SPA

```bash
python3 /var/www/spa.snak84.ru/tools/check_dialog_lifecycle.py
python3 /var/www/spa.snak84.ru/tools/check_dialog_lifecycle.py --mobile
```

Регрессия использует уже открытую QA Selenium-сессию основного SPA, без второго
игрового сервера. Вспомогательные окна — только UI fixtures; окно отправки37
получает настоящий server preview. Мутации игры запрещены guard-проверкой.

Пройдено19 desktop-проверок1365×900 и17 mobile-проверок390×844:

- обычный показ/кнопка закрытия, настоящий alert callback → окно миссии;
- Esc закрывает актуальное окно один раз, escEnabled=false сохраняется;
- close/create в одном task и reopen через50ms после начала fadeOut;
- close до первого RAF и во время fadeIn; два create до первого кадра;
- переходы modal/nonmodal, повторное close, error callback → следующее окно;
- drag/resize настоящими pointer actions на desktop.

На каждом переходе проверены visibility, overlay, актуальный заголовок,
сохранность оболочек, отсутствие ожидающего RAF/FX queue и восстановление CSS
transitionProperty. Новое поле сохраняет DOM/value. Переполнения ширины нет.
В обоих прогонах только GetSystemNavigationInfo/GetAvailableMissions, JS errors=0;
игровые миссии/маршруты не отправлялись. Скрипт восстанавливает перехваты и
viewport, затем перезагружает страницу для чистого завершения.

Дополнительно повторён `check_fleet_resource_preview.py`:17 проверок редактора,
22 настоящих preview, ошибок/игровых записей нет. Предыдущий B011 не вернулся.
JS загружен и выполнен Chromium; Python syntax и точечные diff-check пройдены.
Финальная страница Fleets: WebSocket открыт, pending requests=0, диалог/overlay
скрыты, showFrame=null. Основной контейнер остаётся запущенным с13:48:32 UTC;
после установки правки в14:25:09 UTC в407 свежих строках Docker-лога нет
ERROR/CRITICAL/Fatal. Отдельная Selenium-сессия проверки закрыта после прогонов.

## Границы этапа

Патч отвечает за собственные FX/RAF диалога. Он не меняет маршрутизацию и не
подменяет guards владельцев произвольных поздних server callbacks потребителей.
B013 и отдельные protocol-находки не включены в исправление.

Файлы: `/var/www/spa.snak84.ru/js/Dialog.js`,
`/var/www/spa.snak84.ru/tools/check_dialog_lifecycle.py`,
`docs/found-bugs.md`, этот отчёт.
