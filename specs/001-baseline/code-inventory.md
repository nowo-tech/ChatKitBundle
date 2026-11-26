# Chat Kit Bundle — baseline code inventory

**Package**: `nowo-tech/chat-kit-bundle`  
**Last audited**: 2026-11-26 (2.6.0: 342 production files, `git ls-files src` minus the Vitest suite, `assets/README.md` and the build output `nowo-chat.js`; the 5 rows of files removed in 2.0.0 are gone)  
**Production units**: **330/330** table rows below (2.6.0: +17 typed card and host slot units, see the last section; `ChatReferences`, `ChatReference`, `ChatService`, `ChatForwarder`, `ChatPresenter`, `ChatIdempotency`, `ChatMessageMenu`, `ChatPushDispatcher`, `ChatDigestDispatcher`, `ChatMessageSearch`, `ChatMessageTokens`, `ChatApiController`, `ChatClientApiController`, `ChatDockExtension`, `Configuration`, `NowoChatKitExtension`, the templates, `thread.ts`, `api.ts`, `index.ts`, `types.ts`, `dom.ts`, `message-menu.ts`, `nowo-chat.css` and the translations extended, `specs/005-secret-cards-slots/`; 2.5.0: +1 client API writes unit, `ChatClientApiWriteEvent`; `ChatClientApiController`, `ChatClientApiGuard`, `ChatService`, `ChatClientPrincipal`, `Configuration`, `NowoChatKitExtension` and `services.php` extended, `specs/004-client-api-writes/`; 2.4.1: +2 logout units, `ChatClientTokenRevokerInterface` and `ChatClientLoggedOutEvent`; 2.4: +13 client API units; `ChatNotifier`, `ChatReferences`, `ChatConversationRepository`, `ChatApiException`, `Configuration`, `NowoChatKitExtension`, `services.php`, `dock.ts` and `types.ts` extended); **all 342 files** the audit command returns (2026-11-26) are named in this file (some rows group several files or repeat one file under two sections)  
**Audit command**: `find src -type f \( -name '*.php' -o -name '*.twig' -o -name '*.yaml' -o -name '*.ts' -o -name '*.css' \) -not -path 'src/Resources/assets/tests/*' | wc -l`

## Scope notes

- Vitest files under `src/Resources/assets/tests/` are **excluded** (test code, not shipped behaviour).
- `src/Resources/public/js/nowo-chat.js` is the Vite **build output** of `index.ts` / `iife.ts` (`FR-BUILD-001`), not a separate unit.
- Covers the 1.2 "hide the dock to the side" feature (`FR-TS-007`, `FR-TWIG-003`, `FR-THEME-003`): no new file, existing units extended.
- Covers 1.3: dock Form types (`FR-TWIG-004`), page mark-read script `page.ts` (`FR-TS-008`), page layout global and asset blocks (`FR-TWIG-002`).
- Covers 1.33 (message menu and context menu, `FR-MENU-001`): one new unit `message-menu.ts`; `ChatApiController`, `ChatMarkdownRenderer`, `ChatPresenter`, `ChatDockExtension`, `Configuration`, `_templates.html.twig`, `thread.ts`, `dock.ts`, `api.ts`, `types.ts`, `nowo-chat.css` and the translations extended.
- Covers 1.35 (richer message menu, `FR-MENU-002`): new units `ChatEmojiCatalog`, `ChatSavedMessage`, `ChatReadMark`, their repositories, `ChatMessageActions`, `ChatReadTimes`, `ChatMessageMenu` / `ChatMessageActionsInterface`, three events, `saved.html.twig`, `message-info.ts`, `saved-page.ts`; `ChatParticipant`, `ChatUserSettings`, `ChatService`, `ChatVoter`, the controllers, `ChatPersonalData`, `ChatPurgeCommand`, `ChatApiExceptionListener`, `ChatConflictResponseFormatter`, the templates, `thread.ts`, `message-menu.ts` and the translations extended.
- Covers 1.36 (forward, threads, edit history, `FR-MENU-003`): new units `ChatMessageRevision`, `ChatThreadRead`, `ChatIdempotencyKey` and their repositories, `ChatMenuOptions`, `ChatDraft`, `ChatIdempotency`, `ChatThreads`, `ChatForwarder`, `ChatEditHistory`, `ChatForwardPolicyInterface` / `AllowAllChatForwardPolicy` / `ChatForwardRequest`, `ChatHistoryPolicyInterface` / `NoChatHistoryReaders` / `ChatHistoryRequest`, four events, `forward-dialog.ts`, `thread-panel.ts`; `ChatMessage`, `ChatService` (`post()`), `ChatMessageRepository`, `ChatVoter`, `ChatPresenter`, `ChatNotifier`, `ChatApiController`, `ChatMessageMenu`, `ChatPersonalData`, `ChatPurgeCommand`, the templates, `thread.ts`, `dock.ts`, `message-info.ts` and the translations extended.
- Covers 1.37 (message search, `specs/002-message-search/`): new units under `src/Search/` and `src/SearchKit/`, `ChatSearchController`, `ChatSearchIndexCommand`, `ChatMessageSearchType`, `search.html.twig`; `Configuration`, `NowoChatKitExtension`, `services.php`, `ChatDockExtension`, `ChatPage`, `_templates.html.twig`, `nowo-chat.css` and the translations extended.
- `src/Resources/assets/README.md` is documentation; `demo/` and `.symfony/` (Flex recipe, see `docs/INSTALLATION.md`) are out of inventory scope.

## Coverage summary

| Category | Mapped |
| --- | ---: |
| Bundle, configuration & DI | 8 |
| Domain model & persistence | 17 |
| Contracts & value objects | 11 |
| Access control | 2 |
| Messaging | 13 |
| HTTP | 6 |
| Realtime, presence & notifications | 15 |
| Privacy & retention | 2 |
| Twig, markup & theme | 9 |
| Browser (TypeScript) | 17 |
| Translations | 7 |
| Automatic replies, avatars & author badges | 11 |
| Links (pinned links and links panel) | 14 |
| Mentions, references & quick replies | 13 |
| Channels, sidebar & preferences | 14 |
| Support tickets | 12 |
| Application ports (framework-free twins, messaging by id) | 20 |
| User reference modes (relation, id, string) | 10 |
| Private entity manager, savepoints & write concurrency | 14 |
| Retention policy | 8 |
| Browser (TypeScript) — feature modules | 15 |
| Message search (1.37) | 17 |
| Sources added 1.34–2.2 (sweep 2026-11-22) | 54 |
| Client API v1 (2.4, 2.4.1, 2.5) | 16 |
| Typed cards and host slots (2.6) | 17 |
| **Total** | **342** |

## Bundle, configuration & DI

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/DependencyInjection/Compiler/RealtimeTransportPass.php` | US-05 | FR-DI-002 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | US-01, US-08 | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | US-01, US-08 | FR-CFG-001 |
| `src/DependencyInjection/NowoChatKitExtension.php` | US-01 | FR-CFG-002, FR-DI-001 |
| `src/NowoChatKitBundle.php` | US-01 | FR-BUNDLE-001 |
| `src/Resources/config/routes.php` | US-01 | FR-ROUTE-001 |
| `src/Doctrine/TablePrefixListener.php` | US-01 | FR-ORM-001 |
| `src/Resources/config/services.php` | US-01 | FR-DI-001 |

## Domain model & persistence

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Entity/ChatConversation.php` | US-02, US-03, US-04 | FR-MODEL-001 |
| `src/Entity/ChatMessage.php` | US-02 | FR-MODEL-001 |
| `src/Entity/ChatParticipant.php` | US-02, US-05 | FR-MODEL-002 |
| `src/Entity/ChatReaction.php` | US-02 | FR-MODEL-002 |
| `src/Entity/ChatSavedMessage.php` | US-02 | FR-MENU-002 |
| `src/Entity/ChatReadMark.php` | US-02 | FR-MENU-002 |
| `src/Entity/ChatUserSettings.php` | US-05, US-06 | FR-MODEL-002 |
| `src/Entity/PublicIdTrait.php` | US-02 | FR-MODEL-001 |
| `src/Enum/ConversationKind.php` | US-02, US-03, US-04 | FR-MODEL-001 |
| `src/Enum/SupportStatus.php` | US-04 | FR-MODEL-001 |
| `src/Repository/ChatConversationRepository.php` | US-02, US-04 | FR-MODEL-003 |
| `src/Repository/ChatMessageRepository.php` | US-02, US-09 | FR-MODEL-003 |
| `src/Repository/ChatParticipantRepository.php` | US-02, US-05 | FR-MODEL-003 |
| `src/Repository/ChatReactionRepository.php` | US-02 | FR-MODEL-003 |
| `src/Repository/ChatSavedMessageRepository.php` | US-02 | FR-MENU-002 |
| `src/Repository/ChatReadMarkRepository.php` | US-02 | FR-MENU-002 |
| `src/Repository/ChatUserSettingsRepository.php` | US-05, US-06 | FR-MODEL-003 |

## Contracts & value objects

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Channel/NullChannelProvider.php` | US-03 | FR-DI-001, FR-CONTRACT-003 |
| `src/Contract/ChatAccessPolicyInterface.php` | US-01, US-02, US-04 | FR-CONTRACT-002 |
| `src/Contract/ChatDigestSenderInterface.php` | US-06 | FR-CONTRACT-004 |
| `src/Contract/ChatDirectoryInterface.php` | US-01, US-02 | FR-CONTRACT-001 |
| `src/Contract/ChatLinkPreviewProviderInterface.php` | US-10 | FR-CONTRACT-004 |
| `src/Contract/ChatPushSenderInterface.php` | US-06 | FR-CONTRACT-004 |
| `src/Model/ChatChannel.php` | US-03 | FR-MODEL-004 |
| `src/Model/ChatContact.php` | US-02 | FR-MODEL-004 |
| `src/Model/ChatDigestItem.php` | US-06 | FR-MODEL-004 |
| `src/Model/ChatLinkPreview.php` | US-10 | FR-MODEL-004 |
| `src/Model/ChatUserInterface.php` | US-01 | FR-MODEL-004 |

## Access control

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Security/ChatAccess.php` | US-02, US-03, US-04 | FR-AUTHZ-001 |
| `src/Security/ChatVoter.php` | US-02, US-03, US-04 | FR-AUTHZ-002 |

## Messaging

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Event/ChatMessageDeletedEvent.php` | US-02, US-04 | FR-EVT-001 |
| `src/Event/ChatMessageSavedEvent.php` | US-02 | FR-MENU-002, FR-EVT-001 |
| `src/Event/ChatMessageUnsavedEvent.php` | US-02 | FR-MENU-002, FR-EVT-001 |
| `src/Event/ChatConversationMarkedUnreadEvent.php` | US-02 | FR-MENU-002, FR-EVT-001 |
| `src/Event/ChatMessageSentEvent.php` | US-02 | FR-EVT-001 |
| `src/Event/ChatSupportStatusChangedEvent.php` | US-04 | FR-EVT-001 |
| `src/Service/ChatMarkdownRenderer.php` | US-02 | FR-MSG-002, FR-MENU-001 |
| `src/Service/ChatPresenter.php` | US-02, US-07 | FR-MSG-003 |
| `src/Service/ChatService.php` | US-02, US-03, US-04 | FR-MSG-001, FR-CAPW-004 |
| `src/Service/ChatMessageActions.php` | US-02 | FR-MENU-002 |
| `src/Service/ChatReadTimes.php` | US-02 | FR-MENU-002 |
| `src/Service/ChatMessageMenu.php` | US-02 | FR-MENU-002, FR-PORT-003 |
| `src/Service/ChatSupportInbox.php` | US-04 | FR-SUPPORT-001 |

## HTTP

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Controller/ChatApiController.php` | US-02 … US-05, US-10 | FR-API-001, FR-MENU-001 |
| `src/Controller/ChatPageController.php` | US-07 | FR-PAGE-001 |
| `src/Form/ChatPageMessageType.php` | US-07 | FR-PAGE-001 |
| `src/Http/ChatApiException.php` | US-02 | FR-API-002 |
| `src/Http/ChatApiExceptionListener.php` | US-02 | FR-API-002 |
| `src/View/ChatPage.php` | US-07 | FR-PAGE-001, FR-TWIG-002 |

## Realtime, presence & notifications

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Digest/ChatDigestDispatcher.php` | US-06 | FR-DIGEST-001 |
| `src/Digest/ChatDigestScheduler.php` | US-06 | FR-DIGEST-001 |
| `src/Digest/NullChatDigestSender.php` | US-06 | FR-DIGEST-001 |
| `src/Digest/SendChatDigestMessage.php` | US-06 | FR-DIGEST-001 |
| `src/LinkPreview/NullLinkPreviewProvider.php` | US-10 | FR-PREVIEW-001 |
| `src/Push/ChatPushDispatcher.php` | US-06 | FR-PUSH-001 |
| `src/Push/DeliverChatPushMessage.php` | US-06 | FR-PUSH-001 |
| `src/Push/NullChatPushSender.php` | US-06 | FR-PUSH-001 |
| `src/Realtime/ChatNotifier.php` | US-05 | FR-RT-001 |
| `src/Realtime/ChatPresence.php` | US-05 | FR-RT-003 |
| `src/Realtime/ChatPublisher.php` | US-05 | FR-RT-001 |
| `src/Realtime/ChatRecipients.php` | US-05 | FR-RT-001 |
| `src/Realtime/ChatTopics.php` | US-05 | FR-RT-001 |
| `src/Realtime/MercureChatRealtime.php` | US-05 | FR-RT-002 |
| `src/Realtime/NullChatRealtime.php` | US-05 | FR-RT-002 |

## Privacy & retention

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Command/ChatPurgeCommand.php` | US-09 | FR-RET-001 |
| `src/Privacy/ChatPersonalData.php` | US-09 | FR-PRIV-001 |

## Twig, markup & theme

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Form/ChatDockComposerType.php` | US-02, US-08 | FR-TWIG-004 |
| `src/Form/ChatDockEditType.php` | US-02, US-08 | FR-TWIG-004 |
| `src/Form/ChatDockSearchType.php` | US-02, US-08 | FR-TWIG-004 |
| `src/Resources/public/css/nowo-chat.css` | US-08 | FR-THEME-001, FR-THEME-002, FR-THEME-003 |
| `src/Resources/views/_templates.html.twig` | US-08 | FR-TWIG-002, FR-TWIG-003 |
| `src/Resources/views/dock.html.twig` | US-01 | FR-TWIG-002 |
| `src/Resources/views/page.html.twig` | US-07 | FR-TWIG-002, FR-PAGE-001 |
| `src/Resources/views/saved.html.twig` | US-07 | FR-MENU-002 |
| `src/Twig/ChatDockExtension.php` | US-01, US-08 | FR-TWIG-001, FR-TWIG-004 |

## Browser (TypeScript)

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Resources/assets/src/api.ts` | US-02 | FR-TS-004 |
| `src/Resources/assets/src/dock.ts` | US-02 … US-05, US-08, US-10 | FR-TS-002, FR-TS-006, FR-TS-007 |
| `src/Resources/assets/src/dom.ts` | US-08 | FR-TS-005 |
| `src/Resources/assets/src/emoji.ts` | US-02 | FR-TS-005 |
| `src/Resources/assets/src/format.ts` | US-02 | FR-TS-005 |
| `src/Resources/assets/src/iife.ts` | US-01 | FR-TS-001, FR-BUILD-001 |
| `src/Resources/assets/src/logger.ts` | US-01 | FR-TS-011 |
| `src/Resources/assets/src/index.ts` | US-01 | FR-TS-001, FR-BUILD-001 |
| `src/Resources/assets/src/page.ts` | US-07 | FR-TS-008 |
| `src/Resources/assets/src/message-info.ts` | US-02 | FR-MENU-002 |
| `src/Resources/assets/src/saved-page.ts` | US-07 | FR-MENU-002 |
| `src/Resources/assets/src/realtime.ts` | US-05 | FR-TS-004 |
| `src/Resources/assets/src/sounds.ts` | US-05 | FR-TS-005 |
| `src/Resources/assets/src/state.ts` | US-05, US-08 | FR-TS-005, FR-TS-007 |
| `src/Resources/assets/src/templates.ts` | US-08 | FR-TS-005 |
| `src/Resources/assets/src/thread.ts` | US-02, US-04, US-05 | FR-TS-003, FR-MENU-001 |
| `src/Resources/assets/src/types.ts` | US-02, US-08 | FR-TS-005, FR-TS-007 |

## Translations

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Resources/translations/NowoChatKitBundle.de.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoChatKitBundle.en.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoChatKitBundle.es.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoChatKitBundle.fr.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoChatKitBundle.it.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoChatKitBundle.nl.yaml` | US-01 | FR-I18N-001 |
| `src/Resources/translations/NowoChatKitBundle.pt.yaml` | US-01 | FR-I18N-001 |

## Automatic replies, avatars & author badges

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/AutoReply/ChatAutoReplier.php` | US-02, US-03 | FR-AUTOREPLY-001 |
| `src/Avatar/AvatarUrlPolicy.php` | US-02, US-08 | FR-AVATAR-001 |
| `src/Avatar/ChatAvatars.php` | US-02, US-08 | FR-AVATAR-001 |
| `src/Avatar/NullChatAvatarUrlResolver.php` | US-08 | FR-AVATAR-001 |
| `src/Contract/ChatAvatarUrlResolverInterface.php` | US-08 | FR-AVATAR-001 |
| `src/Badge/ChatAuthorBadges.php` | US-02, US-08 | FR-BADGE-001 |
| `src/Badge/NoChatAuthorBadges.php` | US-08 | FR-BADGE-001 |
| `src/Contract/ChatAuthorBadgeProviderInterface.php` | US-08 | FR-BADGE-001 |
| `src/Enum/ChatAuthorBadgeVariant.php` | US-08 | FR-BADGE-001 |
| `src/Model/ChatAuthorBadge.php` | US-08 | FR-BADGE-001 |
| `src/Model/ChatAuthorRef.php` | US-08 | FR-BADGE-001 |

## Links (pinned links and links panel)

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Link/ChatLinkUrls.php` | US-10 | FR-LINK-001 |
| `src/Link/ChatLinks.php` | US-10 | FR-LINK-001 |
| `src/Model/ChatLinkProvider.php` | US-10 | FR-LINK-001 |
| `src/Model/ChatLinkView.php` | US-10 | FR-LINK-001, FR-EVT-002 |
| `src/Entity/ChatConversationLink.php` | US-10 | FR-LINK-002 |
| `src/Entity/ChatMessageLink.php` | US-10 | FR-LINK-002 |
| `src/Repository/ChatConversationLinkRepository.php` | US-10 | FR-LINK-002 |
| `src/Repository/ChatMessageLinkRepository.php` | US-10 | FR-LINK-002 |
| `src/Controller/ChatLinksController.php` | US-10 | FR-LINK-003 |
| `src/Contract/ChatLinkPolicyInterface.php` | US-10 | FR-LINK-003 |
| `src/Command/ChatLinksBackfillCommand.php` | US-10 | FR-LINK-004 |
| `src/Event/ChatLinkAddedEvent.php` | US-10 | FR-EVT-002 |
| `src/Event/ChatLinkUpdatedEvent.php` | US-10 | FR-EVT-002 |
| `src/Event/ChatLinkRemovedEvent.php` | US-10 | FR-EVT-002 |

## Mentions, references & quick replies

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Service/ChatMentions.php` | US-02, US-03 | FR-MENTION-001 |
| `src/Entity/ChatMessageMention.php` | US-02, US-03 | FR-MENTION-001 |
| `src/Repository/ChatMessageMentionRepository.php` | US-02, US-03 | FR-MENTION-001 |
| `src/Event/ChatMentionEvent.php` | US-02, US-03 | FR-MENTION-001, FR-EVT-002 |
| `src/Event/ChatMentionsErasedEvent.php` | US-09 | FR-MENTION-001, FR-EVT-002 |
| `src/Privacy/ChatMentionErasure.php` | US-09 | FR-MENTION-001 |
| `src/Contract/ChatChannelMentionPolicyInterface.php` | US-03 | FR-MENTION-001, FR-CHANNEL-001 |
| `src/Service/ChatReferences.php` | US-10 | FR-REF-001 |
| `src/Contract/ChatReferenceProviderInterface.php` | US-10 | FR-REF-001 |
| `src/Model/ChatReference.php` | US-10 | FR-REF-001 |
| `src/Model/ChatReferenceScope.php` | US-10 | FR-REF-001 |
| `src/Entity/ChatQuickReply.php` | US-02 | FR-QUICKREPLY-001 |
| `src/Repository/ChatQuickReplyRepository.php` | US-02 | FR-QUICKREPLY-001 |

## Channels, sidebar & preferences

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Contract/ChatChannelWritePolicyInterface.php` | US-03 | FR-CHANNEL-001 |
| `src/Contract/ChatPinPolicyInterface.php` | US-03 | FR-CHANNEL-001 |
| `src/Event/ChatChannelOpeningEvent.php` | US-03 | FR-CHANNEL-001, FR-EVT-002 |
| `src/Service/ChatChannelDeniedException.php` | US-03 | FR-CHANNEL-001 |
| `src/Model/ChatChannelKey.php` | US-03 | FR-CHANNEL-002 |
| `src/Doctrine/ChannelKeyCollationListener.php` | US-03 | FR-CHANNEL-002 |
| `src/Sidebar/ChatSidebar.php` | US-08 | FR-SIDEBAR-001 |
| `src/Controller/ChatSidebarController.php` | US-08 | FR-SIDEBAR-001 |
| `src/Contract/ChatSidebarSectionProviderInterface.php` | US-08 | FR-SIDEBAR-001 |
| `src/Model/ChatSidebarAction.php` | US-08 | FR-SIDEBAR-001 |
| `src/Model/ChatSidebarItem.php` | US-08 | FR-SIDEBAR-001 |
| `src/Model/ChatSidebarSection.php` | US-08 | FR-SIDEBAR-001 |
| `src/Form/ChatPreferencesType.php` | US-08 | FR-PREFS-001 |
| `src/Form/ChatDockStatusType.php` | US-08 | FR-PREFS-001, FR-TWIG-004 |

## Support tickets

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Support/ChatSupportTickets.php` | US-04 | FR-SUPPORT-002 |
| `src/Support/ChatSupportPolicy.php` | US-04 | FR-SUPPORT-002 |
| `src/Support/ChatSupportTopics.php` | US-04 | FR-SUPPORT-002 |
| `src/Support/ConfiguredSupportTopicProvider.php` | US-04 | FR-SUPPORT-002 |
| `src/Contract/ChatSupportTopicProviderInterface.php` | US-04 | FR-SUPPORT-002 |
| `src/Model/ChatSupportTopic.php` | US-04 | FR-SUPPORT-002 |
| `src/Support/ChatTicketNumbers.php` | US-04 | FR-SUPPORT-003 |
| `src/Entity/ChatTicketCounter.php` | US-04 | FR-SUPPORT-003 |
| `src/Event/SupportTicketCreatedEvent.php` | US-04 | FR-EVT-002 |
| `src/Event/SupportTicketAssignedEvent.php` | US-04 | FR-EVT-002 |
| `src/Event/SupportTicketReopenedEvent.php` | US-04 | FR-EVT-002 |
| `src/Event/SupportTicketStatusChangedEvent.php` | US-04 | FR-EVT-002 |

## Application ports (framework-free twins, messaging by id)

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Contract/ChatServiceInterface.php` | US-02 | FR-PORT-001 |
| `src/Security/SecurityChatUserResolver.php` | US-01 | FR-PORT-001 |
| `src/Contract/ChatChannelSourceInterface.php` | US-03 | FR-PORT-002 |
| `src/Contract/ChatParticipantResolverInterface.php` | US-01 | FR-PORT-002 |
| `src/Contract/ChatRealtimeTransportInterface.php` | US-05 | FR-PORT-002 |
| `src/Port/Ports.php` | US-01 | FR-PORT-002 |
| `src/Port/ChatChannelSourceGate.php` | US-03 | FR-PORT-002 |
| `src/Port/ChatParticipantResolverGate.php` | US-01 | FR-PORT-002 |
| `src/Port/ChatRealtimeTransportGate.php` | US-05 | FR-PORT-002 |
| `src/Port/ChatRequestContexts.php` | US-01 | FR-PORT-002 |
| `src/Model/ChatRequestContext.php` | US-01 | FR-PORT-002 |
| `src/Model/ChatSecurityUser.php` | US-01 | FR-PORT-002 |
| `src/DependencyInjection/Compiler/ChatKitPortsPass.php` | US-01 | FR-PORT-002 |
| `src/Contract/ChatMessagingInterface.php` | US-02 | FR-PORT-003 |
| `src/Contract/ChatMessageActionsInterface.php` | US-02 | FR-MENU-002, FR-PORT-003 |
| `src/Service/ChatMessaging.php` | US-02 | FR-PORT-003 |
| `src/Service/ChatNotFoundException.php` | US-02 | FR-PORT-003 |
| `src/Model/ChatConversationView.php` | US-02 | FR-PORT-003, FR-EVT-002 |
| `src/Model/ChatMessageView.php` | US-02 | FR-PORT-003, FR-EVT-002 |
| `src/Model/ChatParticipantView.php` | US-02 | FR-PORT-003 |

## User reference modes (relation, id, string)

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Doctrine/ChatUserReferences.php` | US-01 | FR-USERREF-001 |
| `src/Doctrine/UserReferenceListener.php` | US-01 | FR-USERREF-001 |
| `src/Doctrine/UnresolvedChatUser.php` | US-01 | FR-USERREF-001 |
| `src/Model/ChatUserReferenceInterface.php` | US-01 | FR-USERREF-001 |
| `src/Contract/ChatUserReferenceDirectoryInterface.php` | US-01 | FR-USERREF-001 |
| `src/Model/ChatUserReference.php` | US-01 | FR-USERREF-002 |
| `src/Doctrine/StringReferenceSchemaGuard.php` | US-01 | FR-USERREF-002 |
| `src/Doctrine/ChatUserLookAlikeException.php` | US-01 | FR-USERREF-002 |
| `src/Model/ErasedChatUser.php` | US-09 | FR-USERREF-003 |
| `src/Model/ErasedChatUserReference.php` | US-09 | FR-USERREF-003 |

## Private entity manager, savepoints & write concurrency

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/DependencyInjection/Compiler/PrivateEntityManagerPass.php` | US-01 | FR-PEM-001 |
| `src/DependencyInjection/Compiler/PrivateEntityManagerReferencesPass.php` | US-01 | FR-PEM-001 |
| `src/Doctrine/ChatKitEntityManager.php` | US-01 | FR-PEM-001 |
| `src/Doctrine/ChatKitManagerRegistry.php` | US-01 | FR-PEM-001 |
| `src/Doctrine/PrivateManagerRepositoryFactory.php` | US-01 | FR-PEM-001 |
| `src/Doctrine/PrivateEntityManagerMiddleware.php` | US-01 | FR-PEM-002 |
| `src/Doctrine/PrivateEntityManagerDriver.php` | US-01 | FR-PEM-002 |
| `src/Doctrine/PrivateEntityManagerConnection.php` | US-01 | FR-PEM-002 |
| `src/Doctrine/ChatKitSavepoint.php` | US-01 | FR-PEM-003 |
| `src/Doctrine/ChatKitRows.php` | US-02, US-03 | FR-CONC-001 |
| `src/Doctrine/ChatKitRowLock.php` | US-04 | FR-CONC-001 |
| `src/Transaction/ChatKitTransactionRunner.php` | US-02 | FR-CONC-002 |
| `src/Transaction/ChatKitWrites.php` | US-02 | FR-CONC-002 |
| `src/Worker/ChatRequestBoundaryListener.php` | US-01 | FR-WORKER-001 |

## Retention policy

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Contract/RetentionPolicyProviderInterface.php` | US-09 | FR-RET-002 |
| `src/Support/NullRetentionPolicyProvider.php` | US-09 | FR-RET-002 |
| `src/Support/ChatRetention.php` | US-09 | FR-RET-002 |
| `src/Support/ChatEmojiCatalog.php` | US-02 | FR-MENU-002 |
| `src/Support/InvalidRetentionConfigurationException.php` | US-09 | FR-RET-002 |
| `src/Support/RetentionUnavailableException.php` | US-09 | FR-RET-002 |
| `src/RuntimeSetting/RuntimeSettingRetentionPolicyProvider.php` | US-09 | FR-RET-002 |
| `src/DependencyInjection/Compiler/RuntimeRetentionPass.php` | US-09 | FR-RET-002 |

## Browser (TypeScript) — feature modules

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Resources/assets/src/avatar.ts` | US-08 | FR-AVATAR-001, FR-TS-009 |
| `src/Resources/assets/src/badges.ts` | US-08 | FR-BADGE-001, FR-TS-009 |
| `src/Resources/assets/src/composer-tools.ts` | US-02 | FR-QUICKREPLY-001, FR-TS-009 |
| `src/Resources/assets/src/links.ts` | US-10 | FR-LINK-005 |
| `src/Resources/assets/src/mention.ts` | US-02, US-03 | FR-MENTION-001, FR-TS-009 |
| `src/Resources/assets/src/reference.ts` | US-10 | FR-REF-001, FR-TS-009 |
| `src/Resources/assets/src/sidebar.ts` | US-08 | FR-SIDEBAR-001, FR-TS-009 |
| `src/Resources/assets/src/status.ts` | US-08 | FR-PREFS-001, FR-TS-009 |
| `src/Resources/assets/src/support.ts` | US-04 | FR-SUPPORT-002, FR-TS-009 |
| `src/Resources/assets/src/prefs.ts` | US-08 | FR-PREFS-001, FR-TS-009 |
| `src/Resources/assets/src/public.ts` | US-08 | FR-PREFS-001, FR-TS-009 |
| `src/Resources/assets/src/motion.ts` | US-08 | FR-TS-010 |
| `src/Resources/assets/src/overlay.ts` | US-08 | FR-TS-010 |
| `src/Resources/assets/src/message-menu.ts` | US-02, US-07 | FR-MENU-001, FR-TS-009 |
| `src/Resources/views/_author_badges.html.twig` | US-08 | FR-BADGE-001, FR-TWIG-002 |

## Message search (1.37)

Spec: [`../002-message-search/spec.md`](../002-message-search/spec.md).

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Search/ChatMessageSearch.php` | 002 US-01, US-02, US-03 | FR-001, FR-003, FR-004, FR-005, FR-007, FR-010 |
| `src/Search/ChatMessageSearchSql.php` | 002 US-01, US-02 | FR-002, FR-003, FR-004 |
| `src/Search/ChatMessageSearchQuery.php` | 002 US-04 | FR-006 |
| `src/Search/ChatSearchQueryException.php` | 002 US-01 | FR-006 |
| `src/Search/ChatMessageSearchHit.php` | 002 US-01 | FR-008 |
| `src/Search/ChatMessageSearchPage.php` | 002 US-01 | FR-007 |
| `src/Search/ChatSearchScope.php` | 002 US-02 | FR-003 |
| `src/Search/ChatSearchText.php` | 002 US-01, US-03 | FR-005, FR-006, FR-008 |
| `src/Search/ChatSearchIndex.php` | 002 US-06 | FR-002 |
| `src/Search/ChatSearchSchemaListener.php` | 002 US-06 | FR-002 |
| `src/Command/ChatSearchIndexCommand.php` | 002 US-06 | FR-002 |
| `src/Command/ChatSearchBackfillCommand.php` | 002 US-06 | FR-012 |
| `src/Controller/ChatSearchController.php` | 002 US-01, US-04 | FR-001, FR-006, FR-007, FR-008 |
| `src/Form/ChatMessageSearchType.php` | 002 US-04 | FR-001 |
| `src/Resources/views/search.html.twig` | 002 US-01 | FR-001, FR-008 |
| `src/SearchKit/ChatMessageSearchProvider.php` | 002 US-05 | FR-009 |
| `src/DependencyInjection/Compiler/SearchKitRecentsPass.php` | 002 US-05 | FR-009 |

## Sources added 1.34–2.2 (sweep 2026-11-22)

The 54 files below were added by releases 1.34–2.2 without an inventory row. The second column is the class docblock summary; the requirement ids are defined in the matching sections of `spec.md` (new: FR-QUERY-001, FR-FWD-001, FR-IDEM-001, FR-THREAD-001, FR-HIST-001, FR-SEARCHKIT-001, FR-ARCH-002, FR-PRIV-002, FR-MENU-004).

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Contract/ChatAccessInterface.php` | 2.2 (DDD-host checklist B1/B8, REQ-ARCH-001): whether a user may view, post in, pin in or manage the links of a conversation, and view or handle a support ticke | FR-ARCH-002 |
| `src/Contract/ChatForwardPolicyInterface.php` | 1.36: the application's veto on forwards (`message_menu.forward`), asked once per target after ChatKit's own checks (VIEW on the source, SEND on the target) and | FR-FWD-001 |
| `src/Contract/ChatHistoryPolicyInterface.php` | 1.36: who else may read the previous texts of an edited message (`message_menu.edit_history`) — e.g. | FR-HIST-001 |
| `src/Contract/ChatMentionInboxInterface.php` | 2.0: "messages that mention me" for a host page (a dashboard Mentions list), read and marked read through ChatKit's own (private) entity manager. | FR-QUERY-001 |
| `src/Contract/ChatQueryInterface.php` | 2.2 (DDD-host checklist B7, REQ-ARCH-001): what a host lists and counts in the chat, as read models — so it neither injects ChatKit's repositories nor writes DQ | FR-QUERY-001 |
| `src/Contract/ChatSupportInboxInterface.php` | 2.2 (DDD-host checklist B1/B7): the support agents' inbox by ids — list, count, assign and change the status of support tickets without injecting the concrete S | FR-QUERY-001 |
| `src/DependencyInjection/Compiler/RemovedPortsPass.php` | 2.0 (REQ-ARCH-001 rule 3, audit lesson XR-WF-M1): fails the container build on every service that still uses one of the port interfaces deprecated in 1.27 and r | FR-ARCH-002 |
| `src/Doctrine/ChatKitManagedRows.php` | 2.0: keeps ChatKit's private entity manager in step with its own DQL bulk writes. | FR-PRIV-002 |
| `src/Doctrine/ChatReadTimesOptOutListener.php` | 1.35 (audit TK-2): turning "Share read times" off deletes the user's stored read times in the same flush (and so the same transaction), whatever wrote the setti | FR-PRIV-002 |
| `src/Entity/ChatIdempotencyKey.php` | 1.36 (REQ-RUNTIME-003 rule 7): an `Idempotency-Key` a user sent with a write (forward, thread reply, send), the hash of that request and what it created, writte | FR-IDEM-001 |
| `src/Entity/ChatMessageRevision.php` | 1.36: a previous text of an edited message (`message_menu.edit_history`): the text it had before the edit and when it was replaced. | FR-HIST-001 |
| `src/Entity/ChatThreadRead.php` | 1.36: a user's place in a thread (`message_menu.threads`): the newest reply they read, and whether they follow it. | FR-THREAD-001 |
| `src/Event/ChatConversationDeletedEvent.php` | 2.0: a conversation was deleted for good by a host call (ChatMessagingInterface::deleteConversation() or purgeChannel()), with its messages and every row that h | FR-PRIV-002 |
| `src/Event/ChatMessageEditedEvent.php` | 1.36: a message was edited, dispatched after the commit. | FR-HIST-001 |
| `src/Event/ChatMessageForwardedEvent.php` | 1.36: a message was forwarded to one conversation, dispatched after the commit (once per target). | FR-FWD-001 |
| `src/Event/ChatMessageForwardingEvent.php` | 1.36: a message is about to be forwarded to one target, dispatched once per target before anything is written. | FR-FWD-001 |
| `src/Event/ChatThreadReplyEvent.php` | 1.36: a reply was posted in a thread, dispatched after the commit (besides ChatMessageSentEvent). | FR-THREAD-001 |
| `src/Event/EventRefs.php` | 2.0: how the events turn a user into the scalar the host stores (internal). | FR-ARCH-002 |
| `src/Forward/AllowAllChatForwardPolicy.php` | 1.36: the default `ChatForwardPolicyInterface`: ChatKit's own checks decide. | FR-FWD-001 |
| `src/History/NoChatHistoryReaders.php` | 1.36: the default `ChatHistoryPolicyInterface`: only the author and moderators read previous texts. | FR-HIST-001 |
| `src/Http/ChatConflictResponseFormatter.php` | 1.34: shapes the lock-conflict answer of nowo-tech/transaction-doctrine's responder for ChatKit's own JSON API routes. | FR-IDEM-001 |
| `src/Model/ChatConversationListItem.php` | 2.2: one row of a user's conversation list (ChatQueryInterface::conversationsFor()): the conversation's view plus what is the user's own about it. | FR-QUERY-001 |
| `src/Model/ChatForwardRequest.php` | 1.36 (REQ-ARCH-001 rule 3): one target of a forward as ChatForwardPolicyInterface sees it — public ids and keys, never an entity. | FR-FWD-001 |
| `src/Model/ChatHistoryRequest.php` | 1.36 (REQ-ARCH-001 rule 3): a request to read the previous texts of a message as ChatHistoryPolicyInterface sees it — public ids and keys, never an entity. | FR-HIST-001 |
| `src/Model/ChatMentionView.php` | 2.0: read-only snapshot of one @mention of a user, for a host "Mentions" page (ChatMentionInboxInterface): scalars, public ids and the message view, no Doctrine | FR-QUERY-001 |
| `src/Model/ChatSupportTicketItem.php` | 2.2: one row of the support inbox (ChatSupportInboxInterface::tickets()): the support thread's view plus what the agent sees about it. | FR-QUERY-001 |
| `src/Port/ChatChannelPort.php` | 2.0: the channel provider as the bundle asks it inside (the 1.x `ChatChannelPort`, removed in 2.0). | FR-ARCH-002 |
| `src/Port/ChatCurrentUserPort.php` | 2.0: the current-user resolver as the bundle asks it inside (the 1.x `CurrentChatUserResolverInterface`, removed in 2.0). | FR-ARCH-002 |
| `src/Port/ChatRealtimePort.php` | 2.0: the realtime transport as the bundle asks it inside (the 1.x `ChatRealtimePort`, removed in 2.0). | FR-ARCH-002 |
| `src/Query/ChatQuery.php` | 2.2: default ChatQueryInterface (aliased to it). | FR-QUERY-001 |
| `src/Repository/ChatIdempotencyKeyRepository.php` | 1.36 (REQ-RUNTIME-003 rule 7): the `Idempotency-Key`s of forwards, thread replies and sends. | FR-IDEM-001 |
| `src/Repository/ChatMessageRevisionRepository.php` | 1.36: the previous texts of edited messages (`message_menu.edit_history`). | FR-HIST-001 |
| `src/Repository/ChatThreadReadRepository.php` | 1.36: who follows a thread and how far they read it. | FR-THREAD-001 |
| `src/Resources/assets/src/forward-dialog.ts` | Forward dialog of the message menu (target picker, `message_menu.forward`, 1.36) | FR-FWD-001 |
| `src/Resources/assets/src/search-dialog.ts` | Search dialog of the dock (message search and SearchKit groups, 1.37–1.38) | FR-SEARCHKIT-001 |
| `src/Resources/assets/src/thread-panel.ts` | Thread panel of the dock (one-level threads, `message_menu.threads`, 1.36) | FR-THREAD-001 |
| `src/Resources/views/_search_results.html.twig` | Server-rendered result rows shared by the search page and the dialog (1.37–1.38) | FR-SEARCHKIT-001 |
| `src/SearchKit/ChatConversationSearchDefinition.php` | 1.38: the SearchKit "Conversations" group (`chat_channels`): the conversations the viewer can open on ChatKit's own pages, found by their name — a direct conver | FR-SEARCHKIT-001 |
| `src/SearchKit/ChatConversationSearchProvider.php` | 1.38: the "Conversations" group (`chat_channels`) of the SearchKit palette and results page, registered only with `search_kit.enabled`, `search_kit.channels.ena | FR-SEARCHKIT-001 |
| `src/SearchKit/ChatMessageSearchDefinition.php` | 1.38: the SearchKit "Messages" group (1.37) on SearchKit's provider base (nowo-tech/search-kit-bundle ^1.10): the hooks of `EntitySearchDefinition`, built  | FR-SEARCHKIT-001 |
| `src/SearchKit/ChatSearchKitViewer.php` | 1.38: the viewer of a SearchKit search, for both chat groups (ChatMessageSearchDefinition and ChatConversationSearchDefinition): the chat participant of the sig | FR-SEARCHKIT-001 |
| `src/Security/ChatActorResolver.php` | 2.2 (DDD-host checklist B7/B8): turns the user a host query port receives — a ChatUserInterface, or the user's public id (ChatUserInterface::getChatPublicId())  | FR-ARCH-002 |
| `src/Security/ChatUuidAccess.php` | 2.2: default ChatAccessInterface (aliased to it): loads the conversation by its public uuid through ChatKit's own entity manager, compared in SQL and again byte | FR-ARCH-002 |
| `src/Service/ChatConversationPurger.php` | 2.0: deletes a whole conversation through ChatKit's own (private) entity manager, for ChatMessagingInterface::deleteConversation() and purgeChannel(). | FR-PRIV-002 |
| `src/Service/ChatDraft.php` | 1.36: one message ChatService::post() is to write — in the main timeline, as a thread reply, or as a forward. | FR-MENU-004 |
| `src/Service/ChatEditHistory.php` | 1.36 (Beacon, D-20261118-02): edit history (`message_menu.edit_history`). | FR-HIST-001 |
| `src/Service/ChatForwardRefused.php` | 1.36: a forward was refused for one of its targets (no SEND there, a ticket for a non-agent, a person one may not write to, the application's veto). | FR-FWD-001 |
| `src/Service/ChatForwarder.php` | 1.36 (Beacon, D-20261118-02): "Forward" (`message_menu.forward`) — copies a text message to up to `max_targets` conversations (or new direct conversations), all | FR-FWD-001 |
| `src/Service/ChatIdempotency.php` | 1.36 (REQ-RUNTIME-003 rule 7): `Idempotency-Key` for writes a client may send again after a lost answer (forward, thread reply; optional on send). | FR-IDEM-001 |
| `src/Service/ChatIdempotencyConflict.php` | 1.36: a write unit of work was refused by its `Idempotency-Key` claim (another request used the key first). | FR-IDEM-001 |
| `src/Service/ChatMentionInbox.php` | 2.0: default ChatMentionInboxInterface (aliased to it): the mention repository's queries answered as views, and the mark-read writes by user key on ChatKit's ru | FR-QUERY-001 |
| `src/Service/ChatMenuOptions.php` | 1.36: which of the message menu's data items are offered — forward, threads, edit history — and their limits (`message_menu.forward`, `message_menu.threads`, `m | FR-MENU-004 |
| `src/Service/ChatSupportDesk.php` | 2.2: default ChatSupportInboxInterface (aliased to it): the agent is resolved (ChatActorResolver) and must be a reachable support agent; the threads come from C | FR-QUERY-001 |
| `src/Service/ChatThreads.php` | 1.36 (Beacon, D-20261118-02): one-level threads (`message_menu.threads`). | FR-THREAD-001 |

## Client API v1 (2.4)

| File | Role | Spec |
| --- | --- | --- |
| `src/ClientApi/ChatClientApiController.php` | 2.4: the client API v1 routes (`<prefix>/chat/v1`): me, conversations, conversation, messages, message, read, realtime; 2.5: send, thread reply, reactions, edit / delete own | FR-CAPI-001, FR-CAPW-001 |
| `src/ClientApi/ChatClientApiGuard.php` | 2.4: stateless checks of every client API request (budgets, Bearer via the host resolver, reachability, scope, context); 2.5: write scope and budgets, write event | FR-CAPI-001, FR-CAPW-002 |
| `src/ClientApi/ChatClientApiRouteLoader.php` | 2.4: routing type `nowo_chat_kit_client_api` (no route while the API is off) | FR-CAPI-001 |
| `src/ClientApi/ChatClientCall.php` | 2.4: principal + context of one request (request attribute only) | FR-CAPI-001 |
| `src/ClientApi/ChatClientCursor.php` | 2.4: opaque keyset cursor of the conversation list | FR-CAPI-001 |
| `src/ClientApi/ChatMessageTokens.php` | 2.4: message bodies as structured tokens, references resolved per reader (Q-BD-16, R2) | FR-CAPI-001 |
| `src/Contract/ChatClientPrincipalResolverInterface.php` | 2.4: host port resolving a Bearer token to a principal | FR-CAPI-001 |
| `src/Contract/ChatClientContextPolicyInterface.php` | 2.4: host port for the tenant named per request | FR-CAPI-001 |
| `src/Event/ChatClientApiDeniedEvent.php` | 2.4: a refused client API request (ids and codes only) | FR-CAPI-001 |
| `src/Contract/ChatClientTokenRevokerInterface.php` | 2.4.1: optional host port revoking the presented token on `POST logout` | FR-CAPI-012 |
| `src/Event/ChatClientLoggedOutEvent.php` | 2.4.1: a client signed out (token id, user public id, `revoked`; never the token) | FR-CAPI-012 |
| `src/Event/ChatClientApiWriteEvent.php` | 2.5: a client wrote through the client API (action, token id, conversation / message uuids, `replayed`; never the text or the token) | FR-CAPW-009 |
| `src/Model/ChatClientPrincipal.php` | 2.4: the caller of the client API | FR-CAPI-001 |
| `src/Model/ChatClientRequest.php` | 2.4: a client API request as the host ports see it | FR-CAPI-001 |
| `src/Model/ChatClientContext.php` | 2.4: a tenant a person may use | FR-CAPI-001 |
| `src/Model/ChatClientConversationRef.php` | 2.4: what the context policy learns about a conversation | FR-CAPI-001 |

## Typed cards and host slots (2.6)

| File | Role | Spec |
| --- | --- | --- |
| `src/Card/ChatCards.php` | 2.6: card, composer action and menu slot providers: isolation (deny on failure), unique keys, batched renders | FR-CARD-001, FR-SLOT-001, FR-SLOT-002 |
| `src/Contract/ChatCardProviderInterface.php` | 2.6: host port of a typed card type (`canShare()`, `render()`, `isSecret()`) | FR-CARD-001 |
| `src/Contract/ChatComposerActionProviderInterface.php` | 2.6: host port of composer actions | FR-SLOT-001 |
| `src/Contract/ChatMessageMenuSlotProviderInterface.php` | 2.6: host port of message menu slots | FR-SLOT-002 |
| `src/Enum/ChatCardState.php` | 2.6: Open or Locked | FR-CARD-003 |
| `src/Enum/ChatCardActionKind.php` | 2.6: open, request_access, host | FR-CARD-003 |
| `src/Enum/ChatMenuSlotTarget.php` | 2.6: message, selection, card | FR-SLOT-002 |
| `src/Event/ChatCardSharedEvent.php` | 2.6: a card was shared (ids only), after the commit | FR-CARD-006 |
| `src/Model/ChatCard.php` | 2.6: a card as one reader sees it (plain text, ≤ 4 facts, ≤ 3 actions) | FR-CARD-003 |
| `src/Model/ChatCardAction.php` | 2.6: a card action (same-origin path, optional dialog) | FR-CARD-003 |
| `src/Model/ChatCardFact.php` | 2.6: a card fact (plain text) | FR-CARD-003 |
| `src/Model/ChatCardScope.php` | 2.6: who asks, where (scalars: person, conversation, context, API flag) | FR-CARD-001 |
| `src/Model/ChatCardText.php` | 2.6: shared text, key and path rules of the card and slot value objects | FR-CARD-003 |
| `src/Model/ChatComposerAction.php` | 2.6: a composer action (key, label, icon, dialog path, slash alias) | FR-SLOT-001 |
| `src/Model/ChatComposerScope.php` | 2.6: the scope of composer actions and menu slots (scalars) | FR-SLOT-001, FR-SLOT-002 |
| `src/Model/ChatMenuSlot.php` | 2.6: a message menu slot (target, card types, own only, dialog path) | FR-SLOT-002 |
| `src/Resources/assets/src/slots.ts` | 2.6: dock side of cards and slots: card tray, insert-card, slot dialog, selection payload, before-send | FR-SLOT-001..003 |
