# FrankenPHP + Node for Chat Kit demo (Symfony 8.1, Pentatrion Vite, pnpm)
FROM dunglas/frankenphp:1-php8.5-alpine

RUN apk add --no-cache git unzip bash curl ca-certificates mysql-client nodejs npm

# Pin pnpm to packageManager in package.json (Alpine nodejs often has no corepack).
RUN npm install -g pnpm@10.32.1 \
    && pnpm --version
ENV PNPM_HOME="/usr/local/share/pnpm"
ENV PATH="/usr/local/bin:/usr/local/share/pnpm:${PATH}"

RUN install-php-extensions pdo_mysql zip intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app && \
    git config --global --add safe.directory /var/chat-kit-bundle

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
ENV SERVER_NAME=:80

COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile.dev

COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
