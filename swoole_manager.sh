#!/bin/bash

CONTAINER="swoole_app"

# Функция для отображения справки
show_help() {
    echo "Использование: $0 {start|stop|restart|log} [--clear-logs|-cl]"
    echo ""
    echo "Команды:"
    echo "  start      - Запустить контейнер $CONTAINER"
    echo "  stop       - Остановить контейнер $CONTAINER"
    echo "  restart    - Перезапустить контейнер $CONTAINER"
    echo "  log        - Показать логи контейнера $CONTAINER"
    echo ""
    echo "Опции:"
    echo "  --clear-logs, -cl  - Очистить логи контейнера перед выполнением команды"
    echo ""
    echo "Примеры:"
    echo "  $0 start"
    echo "  $0 restart --clear-logs"
    echo "  $0 restart -cl"
    echo "  $0 stop --clear-logs"
    echo "  $0 log"
    echo "  $0 log -cl"
}

# Функция для получения ID контейнера
get_container_id() {
    CID=$(docker inspect --format='{{.Id}}' $CONTAINER 2>/dev/null)
    if [ -z "$CID" ]; then
        echo "❌ Контейнер $CONTAINER не найден"
        exit 1
    fi
}

# Функция для очистки логов
clear_logs() {
    echo "🧹 Очищаю лог контейнера $CONTAINER..."
    sudo truncate -s 0 /var/lib/docker/containers/$CID/$CID-json.log
}

# Функция для запуска контейнера
start_container() {
    echo "🔄 Запускаю контейнер $CONTAINER..."
    if docker start $CONTAINER > /dev/null; then
        echo "✅ Контейнер $CONTAINER успешно запущен"
        echo "📜 Логи контейнера $CONTAINER:"
        docker logs -f $CONTAINER
    else
        echo "❌ Ошибка при запуске контейнера $CONTAINER"
        exit 1
    fi
}

# Функция для остановки контейнера
stop_container() {
    echo "🛑 Останавливаю контейнер $CONTAINER..."
    if docker stop --timeout 60 $CONTAINER > /dev/null; then
        echo "✅ Контейнер $CONTAINER успешно остановлен"
    else
        echo "❌ Ошибка при остановке контейнера $CONTAINER"
        exit 1
    fi
}

# Функция для перезапуска контейнера
restart_container() {
    echo "🔄 Перезапускаю контейнер $CONTAINER..."
    if docker restart --timeout 60 $CONTAINER > /dev/null; then
        echo "✅ Контейнер $CONTAINER успешно перезапущен"
        echo "📜 Логи контейнера $CONTAINER:"
        docker logs -f $CONTAINER
    else
        echo "❌ Ошибка при перезапуске контейнера $CONTAINER"
        exit 1
    fi
}

# Функция для просмотра логов
show_logs() {
    echo "📜 Логи контейнера $CONTAINER:"
    docker logs -f $CONTAINER
}

# Проверка аргументов
if [ $# -eq 0 ]; then
    show_help
    exit 1
fi

# Парсинг аргументов
ACTION=""
CLEAR_LOGS=false

for arg in "$@"; do
    case $arg in
        start|stop|restart|log)
            if [ -n "$ACTION" ]; then
                echo "❌ Ошибка: Можно указать только одну команду"
                show_help
                exit 1
            fi
            ACTION="$arg"
            ;;
        --clear-logs|-cl)
            CLEAR_LOGS=true
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        *)
            echo "❌ Неизвестный аргумент: $arg"
            show_help
            exit 1
            ;;
    esac
done

# Проверка наличия команды
if [ -z "$ACTION" ]; then
    echo "❌ Ошибка: Не указана команда"
    show_help
    exit 1
fi

# Получаем ID контейнера
get_container_id

# Очищаем логи если указан флаг
if [ "$CLEAR_LOGS" = true ]; then
    clear_logs
fi

# Выполняем действие
case $ACTION in
    start)
        start_container
        ;;
    stop)
        stop_container
        ;;
    restart)
        restart_container
        ;;
    log)
        show_logs
        ;;
esac
