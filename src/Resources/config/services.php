<?php

declare(strict_types=1);

use Nowo\ChatKitBundle\AutoReply\ChatAutoReplier;
use Nowo\ChatKitBundle\Channel\NullChannelProvider;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDigestSenderInterface;
use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Contract\ChatSupportTopicProviderInterface;
use Nowo\ChatKitBundle\Digest\ChatDigestDispatcher;
use Nowo\ChatKitBundle\Digest\ChatDigestScheduler;
use Nowo\ChatKitBundle\Digest\NullChatDigestSender;
use Nowo\ChatKitBundle\Doctrine\TablePrefixListener;
use Nowo\ChatKitBundle\LinkPreview\NullLinkPreviewProvider;
use Nowo\ChatKitBundle\Push\ChatPushDispatcher;
use Nowo\ChatKitBundle\Push\NullChatPushSender;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Realtime\MercureChatRealtime;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Support\ConfiguredSupportTopicProvider;
use Nowo\ChatKitBundle\Twig\ChatDockExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\Icons\IconRendererInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Service definitions for NowoChatKitBundle. The application aliases ChatDirectoryInterface and
 * ChatAccessPolicyInterface; every other contract has a default here that an application alias replaces.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->load('Nowo\\ChatKitBundle\\', '../../')
        ->exclude([
            '../../{DependencyInjection,Entity,Enum,Event,Model,Contract,Resources}',
            '../../NowoChatKitBundle.php',
            '../../Repository',
            '../../Push/DeliverChatPushMessage.php',
            '../../Digest/SendChatDigestMessage.php',
            '../../Http/ChatApiException.php',
            '../../Realtime/ChatTopics.php',
        ]);
    $services->load('Nowo\\ChatKitBundle\\Repository\\', '../../Repository/')
        ->tag('doctrine.repository_service');

    // REQ-ORM-002: bundle tables, indexes and unique constraints get `doctrine.table_prefix`.
    $services->get(TablePrefixListener::class)
        ->arg('$prefix', param('nowo_chat_kit.doctrine.table_prefix'));
    $services->alias(ChatChannelProviderInterface::class, NullChannelProvider::class);
    $services->alias(ChatPushSenderInterface::class, NullChatPushSender::class);
    $services->alias(ChatLinkPreviewProviderInterface::class, NullLinkPreviewProvider::class);
    $services->alias(ChatDigestSenderInterface::class, NullChatDigestSender::class);
    $services->alias(ChatSupportTopicProviderInterface::class, ConfiguredSupportTopicProvider::class);
    $services->set(NullChatRealtime::class);
    if (interface_exists(HubInterface::class)) {
        $services->alias(ChatRealtimeInterface::class, MercureChatRealtime::class);
    } else {
        $services->remove(MercureChatRealtime::class);
        $services->alias(ChatRealtimeInterface::class, NullChatRealtime::class);
    }

    $services->get(ChatPresence::class)
        ->arg('$cache', service('nowo_chat_kit.cache'))
        ->arg('$ttl', param('nowo_chat_kit.presence_ttl'));
    $services->get(ChatRecipients::class)
        ->arg('$cache', service('nowo_chat_kit.cache'));
    // #[AsMessageHandler] on the two dispatchers is read through autoconfiguration only when symfony/messenger is
    // installed: without it the attribute class does not exist (ChatNotifier then calls the push dispatcher inline).
    $messenger = interface_exists(MessageBusInterface::class);
    $services->get(ChatPushDispatcher::class)
        ->autoconfigure($messenger)
        ->arg('$urlTemplate', param('nowo_chat_kit.push_url'))
        ->arg('$icon', param('nowo_chat_kit.push_icon'))
        ->arg('$preview', param('nowo_chat_kit.push_preview'));
    $bus = $messenger ? service('messenger.default_bus')->nullOnInvalid() : null;
    $services->get(ChatNotifier::class)
        ->arg('$pushEnabled', param('nowo_chat_kit.push'))
        ->arg('$bus', $bus)
        ->arg('$digest', service(ChatDigestScheduler::class));
    $services->get(ChatDigestScheduler::class)
        ->arg('$cache', service('nowo_chat_kit.cache'))
        ->arg('$bus', $bus)
        ->arg('$enabled', param('nowo_chat_kit.digest.enabled'))
        ->arg('$delayMinutes', param('nowo_chat_kit.digest.delay_minutes'));
    $services->get(ChatDigestDispatcher::class)
        ->autoconfigure($messenger)
        ->arg('$cache', service('nowo_chat_kit.cache'))
        ->arg('$urlTemplate', param('nowo_chat_kit.push_url'))
        ->arg('$intervalMinutes', param('nowo_chat_kit.digest.interval_minutes'));
    $services->get(ChatAutoReplier::class)
        ->arg('$intervalHours', param('nowo_chat_kit.auto_reply_interval_hours'));
    $services->get(ChatService::class)
        ->arg('$reactions', param('nowo_chat_kit.reactions'))
        ->arg('$maxPins', param('nowo_chat_kit.max_pins'));
    $services->get(ChatDockExtension::class)
        ->arg('$reactions', param('nowo_chat_kit.reactions'))
        ->arg('$iconSet', param('nowo_chat_kit.icon_set'))
        ->arg('$icons', param('nowo_chat_kit.icons'))
        ->arg('$iconRenderer', service(IconRendererInterface::class)->nullOnInvalid())
        ->arg('$pageLayout', param('nowo_chat_kit.page_layout'))
        ->arg('$supportEmojis', param('nowo_chat_kit.support.emojis'))
        ->arg('$autoAwayMinutes', param('nowo_chat_kit.auto_away_minutes'));
    $services->get(ChatPurgeCommand::class)
        ->arg('$retentionDays', param('nowo_chat_kit.retention_days'));
};
