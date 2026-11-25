<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\AutoReply\ChatAutoReplier;
use Nowo\ChatKitBundle\Avatar\ChatAvatars;
use Nowo\ChatKitBundle\Avatar\NullChatAvatarUrlResolver;
use Nowo\ChatKitBundle\Badge\ChatAuthorBadges;
use Nowo\ChatKitBundle\Badge\NoChatAuthorBadges;
use Nowo\ChatKitBundle\Channel\NullChannelProvider;
use Nowo\ChatKitBundle\ClientApi\ChatClientApiController;
use Nowo\ChatKitBundle\ClientApi\ChatClientApiGuard;
use Nowo\ChatKitBundle\ClientApi\ChatClientApiRouteLoader;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Contract\ChatAccessInterface;
use Nowo\ChatKitBundle\Contract\ChatAuthorBadgeProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatAvatarUrlResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatDigestSenderInterface;
use Nowo\ChatKitBundle\Contract\ChatForwardPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatHistoryPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatMentionInboxInterface;
use Nowo\ChatKitBundle\Contract\ChatMessageActionsInterface;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;
use Nowo\ChatKitBundle\Contract\ChatQueryInterface;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Contract\ChatSupportInboxInterface;
use Nowo\ChatKitBundle\Contract\ChatSupportTopicProviderInterface;
use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\PrivateEntityManagerReferencesPass;
use Nowo\ChatKitBundle\Digest\ChatDigestDispatcher;
use Nowo\ChatKitBundle\Digest\ChatDigestScheduler;
use Nowo\ChatKitBundle\Digest\NullChatDigestSender;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\StringReferenceSchemaGuard;
use Nowo\ChatKitBundle\Doctrine\TablePrefixListener;
use Nowo\ChatKitBundle\Doctrine\UserReferenceListener;
use Nowo\ChatKitBundle\Forward\AllowAllChatForwardPolicy;
use Nowo\ChatKitBundle\History\NoChatHistoryReaders;
use Nowo\ChatKitBundle\Http\ChatApiExceptionListener;
use Nowo\ChatKitBundle\Http\ChatConflictResponseFormatter;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Link\ChatLinkUrls;
use Nowo\ChatKitBundle\LinkPreview\NullLinkPreviewProvider;
use Nowo\ChatKitBundle\Port\ChatChannelPort;
use Nowo\ChatKitBundle\Port\ChatCurrentUserPort;
use Nowo\ChatKitBundle\Port\ChatRealtimePort;
use Nowo\ChatKitBundle\Push\ChatPushDispatcher;
use Nowo\ChatKitBundle\Push\NullChatPushSender;
use Nowo\ChatKitBundle\Query\ChatQuery;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Realtime\MercureChatRealtime;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Search\ChatMessageSearch;
use Nowo\ChatKitBundle\Search\ChatMessageSearchSql;
use Nowo\ChatKitBundle\Search\ChatSearchIndex;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatUuidAccess;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Nowo\ChatKitBundle\Service\ChatMentionInbox;
use Nowo\ChatKitBundle\Service\ChatMentions;
use Nowo\ChatKitBundle\Service\ChatMenuOptions;
use Nowo\ChatKitBundle\Service\ChatMessageActions;
use Nowo\ChatKitBundle\Service\ChatMessageMenu;
use Nowo\ChatKitBundle\Service\ChatMessaging;
use Nowo\ChatKitBundle\Service\ChatReadTimes;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatSupportDesk;
use Nowo\ChatKitBundle\Sidebar\ChatSidebar;
use Nowo\ChatKitBundle\Support\ConfiguredSupportTopicProvider;
use Nowo\ChatKitBundle\Support\NullRetentionPolicyProvider;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Twig\ChatDockExtension;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\Icons\IconRendererInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\iterator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

/*
 * Service definitions for NowoChatKitBundle. The application aliases ChatDirectoryInterface and
 * ChatAccessPolicyInterface; every other contract has a default here that an application alias replaces.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private()
            // 1.30 (Beacon spec 239, audits QD-M1 / QD-M3): every ChatKit write runs on ChatKit's own runner, never on
            // a runner the application binds to TransactionRunnerInterface (that one drives the application's manager).
            ->bind(TransactionRunnerInterface::class, service(ChatKitTransactionRunner::SERVICE_ID));

    $services->load('Nowo\\ChatKitBundle\\', '../../')
        ->exclude([
            '../../{Controller,DependencyInjection,Entity,Enum,Event,Model,Contract,Resources}',
            '../../NowoChatKitBundle.php',
            '../../Repository',
            '../../Push/DeliverChatPushMessage.php',
            '../../Digest/SendChatDigestMessage.php',
            '../../Http/ChatApiException.php',
            // 1.34: registered below only when nowo-tech/transaction-doctrine's Symfony conflict API exists.
            '../../Http/ChatConflictResponseFormatter.php',
            '../../Service/ChatChannelDeniedException.php',
            '../../Realtime/ChatTopics.php',
            // 1.27: the port gates and value copies are built by Ports (ChatKitPortsPass), never autowired.
            '../../Port',
            '../../Service/ChatNotFoundException.php',
            '../../Service/{ChatIdempotencyConflict,ChatDraft,ChatForwardRefused}.php',
            '../../Doctrine/ChatUserLookAlikeException.php',
            '../../Support/InvalidRetentionConfigurationException.php',
            // 1.32: registered by the extension only with `runtime.retention_days` (needs the optional contract).
            '../../Support/RetentionUnavailableException.php',
            '../../RuntimeSetting',
            '../../Avatar/AvatarUrlPolicy.php',
            '../../Doctrine/UnresolvedChatUser.php',
            // 1.30: registered below under the bundle's own id; ChatKitWrites is a static helper.
            '../../Transaction',
            // 1.31: built per purge run by the command (a value object over the configured days), never autowired.
            '../../Support/ChatRetention.php',
            // 1.30: a static helper of the row locks, never a service.
            '../../Doctrine/ChatKitRowLock.php',
            // 1.37: search value objects and pure helpers; the schema listener and the SearchKit provider are registered by
            // the extension only when their options are on (the provider also needs nowo-tech/search-kit-bundle).
            '../../Search/{ChatSearchText,ChatMessageSearchQuery,ChatMessageSearchHit,ChatMessageSearchPage,ChatSearchScope,ChatSearchQueryException,ChatSearchSchemaListener}.php',
            '../../SearchKit',
            '../../Doctrine/{ChatKitEntityManager,ChatKitManagerRegistry,ChatKitSavepoint,PrivateEntityManagerConnection,PrivateEntityManagerDriver,PrivateEntityManagerMiddleware,PrivateManagerRepositoryFactory}.php',
            // 2.4: the client API's controller and route loader are registered below; its call and cursor are values.
            '../../ClientApi/{ChatClientApiController,ChatClientApiRouteLoader,ChatClientCall,ChatClientCursor}.php',
        ]);
    // REQ-SF-004 rule 7: controllers are NOT autoconfigured. On Symfony >= 7.3 autoconfiguration tags every #[Route]
    // class with routing.controller, and the host's default `routing.controllers` import would then register the chat
    // routes without the documented routes.php import (no prefix). Tags are explicit instead.
    $services->load('Nowo\\ChatKitBundle\\Controller\\', '../../Controller/')
        ->autoconfigure(false)
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber');
    // 2.4: the client API v1. Same rule as the controllers above (no autoconfiguration: its routes come only through the
    // `nowo_chat_kit_client_api` import, under client_api.prefix, and only while a principal resolver is configured).
    $services->set(ChatClientApiController::class)
        ->autoconfigure(false)
        ->tag('controller.service_arguments')
        ->arg('$realtimeEvents', param('nowo_chat_kit.realtime_events'));
    $services->set(ChatClientApiRouteLoader::class)
        ->autoconfigure(false)
        ->args([param('nowo_chat_kit.client_api.enabled'), param('nowo_chat_kit.client_api.prefix')])
        ->tag('routing.loader');
    // The resolver, the context policy and the token revoker are set by the extension (client_api.principal_resolver /
    // context_policy / token_revoker).
    $services->get(ChatClientApiGuard::class)
        ->arg('$ipLimiter', service('limiter.nowo_chat_client_ip'))
        ->arg('$tokenLimiter', service('limiter.nowo_chat_client_token'))
        ->arg('$readLimiter', service('limiter.nowo_chat_read'))
        ->arg('$actionLimiter', service('limiter.nowo_chat_action'))
        ->arg('$contextHeader', param('nowo_chat_kit.client_api.context_header'))
        ->arg('$events', service('event_dispatcher')->nullOnInvalid());
    $services->load('Nowo\\ChatKitBundle\\Repository\\', '../../Repository/')
        ->tag('doctrine.repository_service');

    // REQ-ORM-002: bundle tables, indexes and unique constraints follow `doctrine.table_name_prefix` and
    // `doctrine.table_prefix`.
    $services->get(TablePrefixListener::class)
        ->arg('$prefix', param('nowo_chat_kit.doctrine.table_prefix'))
        ->arg('$tableNamePrefix', param('nowo_chat_kit.doctrine.table_name_prefix'));
    // 1.24: `doctrine.user_reference` (the extension removes the listener in the default `relation` mode).
    // 1.28 (audit PV-L3): the look-alike warning and refusal carry a keyed hash of the reference, never the reference.
    $services->get(StringReferenceSchemaGuard::class)
        ->arg('$secret', param('kernel.secret'));
    $services->get(ChatUserReferences::class)
        ->arg('$mode', param('nowo_chat_kit.doctrine.user_reference'))
        // 1.27 (audit OJ-M1): `string` mode only (the extension removes the guard otherwise).
        ->arg('$guard', service(StringReferenceSchemaGuard::class)->nullOnInvalid());
    $services->get(UserReferenceListener::class)
        ->arg('$access', service_closure(ChatAccess::class))
        ->arg('$secret', param('kernel.secret'));
    // 1.26.3 (REQ-RUNTIME-002): the memos are cleared at every request / command / message boundary, not only by
    // `kernel.reset` (FrankenPHP worker with FRANKENPHP_RESET_KERNEL=false). The id-mode listener may be removed.
    // Lazy: only memos already built in this process are reset (IGNORE_ON_UNINITIALIZED_REFERENCE), so a request that
    // never touched the chat builds none of them.
    $services->get(ChatRequestBoundaryListener::class)
        ->arg('$memos', iterator([
            service(ChatReferences::class)->ignoreOnUninitialized(),
            service(ChatAvatars::class)->ignoreOnUninitialized(),
            // 1.29: the author badges of the request.
            service(ChatAuthorBadges::class)->ignoreOnUninitialized(),
            service(ChatUserSettingsRepository::class)->ignoreOnUninitialized(),
            service(UserReferenceListener::class)->ignoreOnUninitialized(),
            // 1.27.1 (audit AOY-M2): the schema pass of the `string` mode guard. One check per request instead of one
            // per query by user, and a database switched between two requests is checked again. May be removed.
            service(StringReferenceSchemaGuard::class)->ignoreOnUninitialized(),
            // 1.30: a shared manager closed by a failed chat write is replaced at the boundary (never mid-request).
            service(ChatKitTransactionRunner::SERVICE_ID)->ignoreOnUninitialized(),
        ]));
    // 1.34: the shared lock-conflict policy (409 by default); absent when the host did not register nowo-tech/transaction-doctrine's bundle (the listener then uses 409 + 1 s).
    $services->set(ChatApiExceptionListener::class)
        ->arg('$policy', service('nowo_transaction_doctrine.conflict_response_policy')->nullOnInvalid());
    if (interface_exists(ConflictResponseFormatterInterface::class)) {
        $services->set(ChatConflictResponseFormatter::class)->tag(ConflictResponseFormatterInterface::TAG);
    }
    // 1.30: ChatKit's runner. 2.0: always on ChatKit's private manager (PrivateEntityManagerReferencesPass moves the
    // manager and the registry onto it; the registry resolves the source manager's name to it).
    $services->set(ChatKitTransactionRunner::SERVICE_ID, ChatKitTransactionRunner::class)
        ->args([service(EntityManagerInterface::class), service('doctrine'), param(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER), true, 1, service('logger')->nullOnInvalid()]);
    $services->alias(ChatChannelPort::class, NullChannelProvider::class);
    // 1.24: the application service as a port (decorate or replace the interface).
    $services->alias(ChatServiceInterface::class, ChatService::class);
    // 1.27: the same service by public uuid, answering with views (REQ-ARCH-001 rule 1; DDD hosts).
    $services->alias(ChatMessagingInterface::class, ChatMessaging::class);
    // 2.2 (DDD-host checklist B1/B7/B8): host query ports — read models, access by uuid and the support inbox by id.
    $services->alias(ChatQueryInterface::class, ChatQuery::class);
    $services->alias(ChatAccessInterface::class, ChatUuidAccess::class);
    $services->alias(ChatSupportInboxInterface::class, ChatSupportDesk::class);
    $services->get(ChatUuidAccess::class)
        ->arg('$linkChannelMembers', param('nowo_chat_kit.links.channel_members'))
        ->arg('$logger', service('logger')->nullOnInvalid());
    // 2.0: the host's Mentions page (list, count, mark read) through ChatKit's own manager.
    $services->alias(ChatMentionInboxInterface::class, ChatMentionInbox::class);
    // 1.35: the message menu by public uuid (DDD hosts).
    $services->alias(ChatMessageActionsInterface::class, ChatMessageMenu::class);
    // 1.24: who the signed-in user is in the chat (default: the security user when it implements ChatUserInterface).
    $services->alias(ChatCurrentUserPort::class, SecurityChatUserResolver::class);
    $services->alias(ChatPushSenderInterface::class, NullChatPushSender::class);
    $services->alias(ChatLinkPreviewProviderInterface::class, NullLinkPreviewProvider::class);
    $services->alias(ChatDigestSenderInterface::class, NullChatDigestSender::class);
    // 1.31: retention days read at run time; the application aliases the interface to its own provider.
    $services->alias(RetentionPolicyProviderInterface::class, NullRetentionPolicyProvider::class);
    $services->alias(ChatSupportTopicProviderInterface::class, ConfiguredSupportTopicProvider::class);
    // 1.16: profile pictures; the application aliases the interface to its own resolver.
    $services->alias(ChatAvatarUrlResolverInterface::class, NullChatAvatarUrlResolver::class);
    // 1.29 (Beacon spec 234): author badges; the application aliases the interface to its own provider.
    $services->alias(ChatAuthorBadgeProviderInterface::class, NoChatAuthorBadges::class);
    // 1.36: the forward veto (allows what ChatKit's checks allow) and who else reads edit history (nobody).
    $services->alias(ChatForwardPolicyInterface::class, AllowAllChatForwardPolicy::class);
    $services->alias(ChatHistoryPolicyInterface::class, NoChatHistoryReaders::class);
    $services->set(NullChatRealtime::class);
    if (interface_exists(HubInterface::class)) {
        $services->alias(ChatRealtimePort::class, MercureChatRealtime::class);
    } else {
        $services->remove(MercureChatRealtime::class);
        $services->alias(ChatRealtimePort::class, NullChatRealtime::class);
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
        // 2.4 (Q-BD-13): `ids` keeps message bodies off the hub.
        ->arg('$realtimeEvents', param('nowo_chat_kit.realtime_events'))
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
    // 1.24: @channel / @here budget per writer (no limiter without framework.rate_limiter: no budget).
    $services->get(ChatMentions::class)
        ->arg('$mentionAllLimiter', service('limiter.nowo_chat_mention_all')->nullOnInvalid());
    // 1.35: read times of message info, and the message menu's actions (save, unread, info).
    $services->get(ChatReadTimes::class)
        ->arg('$messageInfo', param('nowo_chat_kit.message_menu.message_info'));
    // 1.36: forward, threads and edit history (flags and limits).
    $services->get(ChatMenuOptions::class)
        ->arg('$forward', param('nowo_chat_kit.message_menu.forward'))
        ->arg('$threads', param('nowo_chat_kit.message_menu.threads'))
        ->arg('$editHistory', param('nowo_chat_kit.message_menu.edit_history'));
    $services->get(ChatMessageActions::class)
        ->arg('$save', param('nowo_chat_kit.message_menu.save'))
        ->arg('$markUnread', param('nowo_chat_kit.message_menu.mark_unread'));
    $services->get(ChatService::class)
        ->arg('$reactions', param('nowo_chat_kit.accepted_reactions'))
        ->arg('$maxPins', param('nowo_chat_kit.max_pins'));
    // 1.19: links (pinned links, link cards, the Links panel).
    $services->get(ChatLinkUrls::class)
        ->arg('$providers', param('nowo_chat_kit.links.providers'))
        ->arg('$allowHttp', param('nowo_chat_kit.links.allow_http'));
    $services->get(ChatLinks::class)
        ->arg('$maxPinned', param('nowo_chat_kit.links.max_pinned'))
        ->arg('$enabled', param('nowo_chat_kit.links.enabled'))
        ->arg('$channelMembers', param('nowo_chat_kit.links.channel_members'));
    $services->get(ChatDockExtension::class)
        ->arg('$reactions', param('nowo_chat_kit.reactions'))
        ->arg('$iconSet', param('nowo_chat_kit.icon_set'))
        ->arg('$icons', param('nowo_chat_kit.icons'))
        ->arg('$iconRenderer', service(IconRendererInterface::class)->nullOnInvalid())
        ->arg('$supportEmojis', param('nowo_chat_kit.support.emojis'))
        ->arg('$autoAwayMinutes', param('nowo_chat_kit.auto_away_minutes'))
        ->arg('$statusPresets', param('nowo_chat_kit.status_presets'))
        ->arg('$dock', param('nowo_chat_kit.dock'))
        ->arg('$channelsUrl', param('nowo_chat_kit.web_ui.channels_url'))
        ->arg('$openRecent', param('nowo_chat_kit.web_ui.page.open_recent'))
        ->arg('$dockSidebar', param('nowo_chat_kit.web_ui.dock.sidebar_sections'))
        ->arg('$dialogRuntime', param('nowo_chat_kit.web_ui.dock.dialog_runtime'))
        ->arg('$packages', service('assets.packages')->nullOnInvalid())
        ->arg('$messageContextMenu', param('nowo_chat_kit.web_ui.message_context_menu'))
        ->arg('$messageMenu', param('nowo_chat_kit.message_menu'))
        ->arg('$searchDockEntry', param('nowo_chat_kit.search.dock_link'))
        ->arg('$searchConversationEntry', param('nowo_chat_kit.search.conversation_link'));
    // 1.25: the host sections of the sidebar (UX icon names rendered with the dock's icon set).
    $services->get(ChatSidebar::class)
        ->arg('$iconRenderer', service(IconRendererInterface::class)->nullOnInvalid())
        ->arg('$iconSet', param('nowo_chat_kit.icon_set'));
    // 1.37: message search (`search`); the retention window is the purge's.
    $services->get(ChatMessageSearchSql::class)
        ->arg('$fulltext', param('nowo_chat_kit.search.fulltext'))
        ->arg('$postgresConfig', param('nowo_chat_kit.search.postgresql_config'))
        ->arg('$mysqlMinTokenLength', param('nowo_chat_kit.search.mysql_min_token_length'));
    $services->get(ChatSearchIndex::class)
        ->arg('$postgresConfig', param('nowo_chat_kit.search.postgresql_config'))
        ->arg('$tablePrefix', param('nowo_chat_kit.doctrine.table_prefix'))
        ->arg('$tableNamePrefix', param('nowo_chat_kit.doctrine.table_name_prefix'));
    $services->get(ChatMessageSearch::class)
        ->arg('$enabled', param('nowo_chat_kit.search.enabled'))
        ->arg('$minQueryLength', param('nowo_chat_kit.search.min_query_length'))
        ->arg('$maxQueryLength', param('nowo_chat_kit.search.max_query_length'))
        ->arg('$pageSize', param('nowo_chat_kit.search.page_size'))
        ->arg('$retentionDays', param('nowo_chat_kit.retention_days'))
        ->arg('$retention', service(RetentionPolicyProviderInterface::class))
        ->arg('$logger', service('logger')->nullOnInvalid());
    $services->get(ChatPurgeCommand::class)
        ->arg('$retentionDays', param('nowo_chat_kit.retention_days'))
        ->arg('$retention', service(RetentionPolicyProviderInterface::class));
};
