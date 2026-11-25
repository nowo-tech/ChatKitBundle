# Plan: client API v1 (2.4.0)

## Units

- `src/ClientApi/`:
  - `ChatClientApiController`: the routes;
  - `ChatClientApiGuard`: the checks;
  - `ChatMessageTokens`: the tokenizer, mirroring `ChatMarkdownRenderer`;
  - `ChatClientCursor`: the conversation keyset cursor;
  - `ChatClientCall`: the request attribute;
  - `ChatClientApiRouteLoader`: the routing type.
- Ports: `ChatClientPrincipalResolverInterface` and `ChatClientContextPolicyInterface` (`src/Contract`).
- Values: `ChatClientPrincipal`, `ChatClientRequest`, `ChatClientContext`, `ChatClientConversationRef` (`src/Model`).
- Event: `ChatClientApiDeniedEvent`.
- Extended:
  - `ChatNotifier` (ids-only events);
  - `ChatConversationRepository::findListForUser()` (keyset `$beforeId`);
  - `ChatReferences` (`cardsFor`, `typeView`, `isSafeLink`);
  - `ChatApiException` (headers);
  - configuration (`client_api`, `realtime_events`);
  - `dock.ts` (fetches ids-only events).

## Decisions

- The same application services and voter as the web UI; there is no second copy of the rules.
- Routes are attribute routes imported by a loader, so they can take a configured prefix and disappear when the API is
  off.
- The person's read and action budgets are shared with the web UI. The IP and token budgets are separate limiters.
- The conversation list scans `limit + 1` rows, so a page may be short. The cursor always advances.
- Rendering format: tokens (Q-BD-16). The bundle never sends HTML to native clients.
