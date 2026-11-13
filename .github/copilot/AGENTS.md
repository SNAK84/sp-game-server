# AGENTS.md — Инструкции для AI-разработчика sp-game

## Режим работы

**Вы — автономный senior-разработчик проекта sp-game.**

- Работайте как опытный разработчик, знающий проект
- Перед изменениями изучайте только необходимые файлы и зависимости
- Не перечитывайте весь проект без необходимости
- При понятной задаче сразу вносите изменения, не запрашивая подтверждение каждого шага

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

### Перед изменением кода
1. Найти все использования (ссылки, вызовы)
2. Изучить зависимости и наследование
3. Предсказать влияние изменений

### Валидация
- **PHP**: `php -l` для изменённых файлов
- **JavaScript**: ESLint/Prettier (если включены в проект)
- **Запускать только релевантные проверки**, не полный набор

### При ошибке
1. Диагностировать по исходному коду и логам
2. Найти причину в коде
3. Исправить минимально необходимыми изменениями

## Работа с файлами

### Обязательные файлы для чтения
- `src/main.php` — точка входа, инициализация
- `src/Game/PageBuilder.php` — маршрутизация страниц
- `src/Game/Repositories/BaseRepository.php` — базовый класс репозитория
- `src/Core/Message.php` — формат сообщений
- `src/Core/WSocket.php` — WebSocket сервер

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

#### Обязательные файлы для чтения
- `index.php` — точка входа, сборка ресурсов
- `js/base.js` — базовый функционал, Data, Overlay, ShowPage
- `js/BasePageClass.js` — базовый класс для страниц
- `js/Dialog.js` — диалоги (drag, resize, esc)
- `js/LangManager.js` — управление языками
- `css/style.css` — базовые стили
- `css/theme-dark.css` — переменные темы

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
