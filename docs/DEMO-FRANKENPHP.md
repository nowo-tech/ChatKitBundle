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
- [Timeouts](#timeouts)
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
- **The chat dock as an integrator ships it** — `templates/base.html.twig` includes `@NowoChatKitBundle/dock.html.twig`, which loads the bundle's IIFE `js/nowo-chat.js` and `css/nowo-chat.css` from `/bundles/nowochatkit/` (`assets:install`)
- **Pentatrion Vite + pnpm** — only for the demo's own entry `assets/app.ts` (demo chrome); it does not compile the bundle TypeScript
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

## Timeouts

The only blocking call the bundle makes inside a web request is the Mercure publish, through the application's
`HubInterface` / Symfony HttpClient (see [CONFIGURATION — Timeouts](CONFIGURATION.md#timeouts) and
[USAGE — Timeouts](USAGE.md#timeouts)). The demo layers its deadlines innermost first (REQ-RUNTIME-001):

| Layer | Where in the demo | Value |
| --- | --- | --- |
| Mercure hub HTTP client | `framework.http_client.default_options.timeout` (`config/packages/framework.yaml`) | 5 s |
| PHP `max_execution_time` (and `max_input_time`) | `docker/php-dev.ini` | 30 s |
| Caddy / FrankenPHP write timeout | `servers { timeouts { write 60s } }` in `docker/frankenphp/Caddyfile` and `Caddyfile.dev` | 60 s |
| FrankenPHP `max_wait_time` | `frankenphp { max_wait_time 30s }` in both Caddyfiles | 30 s (wait for a free PHP thread, then 504) |

**HTTP client 5 s < PHP 30 s < Caddy write 60 s.** FrankenPHP `max_wait_time` caps how long a request waits for a free
PHP thread, so a saturated pool answers 504 instead of growing an endless queue. Raise the three together if you change one. A timed-out publish is logged and the
request still succeeds; the dock catches up by polling.

## Troubleshooting

### Twig or PHP changes do not appear

Use `FRANKENPHP_MODE=classic`, confirm `twig.cache: false` in `dev`, and hard-refresh the browser.

### Demo does not start

Ensure Docker is running, port **8147** is free, and `make -C demo/symfony8 logs` shows FrankenPHP listening. Composer DNS: Compose sets `8.8.8.8` (REQ-DEMO-009).

### Browser console: 404 on `/build/…` or missing Vite entry

Pentatrion needs `public/build/entrypoints.json`. Run `make -C demo/symfony8 assets` (pnpm + `vite build`; also part of `make up` and `update-bundle`). Hard-refresh the page. Caddy serves `/build/*` as static files (no Vite dev-server proxy).

### Browser console: 404 on `nowo-chat.js`

The dock runs from the bundle's IIFE: `base.html.twig` includes `@NowoChatKitBundle/dock.html.twig`, which loads `/bundles/nowochatkit/js/nowo-chat.js` (asset package `nowo_chat_kit`). Pentatrion (`vite_entry_script_tags('app')`) only serves the demo's own `assets/app.ts`. A 404 means the public assets are not installed: run `make -C demo/symfony8 assets-install` (`assets:install public --symlink --relative`, also part of `make install`). If the file itself is stale, rebuild it from the bundle root with `make assets`.

### Database empty

The PHP service `depends_on` MySQL. Entrypoint runs `doctrine:database:create` and `doctrine:schema:update`. MySQL is **not** published to the host; use `docker compose exec php php bin/console` inside the network.
