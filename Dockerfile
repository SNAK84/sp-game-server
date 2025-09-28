FROM openswoole/swoole:latest

# Устанавливаем pdo_mysql
RUN docker-php-ext-install pdo pdo_mysql
