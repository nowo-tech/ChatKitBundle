<?php

declare(strict_types=1);

use Nowo\ChatKitBundle\Channel\NullChannelProvider;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Push\ChatPushDispatcher;
use Nowo\ChatKitBundle\Push\NullChatPushSender;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Realtime\MercureChatRealtime;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Twig\ChatDockExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\Icons\IconRendererInterface;

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
            '../../{DependencyInjection,Entity,Enum,Model,Contract,Resources}',
            '../../NowoChatKitBundle.php',
            '../../Repository',
            '../../Push/DeliverChatPushMessage.php',
            '../../Http/ChatApiException.php',
            '../../Realtime/ChatTopics.php',
        ]);
    $services->load('Nowo\\ChatKitBundle\\Repository\\', '../../Repository/')
        ->tag('doctrine.repository_service');

    $services->alias(ChatChannelProviderInterface::class, NullChannelProvider::class);
    $services->alias(ChatPushSenderInterface::class, NullChatPushSender::class);
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
    $services->get(ChatPushDispatcher::class)
        ->arg('$urlTemplate', param('nowo_chat_kit.push_url'))
        ->arg('$icon', param('nowo_chat_kit.push_icon'))
        ->tag('messenger.message_handler');
    $services->get(ChatNotifier::class)
        ->arg('$pushEnabled', param('nowo_chat_kit.push'))
        ->arg('$bus', interface_exists(MessageBusInterface::class) ? service('messenger.default_bus')->nullOnInvalid() : null);
    $services->get(ChatService::class)
        ->arg('$reactions', param('nowo_chat_kit.reactions'));
    $services->get(ChatDockExtension::class)
        ->arg('$reactions', param('nowo_chat_kit.reactions'))
        ->arg('$iconSet', param('nowo_chat_kit.icon_set'))
        ->arg('$icons', param('nowo_chat_kit.icons'))
        ->arg('$iconRenderer', service(IconRendererInterface::class)->nullOnInvalid());
    $services->get(ChatPurgeCommand::class)
        ->arg('$retentionDays', param('nowo_chat_kit.retention_days'));
};
