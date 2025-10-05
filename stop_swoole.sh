#!/bin/bash
CONTAINER="swoole_app"

# Получаем полный ID контейнера
CID=$(docker inspect --format='{{.Id}}' $CONTAINER 2>/dev/null)

if [ -z "$CID" ]; then
  echo "❌ Контейнер $CONTAINER не найден"
  exit 1
fi

echo "🧹 Очищаю лог контейнера $CONTAINER..."
sudo truncate -s 0 /var/lib/docker/containers/$CID/$CID-json.log

echo "🔄 Перезапускаю контейнер $CONTAINER..."
if docker stop $CONTAINER > /dev/null; then
  echo "✅ Контейнер $CONTAINER успешно остановлен"
else
  echo "❌ Ошибка при остановке контейнера $CONTAINER"
  exit 1
fi
