#!/bin/sh
set -e

MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		if [ -f /etc/frankenphp/Caddyfile.dev ]; then
			cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		fi
		;;
	worker)
		if [ -f /app/docker/frankenphp/Caddyfile ]; then
			cp /app/docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
		fi
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

mkdir -p /app/var/cache /app/var/log /app/var
chmod -R 777 /app/var 2>/dev/null || true

if [ ! -f /app/.env ]; then
	cp /app/.env.example /app/.env 2>/dev/null || true
fi

if [ ! -f /app/vendor/autoload_runtime.php ]; then
	echo "vendor not found, running composer install..."
	composer install --no-interaction --working-dir=/app || exec tail -f /dev/null
fi

if [ -d /app/vendor ]; then
	php /app/bin/console doctrine:database:create --if-not-exists --no-interaction 2>/dev/null || true
	php /app/bin/console doctrine:schema:update --force --no-interaction 2>/dev/null || true
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
