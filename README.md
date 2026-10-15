# Chat Kit Bundle

[![CI](https://github.com/nowo-tech/ChatKitBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/ChatKitBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/chat-kit-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/chat-kit-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/chat-kit-bundle.svg)](https://packagist.org/packages/nowo-tech/chat-kit-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/chat-kit-bundle.svg?style=social&label=Star)](https://github.com/nowo-tech/ChatKitBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** [Install from Packagist](https://packagist.org/packages/nowo-tech/chat-kit-bundle) · Give it a **star** on [GitHub](https://github.com/nowo-tech/ChatKitBundle) so more developers can find it.

**Chat Kit Bundle** — floating LinkedIn-style chat for Symfony: direct messages, channels and a support inbox with realtime (Mercure), presence, read receipts, typing, Web Push, customizable Twig templates and theme tokens.

> 📋 **Compatible with Symfony 7.4+ and 8.0–8.1+** — PHP 8.3+ (Symfony 8.x requires PHP 8.4+).

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**. See [docs/FRANKENPHP-WORKER-AUDIT.md](docs/FRANKENPHP-WORKER-AUDIT.md).

> **Status:** `1.0.x-dev` skeleton. The package infrastructure (configuration, asset package, QA, CI, demo) is in place; the chat features listed below are being migrated into the bundle and are **planned**, not yet shipped.

## Features

Planned for the first stable release:

- **Direct messages** between users of the host application
- **Channels** bound to host resources (projects, rooms) with membership resolved by the host
- **Support inbox** — users write to "Support"; agents answer from a shared inbox
- **Realtime** delivery through **Mercure** (FrankenPHP built-in hub or standalone)
- **Presence**, **typing** indicators and **read receipts**
- **Buzz**, configurable **sounds**, **emoji** picker and cross-tab sync
- **Web Push** notifications only when the application is not open
- **Customizable markup**: every UI fragment is a Twig-rendered `<template>` you can override
- **Theme tokens**: CSS custom properties `--nowo-chat-*` on `.nowo-chat-` components
- **Host extension points**: an access / reachability provider interface (who may talk to whom) and a user display provider interface (names, avatars, profile URLs)
- Named configuration **profiles** (`default_profile` + `profiles`)

## Installation

```bash
composer require nowo-tech/chat-kit-bundle
```

With **Symfony Flex**, the recipe registers the bundle and creates `config/packages/nowo_chat_kit.yaml`. Without Flex, see [docs/INSTALLATION.md](docs/INSTALLATION.md).

```php
// config/bundles.php
return [
    Nowo\ChatKitBundle\NowoChatKitBundle::class => ['all' => true],
];
```

## Requirements

- PHP `>=8.3 <8.6`
- Symfony 7.4, 8.0, and 8.1+
- Planned optional integrations: Doctrine ORM (persistence), Mercure (realtime), Messenger (async fan-out), `minishlink/web-push` (push)

## Configuration

```yaml
# config/packages/nowo_chat_kit.yaml
nowo_chat_kit:
    default_profile: default
    profiles:
        default:
            enabled: true
```

See [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

## Demo

A Symfony 8.1 demo runs on FrankenPHP (worker mode by default) with MySQL:

```bash
make -C demo up
# Demo started at: http://localhost:8147
```

See [docs/DEMO-FRANKENPHP.md](docs/DEMO-FRANKENPHP.md).

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Architecture and planned features](docs/ARCHITECTURE.md)
- [PSR evaluation](docs/PSR.md)
- [Coverage policy](docs/COVERAGE.md)
- [Demo FrankenPHP](docs/DEMO-FRANKENPHP.md)
- [FrankenPHP worker audit](docs/FRANKENPHP-WORKER-AUDIT.md)
- [GitHub CI](docs/GITHUB_CI.md)
- [GitHub About](docs/GITHUB.md)

## Tests and coverage

PHP line coverage target: **100%** of includable `src/` (gate **≥ 99%**). TypeScript line coverage target: **100%** (gate **≥ 90%**).

```bash
make test
make test-coverage
make test-ts
make qa
```

| Language | Latest reported |
| --- | --- |
| PHP | 100% lines (skeleton; `make test-coverage`) |
| TypeScript | 100% lines (skeleton; `pnpm run test:coverage`) |

## License

MIT © 2026 Héctor Franco Aceituno / Nowo.tech
