# PHP 8.4 Alpine for development, tests, and frontend builds.
FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    git \
    unzip \
    bash \
    libzip-dev \
    icu-dev \
    nodejs \
    npm \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) zip intl pdo_mysql

RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && printf 'pcov.directory=/app\npcov.exclude="~(vendor|demo|node_modules|coverage)~"\n' \
        > /usr/local/etc/php/conf.d/pcov-directory.ini \
    && apk del $PHPIZE_DEPS

RUN npm install -g pnpm@10

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV XDEBUG_MODE=coverage
