# Chat Kit Bundle — baseline code inventory

**Package**: `nowo-tech/chat-kit-bundle`  
**Last audited**: 2026-10-16  
**Production units**: **94/94**  
**Audit command**: `find src -type f \( -name '*.php' -o -name '*.twig' -o -name '*.yaml' -o -name '*.ts' -o -name '*.css' \) -not -path 'src/Resources/assets/tests/*' | wc -l`

## Scope notes

- Vitest files under `src/Resources/assets/tests/` are **excluded** (test code, not shipped behaviour).
- `src/Resources/public/js/nowo-chat.js` is the Vite **build output** of `index.ts` / `iife.ts` (`FR-BUILD-001`), not a separate unit.
- Covers the 1.2 "hide the dock to the side" feature (`FR-TS-007`, `FR-TWIG-003`, `FR-THEME-003`): no new file, existing units extended.
- `src/Resources/assets/README.md` is documentation; `demo/` and `.symfony/` (Flex recipe, see `docs/INSTALLATION.md`) are out of inventory scope.

## Coverage summary

| Category | Mapped |
| --- | ---: |
| Bundle, configuration & DI | 8 |
| Domain model & persistence | 13 |
| Contracts & value objects | 13 |
| Access control | 2 |
| Messaging | 7 |
| HTTP | 5 |
| Realtime, presence & notifications | 15 |
| Privacy & retention | 2 |
| Twig, markup & theme | 9 |
| Browser (TypeScript) | 13 |
| Translations | 7 |
| **Total** | **94** |

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
| `src/Service/ChatMarkdownRenderer.php` | US-02 | FR-MSG-002 |
| `src/Service/ChatPresenter.php` | US-02, US-07 | FR-MSG-003 |
| `src/Service/ChatService.php` | US-02, US-03, US-04 | FR-MSG-001 |
| `src/Service/ChatSupportInbox.php` | US-04 | FR-SUPPORT-001 |

## HTTP

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Controller/ChatApiController.php` | US-02 … US-05, US-10 | FR-API-001 |
| `src/Controller/ChatPageController.php` | US-07 | FR-PAGE-001 |
| `src/Form/ChatPageMessageType.php` | US-07 | FR-PAGE-001 |
| `src/Http/ChatApiException.php` | US-02 | FR-API-002 |
| `src/Http/ChatApiExceptionListener.php` | US-02 | FR-API-002 |

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
| `src/Resources/assets/src/realtime.ts` | US-05 | FR-TS-004 |
| `src/Resources/assets/src/sounds.ts` | US-05 | FR-TS-005 |
| `src/Resources/assets/src/state.ts` | US-05, US-08 | FR-TS-005, FR-TS-007 |
| `src/Resources/assets/src/templates.ts` | US-08 | FR-TS-005 |
| `src/Resources/assets/src/thread.ts` | US-02, US-04, US-05 | FR-TS-003 |
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
