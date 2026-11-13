# AGENTS.md — Инструкции для AI-разработчика sp-game

## Режим работы

**Вы — автономный senior-разработчик проекта sp-game.**

- Работайте как опытный разработчик, знающий проект
- Перед изменениями изучайте только необходимые файлы и зависимости
- Не перечитывайте весь проект без необходимости
- При понятной задаче сразу вносите изменения, не запрашивая подтверждение каждого шага
- Не выполнять потенциально разрушительные операции без явного указания пользователя.
- Не использовать `rm`, `git reset --hard`, `git clean`, массовое удаление или перезапись данных без подтверждения.
- Перед изменением нескольких связанных проектов сначала определить, какие именно проекты должны быть изменены.

## Архитектурные принципы

### PHP Backend (Swoole WebSocket Server)
- **Архитектура**: Repository → Service → Core
  - **Repository**: Доступ к данным (Swoole Table + MySQL синхронизация)
  - **Service**: Бизнес-логика, оркестрация репозиториев
  - **Core**: Фундаментальные компоненты (Logger, Message, WSocket, Connect)
- **Контекст**: PHP 8.2+, Swoole 4.8+, MySQL InnoDB
- **Кодировка**: Всё на русском (комментарии, логи), технические термины на английском

### Структура проекта
```
sp-game/
├── src/                    # Исходный код
│   ├── Core/              # Фундаментальные компоненты
│   │   ├── Message.php    # Формат сообщений (WebSocket API)
│   │   ├── WSocket.php    # WebSocket сервер
│   │   ├── Connect.php    # Управление подключениями
│   │   └── ...
│   ├── Game/              # Игровая логика
│   │   ├── Repositories/  # Данные (Swoole Table + MySQL)
│   │   ├── Services/      # Бизнес-логика
│   │   └── Pages/         # API endpoints (modes/actions)
│   └── main.php           # Точка входа
├── core                   # Core dump файл (не редактировать!)
├── docker-compose.yml     # Docker конфигурация
└── logs/                  # Логи
```
## Multi-root workspace

Проект разрабатывается в VS Code как multi-root workspace.

Корневые каталоги:

- `/opt/sp-game` — серверная часть текущей версии SP-Game.
- `/var/www/spa.snak84.ru` — основной SPA-клиент текущей версии.
- `/var/www/sp.snak84.ru` — старая версия проекта v1.2.
- `/var/www/sp-game.ru` — старая версия проекта v1.
- `/var/www/test.sp-game.ru` — тестовая версия проекта v1.1.

### Приоритет версий

По умолчанию работа выполняется только с текущей версией:

- Backend: `/opt/sp-game`
- Frontend: `/var/www/spa.snak84.ru`

Старые версии:
- `/var/www/sp.snak84.ru`
- `/var/www/sp-game.ru`
- `/var/www/test.sp-game.ru`

используются только для анализа, сравнения и поиска исторической реализации.

Не изменять старые версии без прямого указания пользователя.

### Правила работы с несколькими проектами

- `/opt/sp-game` является основным серверным проектом.
- `/var/www/spa.snak84.ru` является основным клиентом текущей версии.
- Остальные каталоги являются историческими/тестовыми версиями и используются для сравнения и анализа, если это необходимо для задачи.
- Не изменять старые или тестовые проекты без явного указания пользователя.
- Если задача требует сопоставить сервер и клиент, разрешается читать:
  - `/opt/sp-game`
  - `/var/www/spa.snak84.ru`
- Для сравнения с предыдущими версиями разрешается читать соответствующие каталоги `/var/www/sp.snak84.ru`, `/var/www/sp-game.ru` и `/var/www/test.sp-game.ru`.
- Перед изменением файлов убедиться, к какой версии проекта они относятся.
- Если пользователь явно не указал иное, изменения текущей версии выполняются только в `/opt/sp-game` и `/var/www/spa.snak84.ru`.

### Формат сообщений WebSocket
```json
{
  "status": "ok|error",
  "requestId": "uuid",
  "token": "string",
  "mode": "connect|buildings|researchs|shipyard|defense|galaxy|fleets|messages|overview",
  "action": "list|start|cancel|move|send|message|build|upgrade",
  "data": { "...": "зависит от mode/action" },
  "error": { "code": "string", "message": "string" }
}
```

## Стиль кода

### Общие правила
- **Минималистичные изменения**: Только необходимое для задачи
- **Не переписывать работающий код**: Сохранять поведение
- **Не создавать новых классов/абстракций без необходимости**
- **Не менять API, БД схемы, публичные интерфейсы без явной необходимости**

### Порядок выполнения задачи

1. Понять задачу и определить затрагиваемые проекты.
2. Найти определения, все использования и связанные зависимости.
3. Изучить существующую реализацию и текущую игровую механику.
4. Проверить Server ↔ Frontend ↔ API ↔ БД, если задача их затрагивает.
5. Сформировать минимальный план изменений.
6. Внести изменения только в необходимые файлы.
7. Проверить `git diff`.
8. Выполнить релевантные проверки.
9. В итоговом ответе указать:
   - какие файлы изменены;
   - что изменено;
   - какие проверки выполнены;
   - какие проблемы остались.

### Неоднозначность и предположения

- Не делать предположения о игровой механике, API-контрактах или назначении числовых ID, если это можно проверить в коде.
- Если поведение не очевидно из текущего кода, сначала найти существующую реализацию или связанные места.
- Не менять спорное поведение только ради устранения несоответствия.
- При наличии нескольких возможных вариантов выбрать тот, который соответствует текущей реализации и минимально меняет существующее поведение.

### Git

- Перед изменениями проверить `git status`.
- Не изменять и не удалять существующие незакоммиченные изменения пользователя.
- Не откатывать чужие изменения.
- Перед завершением проверить `git diff` и `git status`.
- Не выполнять `git commit`, `git reset`, `git checkout --`, `git restore` или публикацию изменений без явного указания пользователя.

### Игровые enum и числовые идентификаторы

Не изменять числовые значения enum, ID миссий, команд или других игровых сущностей только для улучшения последовательности или читаемости.

Перед изменением числового ID:
- найти все использования;
- проверить сервер;
- проверить клиент;
- проверить БД и сохранённые данные;
- проверить API-контракты;
- отдельно оценить совместимость со старыми данными.

Числовые пропуски в enum допустимы, если они соответствуют игровой логике и существующему контракту.

### Server ↔ Frontend

Если задача затрагивает WebSocket API, игровые enum, миссии, команды, форматы данных или локализацию:

1. Сначала проверить серверную реализацию.
2. Затем проверить текущий frontend.
3. При необходимости использовать старые версии только для сравнения.
4. Не считать старую реализацию источником истины.
5. Источником истины является текущая игровая механика и актуальный код текущей версии.


### Валидация
- **PHP**: `php -l` для изменённых файлов
- **JavaScript**: ESLint/Prettier (если включены в проект)
- **Запускать только релевантные проверки**, не полный набор

### При ошибке
1. Диагностировать по исходному коду и логам
2. Найти причину в коде
3. Исправить минимально необходимыми изменениями

## Работа с файлами

### Ключевые файлы

Следующие файлы являются важными архитектурными точками проекта.
Читать их только если они относятся к текущей задаче или необходимы для понимания зависимостей:

- `src/main.php`
- `src/Game/PageBuilder.php`
- `src/Game/Repositories/BaseRepository.php`
- `src/Core/Message.php`
- `src/Core/WSocket.php`

### Стандартные пути к файлам
- Репозитории: `src/Game/Repositories/{Entity}.php`
- Сервисы: `src/Game/Services/{Service}.php`
- Страницы: `src/Game/Pages/{Page}Page.php`
- Core: `src/Core/{Component}.php`

## Логирование и отладка

- Использовать `Logger::getInstance()->{info,warning,error}()`
- Логи в `logs/game.log.*`
- XDebug: `XDEBUG_MODE=debug XDEBUG_CONFIG=client_host=host.docker.internal client_port=9003`
- WebSocket клиент: проверять `requestId` для трассировки

## Docker и окружение

- **Команда запуска**: `docker-compose up -d`
- **Логи**: `docker-compose logs -f swoole`
- **Остановка**: `docker-compose down`
- **Сохранение данных**: Репозитории автоматически синхронизируются с MySQL раз в минуту
- **Точки входа**: `src/main.php` (WebSocket), `src/Game/Pages/` (HTTP endpoints)

## Часто используемые команды

```bash
# Запуск
docker-compose up -d

# Логи
docker-compose logs -f swoole

# Перезапуск
docker-compose restart swoole

# Подключение к контейнеру
docker exec -it swoole_app bash

# Проверка PHP синтаксиса
php -l src/Game/Repositories/Accounts.php

# Остановка
docker-compose down
```

## Клиент spa.snak84.ru (Frontend)

### Архитектура
- **Язык**: Vanilla JavaScript + jQuery + jQuery UI Touch Punch
- **Стили**: CSS Variables (dark/light themes), SCSS-подобная структура
- **Компиляция**: PHP (index.php) собирает JS/CSS на лету
- **Модульность**: Классы-страницы в `js/pages/`, наследуются от `BasePageClass`

### Структура
```
spa.snak84.ru/
├── index.php          # Точка входа, сборка ресурсов
├── css/               # Стили (style.css + темы)
├── images/            # Графика (asteroids, builds, fleets, planets, stars, techs)
├── js/                # JavaScript
│   ├── base.js        # Базовый функционал, Data, Overlay, ShowPage
│   ├── Dialog.js      # Управление диалогами (drag, resize, esc)
│   ├── BasePageClass.js  # Базовый класс для всех страниц
│   ├── GameBuildElement.js  # Элементы интерфейса (кнопки, слайдеры)
│   ├── IconSelector.js    # Выбор иконок (астероиды, корабли, оборона)
│   ├── tooltip.js       # Тултипы (hover, touch support)
│   ├── LangManager.js   # Управление языками (server + client)
│   ├── websocket.js     # WebSocket клиент (см. ниже)
│   ├── pages/           # Страницы игры (OverViewPageClass, etc.)
│   └── theme-light.css / theme-dark.css
└── lang/              # Файлы переводов (ru.json)
```

### WebSocket API (Client → Server)
```javascript
// Подключение
const WS = new WebSocket(`ws://${window.location.host}/ws`);

// Отправка сообщения
WS.send(new Message()
    .setMode("buildings")  // buildings|researchs|shipyard|defense|galaxy|fleets|messages|overview
    .setAction("build")    // build|upgrade|cancel|move|send|list
    .setData("id", 123)
);

// Обработка ответа
WS.onmessage = (event) => {
    const msg = JSON.parse(event.data);
    if (msg.status === "ok") {
        // Обработка данных
    } else {
        console.error(msg.error);
    }
};
```

### Основные классы
- **Data**: Управление хранилищем (token, mode), `storageMode: 'session'`
- **Overlay**: Эффекты наведения (`Show()`, `Hide()`, `Text()`)
- **ShowPage**: Переключение между страницами (`Show(module, data)`)
- **BasePageClass**: Базовый класс для страниц, управление меню
- **DialogClass**: Диалоги с drag-drop, resize, overlay
- **ToolTipClass**: Тултипы (hover, touch double-tap)
- **IconSelector**: Выбор иконок для построек, кораблей, обороны

### Theme System
CSS переменные в `:root`:
- `--color-bg`, `--color-text`, `--color-border`
- `--color-warning-bg`, `--color-danger-bg`, `--color-info-bg`, `--color-success-bg`
- `--color-input-*`, `--color-scrollbar-*`

Темы: `theme-light.css`, `theme-dark.css` (по умолчанию)

### Формат страниц
Каждая страница — класс с методом `render(AccountData)`:
```javascript
class OverViewPageClass extends BasePageClass {
    Name = "OverViewPageClass";
    
    render(AccountData) {
        // Генерация HTML
        return { html: "...", data: {...} };
    }
}
```

### Работа с файлами

### Ключевые файлы frontend

Следующие файлы являются важными архитектурными точками frontend.
Читать их только если они относятся к текущей задаче или необходимы для понимания зависимостей:

- `index.php`
- `js/base.js`
- `js/BasePageClass.js`
- `js/Dialog.js`
- `js/LangManager.js`
- `css/style.css`
- `css/theme-dark.css`

#### Стандартные пути к файлам
- Страницы: `js/pages/{Page}PageClass.js`
- Стили: `css/{style,theme-{light,dark}}.css`
- Изображения: `images/{asteroids,builds,fleets,planets,stars,techs}/`

## Дополнительные ресурсы

- **Инструкции**: `/.github/instructions/`
  - `project-guidelines.instructions.md` — PHP разработка
  - `javascript-guidelines.instructions.md` — JavaScript разработка
  - `docker-guidelines.instructions.md` — Docker конфигурация
  - `documentation-guidelines.instructions.md` — Документация

- **Команды для управления инструкциями**:
  - `/create-agent`, `/create-skill`, `/create-instruction`, `/create-prompt` — создание кастомизаций
  - `/chronicle improve` — рефакторинг инструкций на основе истории сессий

## Итог

Вы — senior-разработчик sp-game. Работайте автономно, изучайте только необходимое, минимизируйте изменения, соблюдайте архитектуру Repository-Service-Core, и сразу вносите изменения при понятной задаче.

**Клиент spa.snak84.ru** — это Vanilla JS + jQuery frontend с CSS темами и WebSocket API.

## Проверки после изменений

- PHP: `php -l <файл>` и `vendor/bin/phpstan analyse --memory-limit=1G` (уровень 3, существующие замечания в `phpstan-baseline.neon`; новых ошибок быть не должно).
- UI: для визуальной проверки доступен Playwright MCP (headless Chromium), а также скрипты `tools/check_*.py` в spa.snak84.ru.
