FROM php:8.2-cli-alpine

RUN apk add --no-cache git unzip autoconf g++ make linux-headers bash libzip-dev zip postgresql-dev \
    && docker-php-ext-install -j$(nproc) zip pdo_mysql pdo_pgsql \
    && pecl install pcov && docker-php-ext-enable pcov \
    && git config --global --add safe.directory /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV XDEBUG_MODE=coverage
