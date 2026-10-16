# Demo applications with FrankenPHP (development and production)

This document describes how the **Chat Kit Bundle** demo runs under **FrankenPHP** in Docker.

## Table of contents

- [Overview](#overview)
- [What the demo includes](#what-the-demo-includes)
- [Development configuration](#development-configuration)
  - [Caddyfile (development)](#caddyfile-development)
  - [PHP and Twig (development)](#php-and-twig-development)
  - [Start (development)](#start-development)
- [Production configuration](#production-configuration)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Troubleshooting](#troubleshooting)
  - [Twig or PHP changes do not appear](#twig-or-php-changes-do-not-appear)
  - [Demo does not start](#demo-does-not-start)
  - [Browser console: 404 on `/build/…` or missing Vite entry](#browser-console-404-on-build-or-missing-vite-entry)
  - [Browser console: 404 on `nowo-chat.js`](#browser-console-404-on-nowo-chatjs)
  - [Database empty](#database-empty)

## Overview

The `demo/` folder is **not shipped** in the Composer package (`archive.exclude` includes `/demo`). The demo exists only in the source repository.

The stack uses:

- **FrankenPHP** (Caddy + PHP) in a single `php` container
- **MySQL 8** on the Compose network (**no host port**; REQ-DEMO-006 / REQ-DEMO-011)
- Bundle mount `../..` → `/var/chat-kit-bundle`
- Two Caddyfiles: `Caddyfile` (worker) and `Caddyfile.dev` (classic)
- `FRANKENPHP_MODE` (`classic` \| `worker`, default **`worker`**)

| Demo | UI | Default port |
| --- | --- | --- |
| `demo/symfony8/` | Bootstrap 5 | **8147** |

```bash
make -C demo up-symfony8
# Demo started at: http://localhost:8147
```

| Aspect | Development (default) | Production |
| --- | --- | --- |
| FrankenPHP worker | Off when `FRANKENPHP_MODE=classic` | On (`worker`) |
| Twig cache | Off (`config/packages/dev/twig.yaml`) | On |
| OPcache revalidation | Every request (`docker/php-dev.ini`) | Default |
| HTTP cache headers | `no-store` in `Caddyfile.dev` | Omitted |
| `APP_ENV` / `APP_DEBUG` | `dev` / `1` | `prod` / `0` |
| Database | MySQL service `db` (`DATABASE_URL` uses hostname `db`) | Same image |

## What the demo includes

- **Symfony Web Profiler** — `dev` and `test`
- **Nowo Twig Inspector** and **Nowo Hot Reload** — `dev`/`test` only (Caddyfile Mercure hub + `hot_reload`; do not enable Hot Reload in production)
- **Twig Extra Bundle** (`twig/extra-bundle` + `twig/string-extra`)
- **Chat Kit Bundle** — Symfony 8.1 (PHP 8.5) app with the real chat: Doctrine users seeded by `make -C demo/symfony8 setup-db` (`alice`, `bob`, `carol` and the support agent `support`, password `password`), the dock on every signed-in page, a **General** channel, a support thread and the full-page chat at `/chat/`. See [`demo/README.md`](../demo/README.md).
- **Tests**: `make -C demo/symfony8 test` (PHPUnit), `make -C demo/symfony8 test-e2e` (Playwright: dock send, full page), `make -C demo/symfony8 demo-screenshots` (README image), `make demo-smoke` from the bundle root (boot + HTTP 200, REQ-TEST-011).
- **PHP / Symfony policy**: the demo tracks the latest PHP (8.5) and Symfony 8.1; the bundle itself supports PHP 8.3+ and Symfony 7.4 / 8.x (see the README requirements).
- **Pentatrion Vite + pnpm** — demo entry `assets/app.ts` compiles bundle TypeScript (`@bundle`)
- **MySQL** — ready for the chat Doctrine schema (no SQLite / `*.db`)

Example `config/bundles.php`:

```php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Nowo\ChatKitBundle\NowoChatKitBundle::class => ['all' => true],
    Nowo\HotReloadBundle\NowoHotReloadBundle::class => ['dev' => true, 'test' => true],
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class => ['dev' => true, 'test' => true],
];
```

## Development configuration

### Caddyfile (development)

`demo/symfony8/docker/frankenphp/Caddyfile.dev` — no worker, `no-store` cache headers, Mercure for Hot Reload.

### PHP and Twig (development)

`docker/php-dev.ini` revalidates OPcache. `config/packages/dev/twig.yaml` sets `twig.cache: false`.

### Start (development)

From the bundle root:

```bash
make -C demo up
```

`make up` in `demo/symfony8` prints `Waiting for container to be ready...`, `Installing dependencies...`, then `Demo started at: http://localhost:<PORT>` from `PORT` in `.env` / `.env.example` (REQ-DEMO-005).

## Production configuration

Use `FRANKENPHP_MODE=worker` (default) and `APP_ENV=prod`. Do not enable Hot Reload.

## Switching classic vs worker (`FRANKENPHP_MODE`)

Set `FRANKENPHP_MODE=classic` or `worker` in `.env`, then recreate:

```bash
docker compose up -d --force-recreate
```

The entrypoint copies `Caddyfile.dev` when mode is `classic`.

## Troubleshooting

### Twig or PHP changes do not appear

Use `FRANKENPHP_MODE=classic`, confirm `twig.cache: false` in `dev`, and hard-refresh the browser.

### Demo does not start

Ensure Docker is running, port **8147** is free, and `make -C demo/symfony8 logs` shows FrankenPHP listening. Composer DNS: Compose sets `8.8.8.8` (REQ-DEMO-009).

### Browser console: 404 on `/build/…` or missing Vite entry

Pentatrion needs `public/build/entrypoints.json`. Run `make -C demo/symfony8 assets` (pnpm + `vite build`; also part of `make up` and `update-bundle`). Hard-refresh the page. Caddy serves `/build/*` as static files (no Vite dev-server proxy).

### Browser console: 404 on `nowo-chat.js`

The demo home uses Pentatrion (`vite_entry_script_tags`), not the IIFE. The IIFE is still published under `/bundles/nowochatkit/` for hosts that use `assets:install`. Run `make -C demo/symfony8 assets-install` if you need that path.

### Database empty

The PHP service `depends_on` MySQL. Entrypoint runs `doctrine:database:create` and `doctrine:schema:update`. MySQL is **not** published to the host; use `docker compose exec php php bin/console` inside the network.
