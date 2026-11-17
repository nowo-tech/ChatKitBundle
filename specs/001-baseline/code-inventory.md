# Chat Kit Bundle — baseline code inventory

**Package**: `nowo-tech/chat-kit-bundle`  
**Last audited**: 2026-11-17  
**Production units**: **227/227**  
**Audit command**: `find src -type f \( -name '*.php' -o -name '*.twig' -o -name '*.yaml' -o -name '*.ts' -o -name '*.css' \) -not -path 'src/Resources/assets/tests/*' | wc -l`

## Scope notes

- Vitest files under `src/Resources/assets/tests/` are **excluded** (test code, not shipped behaviour).
- `src/Resources/public/js/nowo-chat.js` is the Vite **build output** of `index.ts` / `iife.ts` (`FR-BUILD-001`), not a separate unit.
- Covers the 1.2 "hide the dock to the side" feature (`FR-TS-007`, `FR-TWIG-003`, `FR-THEME-003`): no new file, existing units extended.
- Covers 1.3: dock Form types (`FR-TWIG-004`), page mark-read script `page.ts` (`FR-TS-008`), page layout global and asset blocks (`FR-TWIG-002`).
- Covers 1.33 (message menu and context menu, `FR-MENU-001`): one new unit `message-menu.ts`; `ChatApiController`, `ChatMarkdownRenderer`, `ChatPresenter`, `ChatDockExtension`, `Configuration`, `_templates.html.twig`, `thread.ts`, `dock.ts`, `api.ts`, `types.ts`, `nowo-chat.css` and the translations extended.
- `src/Resources/assets/README.md` is documentation; `demo/` and `.symfony/` (Flex recipe, see `docs/INSTALLATION.md`) are out of inventory scope.

## Coverage summary

| Category | Mapped |
| --- | ---: |
| Bundle, configuration & DI | 8 |
| Domain model & persistence | 13 |
| Contracts & value objects | 13 |
| Access control | 2 |
| Messaging | 7 |
| HTTP | 6 |
| Realtime, presence & notifications | 15 |
| Privacy & retention | 2 |
| Twig, markup & theme | 10 |
| Browser (TypeScript) | 14 |
| Translations | 7 |
| Automatic replies, avatars & author badges | 11 |
| Links (pinned links and links panel) | 14 |
| Mentions, references & quick replies | 13 |
| Channels, sidebar & preferences | 14 |
| Support tickets | 12 |
| Application ports (framework-free twins, messaging by id) | 20 |
| User reference modes (relation, id, string) | 10 |
| Private entity manager, savepoints & write concurrency | 14 |
| Retention policy | 7 |
| Browser (TypeScript) — feature modules | 15 |
| **Total** | **227** |

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
| `src/Entity/ChatUserSettings.php` | US-05, US-06 | FR-MODEL-002 |
| `src/Entity/PublicIdTrait.php` | US-02 | FR-MODEL-001 |
| `src/Enum/ConversationKind.php` | US-02, US-03, US-04 | FR-MODEL-001 |
| `src/Enum/SupportStatus.php` | US-04 | FR-MODEL-001 |
| `src/Repository/ChatConversationRepository.php` | US-02, US-04 | FR-MODEL-003 |
| `src/Repository/ChatMessageRepository.php` | US-02, US-09 | FR-MODEL-003 |
| `src/Repository/ChatParticipantRepository.php` | US-02, US-05 | FR-MODEL-003 |
| `src/Repository/ChatReactionRepository.php` | US-02 | FR-MODEL-003 |
| `src/Repository/ChatUserSettingsRepository.php` | US-05, US-06 | FR-MODEL-003 |

## Contracts & value objects

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Channel/NullChannelProvider.php` | US-03 | FR-DI-001, FR-CONTRACT-003 |
| `src/Contract/ChatAccessPolicyInterface.php` | US-01, US-02, US-04 | FR-CONTRACT-002 |
| `src/Contract/ChatChannelProviderInterface.php` | US-03 | FR-CONTRACT-003 |
| `src/Contract/ChatDigestSenderInterface.php` | US-06 | FR-CONTRACT-004 |
| `src/Contract/ChatDirectoryInterface.php` | US-01, US-02 | FR-CONTRACT-001 |
| `src/Contract/ChatLinkPreviewProviderInterface.php` | US-10 | FR-CONTRACT-004 |
| `src/Contract/ChatPushSenderInterface.php` | US-06 | FR-CONTRACT-004 |
| `src/Contract/ChatRealtimeInterface.php` | US-05 | FR-CONTRACT-004 |
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
| `src/Event/ChatMessageSentEvent.php` | US-02 | FR-EVT-001 |
| `src/Event/ChatSupportStatusChangedEvent.php` | US-04 | FR-EVT-001 |
| `src/Service/ChatMarkdownRenderer.php` | US-02 | FR-MSG-002, FR-MENU-001 |
| `src/Service/ChatPresenter.php` | US-02, US-07 | FR-MSG-003 |
| `src/Service/ChatService.php` | US-02, US-03, US-04 | FR-MSG-001 |
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
| `src/Resources/views/page_layout.html.twig` | US-07 | FR-TWIG-002 |
| `src/Resources/views/page_legacy.html.twig` | US-07 | FR-TWIG-002, FR-PAGE-001 |
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
| `src/Resources/assets/src/index.ts` | US-01 | FR-TS-001, FR-BUILD-001 |
| `src/Resources/assets/src/page.ts` | US-07 | FR-TS-008 |
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
| `src/Contract/CurrentChatUserResolverInterface.php` | US-01 | FR-PORT-001 |
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
