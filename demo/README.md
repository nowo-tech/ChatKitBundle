# Chat Kit Bundle — Demo

FrankenPHP demo for **Symfony 8.1** (PHP 8.5) running the real floating chat: Doctrine users, the bundle's contracts implemented in `src/Chat/`, the dock in the layout and the full-page chat inside the demo layout.

## Accounts

`make -C demo/symfony8 setup-db` creates the schema and seeds (idempotent, `app:demo:seed`):

| User | Name | Role |
| --- | --- | --- |
| `alice` | Alice Martin | member |
| `bob` | Bob Lee | member |
| `carol` | Carol Diaz | member |
| `support` | Sam Support | support agent (`ROLE_SUPPORT`) |

Password for all: `password`. Starter content: a direct conversation (alice ↔ bob), the shared **General** channel and a support thread from carol. Sign in with two accounts in two browsers to see live delivery.

## Quick start

```bash
make -C demo/symfony8 up
make -C demo/symfony8 setup-db
```

Default URL: **http://localhost:8147** (`PORT` in `demo/symfony8/.env.example`).

## Commands

| Command | Description |
| ------- | ----------- |
| `make -C demo/symfony8 up` | Build, install dependencies, publish assets, build the Vite entry |
| `make -C demo/symfony8 setup-db` | Create / update the schema and seed the demo accounts |
| `make -C demo/symfony8 down` | Stop containers |
| `make -C demo/symfony8 test` | Sync the bundle, seed, run the demo PHPUnit tests |
| `make -C demo/symfony8 test-e2e` | Playwright happy path (dock send, full page) — needs `up` + `setup-db` |
| `make -C demo/symfony8 demo-screenshots` | Regenerate `docs/images/demo/overview.webp` (README image) |
| `make demo-smoke` | From the bundle root: boot the demo and assert HTTP 200 (REQ-TEST-011) |
| `make update-deps` | Refresh bundle **and** demo Composer dependencies (from the bundle root) |

From `demo/`: `make up`, `make down`, `make test-symfony8`, `make release-check` (tests + healthcheck).

See [`docs/DEMO-FRANKENPHP.md`](../docs/DEMO-FRANKENPHP.md) for the FrankenPHP setup (worker / classic, Caddyfile, troubleshooting).
