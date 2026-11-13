#FROM openswoole/swoole:latest

# Устанавливаем pdo_mysql
#RUN docker-php-ext-install pdo pdo_mysql

FROM php:8.2-cli
# Установим инструменты и dev-пакеты для сборки расширений
RUN apt-get update && apt-get install -y --no-install-recommends \
        mc git zip unzip pkg-config libssl-dev autoconf make gcc g++ \
    # Устанавливаем Swoole и Xdebug
    && pecl install openswoole xdebug \
    && docker-php-ext-enable openswoole xdebug \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*


# Копируем конфигурацию Xdebug и PHP-логов
#COPY php.ini /usr/local/etc/php/conf.d/php.ini
#COPY xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini

WORKDIR /var/www/html