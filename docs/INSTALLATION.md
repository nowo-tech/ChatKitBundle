# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Register the bundle (without Flex)](#register-the-bundle-without-flex)
- [Configuration file](#configuration-file)
- [Publish assets](#publish-assets)
- [FrankenPHP](#frankenphp)

## Requirements

- PHP `>=8.3 <8.6`
- Symfony 7.4, 8.0 or 8.1+ (FrameworkBundle)
- Planned optional integrations (not required by the skeleton): Doctrine ORM, `symfony/mercure-bundle`, Symfony Messenger, `minishlink/web-push`

## Install with Composer

```bash
composer require nowo-tech/chat-kit-bundle
```

With **Symfony Flex**, the recipe (`.symfony/recipe/`) registers the bundle and copies `config/packages/nowo_chat_kit.yaml`.

## Register the bundle (without Flex)

```php
// config/bundles.php
return [
    // ...
    Nowo\ChatKitBundle\NowoChatKitBundle::class => ['all' => true],
];
```

## Configuration file

```yaml
# config/packages/nowo_chat_kit.yaml
nowo_chat_kit:
    default_profile: default
    profiles:
        default:
            enabled: true
```

All keys are documented in [CONFIGURATION.md](CONFIGURATION.md).

## Publish assets

The bundle publishes its browser entry under `public/bundles/nowochatkit/` and registers the Symfony asset package **`nowo_chat_kit`** (REQ-ASSETS-004). Run once after install or upgrade:

```bash
php bin/console assets:install
```

Reference published files with the named package — never with hard-coded `/bundles/...` paths:

```twig
<script src="{{ asset('js/nowo-chat.js', 'nowo_chat_kit') }}" defer></script>
```

## FrankenPHP

The bundle is designed for FrankenPHP worker mode (no request state kept in services). See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) and the demo in [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
