# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Register the bundle (without Flex)](#register-the-bundle-without-flex)
- [Configuration file](#configuration-file)
- [Your user entity](#your-user-entity)
- [Routes](#routes)
- [Database](#database)
- [Contracts to implement](#contracts-to-implement)
- [Render the dock](#render-the-dock)
- [Publish assets](#publish-assets)
- [Icons](#icons)
- [Realtime (Mercure)](#realtime-mercure)
- [Retention purge](#retention-purge)
- [FrankenPHP](#frankenphp)

## Requirements

- PHP `>=8.3 <8.6`
- Symfony 7.4, 8.0 or 8.1+: FrameworkBundle, SecurityBundle (CSRF), TwigBundle, Doctrine ORM, Symfony UX Icons
- Twig Extra (`twig/extra-bundle`, `twig/string-extra`) — installed as dependencies; register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` when Flex does not.
- Optional: `symfony/mercure-bundle` (realtime), Symfony Messenger (asynchronous Web Push), a Web Push library behind
  `ChatPushSenderInterface`

## Install with Composer

```bash
composer require nowo-tech/chat-kit-bundle
```

With **Symfony Flex**, the recipe registers the bundle and copies `config/packages/nowo_chat_kit.yaml` (with `user_class: App\Entity\User` — adjust it) and `config/routes/nowo_chat_kit.yaml`.

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
    profiles:
        default:
            user_class: App\Entity\User
```

`user_class` is required (it becomes the target of the chat tables' user foreign keys). Every other key is optional —
see [CONFIGURATION.md](CONFIGURATION.md).

## Your user entity

The entity implements `Nowo\ChatKitBundle\Model\ChatUserInterface`:

```php
use Nowo\ChatKitBundle\Model\ChatUserInterface;

class User implements UserInterface, ChatUserInterface
{
    public function getChatPublicId(): string { return $this->uuid; }     // never the numeric id
    public function getChatDisplayName(): string { return $this->name; }
    public function getChatInitials(): string { return mb_substr($this->name, 0, 1); }
    public function getChatEmail(): ?string { return $this->email; }       // null hides it
    public function getChatLocale(): ?string { return $this->locale; }     // Web Push texts
}
```

The public id appears in URLs, the JSON API and realtime topics: use a UUID (or another unguessable id).

## Routes

```yaml
# config/routes/nowo_chat_kit.yaml
nowo_chat_kit:
    resource: '@NowoChatKitBundle/Resources/config/routes.php'
    prefix: /chat
```

The JSON API answers under `/chat/api/…` (route names `nowo_chat_api_*`). Every endpoint checks the signed-in user
itself (401 for guests); you can also cover `^/chat` in `access_control`.

## Database

The bundle maps its entities (`nowo_chat_conversation`, `nowo_chat_participant`, `nowo_chat_message`,
`nowo_chat_reaction`, `nowo_chat_user_settings`). Generate and run a migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Contracts to implement

| Interface | Required | Default | Role |
| --- | --- | --- | --- |
| `ChatDirectoryInterface` | yes | — | Find users by public id / ids, the people picker contacts, the support agents |
| `ChatAccessPolicyInterface` | yes | — | Reachable accounts, support agents, who may message whom |
| `ChatChannelProviderInterface` | no | no channels | Group conversations of your application (e.g. one per project) |
| `ChatRealtimeInterface` | no | Mercure when a hub is configured, else polling only | Realtime transport |
| `ChatPushSenderInterface` | no | no push | Web Push delivery |
| `ChatLinkPreviewProviderInterface` | no | no cards | Previews of links to your application, checked per viewer |
| `ChatDigestSenderInterface` | no | no email | Email of the conversations still unread |

Alias them in `config/services.yaml`:

```yaml
services:
    Nowo\ChatKitBundle\Contract\ChatDirectoryInterface: '@App\Chat\MyDirectory'
    Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface: '@App\Chat\MyPolicy'
```

See [USAGE.md](USAGE.md#contracts) for what each method must answer.

## Render the dock

Once, in the layout of signed-in pages:

```twig
{{ include('@NowoChatKitBundle/dock.html.twig') }}
```

Guests and unreachable accounts get nothing. The template loads `css/nowo-chat.css` and `js/nowo-chat.js` from the
`nowo_chat_kit` asset package; the script mounts the dock after first paint (and again after Turbo visits).

## Publish assets

```bash
php bin/console assets:install
```

## Icons

The dock draws its icons with **Symfony UX Icons** (`icon_set: ux_icon`, Tabler names by default). Import them so they
are served locally (no Iconify request at runtime):

```bash
php bin/console ux:icons:import tabler:message-circle tabler:edit tabler:chevron-up tabler:chevron-down tabler:x \
    tabler:send tabler:arrow-left tabler:arrow-down tabler:search tabler:hash tabler:lifebuoy tabler:refresh \
    tabler:bolt tabler:mood-smile tabler:check tabler:pencil tabler:trash tabler:link tabler:external-link
```

An icon UX Icons cannot find falls back to the built-in SVG; `icon_set: svg_inline` uses only the built-in set.

## Realtime (Mercure)

With `symfony/mercure-bundle` configured (FrankenPHP's built-in hub works), messages, typing, presence and read
receipts arrive live; without it, the dock polls (every `pollMs`, slower when the tab is hidden). Topics are private
(`/nowo-chat/users/<public id>`); the dock receives a 15-minute subscriber token for its own topic only.

## Retention purge

Schedule the purge daily (Scheduler, cron…):

```bash
php bin/console nowo:chat:purge --no-interaction
```

It deletes messages older than `retention_days` (minimum 30) in bounded batches.

## FrankenPHP

The bundle is designed for FrankenPHP worker mode: per-request memoization is reset between requests
(`ResetInterface`). See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) and the demo in
[DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
