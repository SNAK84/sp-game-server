#!/bin/bash
CONTAINER="swoole_app"

# Получаем полный ID контейнера
CID=$(docker inspect --format='{{.Id}}' $CONTAINER 2>/dev/null)

if [ -z "$CID" ]; then
  echo "❌ Контейнер $CONTAINER не найден"
  exit 1
fi

#echo "🧹 Очищаю лог контейнера $CONTAINER..."
#sudo truncate -s 0 /var/lib/docker/containers/$CID/$CID-json.log

echo "🔄 Запускаю контейнер $CONTAINER..."
if docker start $CONTAINER > /dev/null; then
  echo "✅ Контейнер $CONTAINER успешно запущен"
else
  echo "❌ Ошибка при запуске контейнера $CONTAINER"
  exit 1
fi

echo "📜 Логи контейнера $CONTAINER:"
docker logs -f $CONTAINER
