<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\ClientApi;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Nowo\ChatKitBundle\Contract\ChatClientContextPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatIdempotencyKey;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Event\ChatClientApiWriteEvent;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatClientContext;
use Nowo\ChatKitBundle\Model\ChatClientConversationRef;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Model\ChatLinkPreview;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Port\ChatChannelPort;
use Nowo\ChatKitBundle\Port\ChatRealtimePort;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Realtime\ChatTopics;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatIdempotency;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatThreads;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function array_chunk;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function hash;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function mb_check_encoding;
use function min;
use function preg_match;
use function strlen;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * 2.4 (client API v1, Beacon Desktop BD-2): the versioned, stateless client API of the chat for native clients, at
 * `<client_api.prefix>/chat/v1` (D-20261110-04; the full path is the container parameter
 * `nowo_chat_kit.client_api.v1_path_prefix`). Bearer only: no session, no cookie, no CSRF (the token is not an ambient
 * credential). Read-only in v1, plus the reader's own read marks (scope `chat:read`, Q-BD-8). Every request goes through
 * ChatClientApiGuard (budgets, token, scope, context); every conversation and message goes through the same access rules
 * as the web UI (ChatVoter::decide() for the token's person, ChatAccess), then through the host's context policy.
 * Unknown, foreign or out-of-context uuids answer 404, never 403. Message bodies are structured tokens, never HTML
 * (Q-BD-16), with #references and link previews resolved for the reader (R2). Responses are never cached. Stateless: the
 * principal and the context live in the request attributes only (FrankenPHP worker mode, kernel reset false).
 */
#[Route('/chat/v1', name: 'nowo_chat_api_v1_')]
final readonly class ChatClientApiController
{
    /** The API version of this controller. */
    public const int VERSION = 1;

    public const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** Conversations per page by default, and at most. */
    public const int CONVERSATIONS_PAGE     = 50;
    public const int CONVERSATIONS_PAGE_MAX = 100;

    /** Messages per page by default, and at most. */
    public const int MESSAGES_PAGE     = 30;
    public const int MESSAGES_PAGE_MAX = 100;

    /** Values of `mentionAll`. */
    public const array MENTION_ALL = ['channel', 'here'];

    /** Conversations the first page reads to count `unreadTotal` (the most recent ones). */
    public const int UNREAD_SCAN = 200;

    /** Link previews per message (as the dock) and per response; the provider takes 10 URLs per call. */
    public const int PREVIEWS_PER_MESSAGE = 3;
    public const int PREVIEWS_MAX         = 30;

    /** Lifetime of a realtime subscriber token, as the web UI's. */
    public const string REALTIME_TTL = '+15 minutes';

    private const int MAX_JSON_BODY_BYTES = 4096;

    /**
     * 2.5: largest JSON body of a write: a text of ChatMessage::MAX_LENGTH characters fits even fully `\uXXXX`-escaped
     * (12 bytes per non-BMP character: 48 000), with room for the key and braces (audit ZCG-I3).
     */
    private const int MAX_WRITE_BODY_BYTES = 49_152;

    /** 2.5: the fields a write body may hold (`text`); 2.6 adds typed card references (see docs/API.md, "Writes"). */
    public const array WRITE_FIELDS = ['text'];

    /** 2.5: the write codes of the service, as the client API answers them (422). */
    private const array WRITE_ERRORS = [
        'empty'                => 'empty_text',
        'too_long'             => 'text_too_long',
        'invalid_link'         => 'invalid_link',
        'reaction'             => 'invalid_reaction',
        'not_threadable'       => 'not_threadable',
        'idempotency_mismatch' => 'idempotency_mismatch',
    ];

    /**
     * @param string $realtimeEvents `full` or `ids` (the profile's `realtime_events`): what hub events carry
     * @param ChatThreads|null $threads Thread reads (`message_menu.threads`); null or off: the thread route answers 404
     * @param ChatIdempotency|null $idempotency 2.5: the `Idempotency-Key` of the writes; null: no write route
     * @param list<string> $acceptedReactions 2.5: every emoji the server accepts as a reaction (`me.reactions.accepted`)
     * @param list<string> $quickReactions 2.5: the configured `reactions` (`me.reactions.quick`)
     */
    public function __construct(
        private ChatClientApiGuard $guard,
        private ChatAccess $access,
        private ChatVoter $voter,
        private ChatChannelPort $channels,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatParticipantRepository $participants,
        private ChatReactionRepository $reactions,
        private ChatUserSettingsRepository $settings,
        private ChatPresenter $presenter,
        private ChatPresence $presence,
        private ChatRecipients $recipients,
        private ChatServiceInterface $chat,
        private ChatRealtimePort $realtime,
        private ChatLinkPreviewProviderInterface $linkPreviews,
        private ChatMessageTokens $tokens,
        private string $realtimeEvents = 'full',
        private ClockInterface $clock = new Clock(),
        private ?ChatThreads $threads = null,
        private ?ChatIdempotency $idempotency = null,
        private array $acceptedReactions = [],
        private array $quickReactions = [],
    ) {
    }

    /**
     * The caller: who they are, the scopes of the token, the context of the request and the contexts they may use, and
     * what the server offers (`features`, `limits`).
     *
     * @param Request $request The request
     *
     * @return JsonResponse `{apiVersion, user, scopes, context, contexts, features, limits}`
     */
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $call     = $this->guard->authenticate($request);
        $user     = $call->principal->user;
        $policy   = $this->guard->contexts();
        $contexts = $policy instanceof ChatClientContextPolicyInterface ? $policy->contextsFor($call->principal) : [];
        $this->presenter->primeAvatars([$user]);

        $writes  = $this->writable();
        $threads = $this->threads instanceof ChatThreads && $this->threads->enabled();

        return $this->json([
            'apiVersion' => self::VERSION,
            'user'       => [
                'uuid'      => $user->getChatPublicId(),
                'name'      => $user->getChatDisplayName(),
                'initials'  => $user->getChatInitials(),
                'avatarUrl' => $this->presenter->avatarUrl($user),
            ],
            'scopes'   => $call->principal->scopes,
            'context'  => $call->contextKey,
            'contexts' => array_map(static fn (ChatClientContext $context): array => $context->toArray(), $contexts),
            'features' => [
                'realtime'       => $this->realtime->isEnabled(),
                'realtimeEvents' => $this->realtimeEvents,
                // 2.5: the write routes this server offers to a token holding chat:write (false: they answer 404).
                'send'        => $writes,
                'threadReply' => $writes && $threads,
                'reactions'   => $writes,
                'editOwn'     => $writes,
                'deleteOwn'   => $writes,
                'search'      => false,
                'attachments' => false,
                // 2.6 (spec 259): typed card references in a write body. false: a body holds `text` only.
                'cards' => false,
                // 2.4.1: whether `POST logout` revokes the token on the server (else the client only forgets it).
                'serverLogout' => $this->guard->revokes(),
            ],
            'limits' => [
                'conversationsPageMax' => self::CONVERSATIONS_PAGE_MAX,
                'messagesPageMax'      => self::MESSAGES_PAGE_MAX,
                // 2.5: the longest text (characters), the edit/delete window of one's own messages and the longest key.
                'textMaxLength'           => ChatMessage::MAX_LENGTH,
                'editWindowSeconds'       => ChatMessage::EDIT_WINDOW_SECONDS,
                'idempotencyKeyMaxLength' => ChatIdempotencyKey::MAX_KEY_LENGTH,
            ],
            // 2.5: the reactions a client may set: the quick row and every accepted emoji.
            'reactions' => ['quick' => $this->quickReactions, 'accepted' => $this->acceptedReactions],
        ]);
    }

    /**
     * The caller's conversations in the request's context, newest activity first (`?archived=1`: the ones they closed),
     * a page at a time: follow `nextCursor` until it is null (a page may hold fewer rows than `limit`, or none, when
     * the scanned rows were not visible). The first page (no cursor) also answers `unreadTotal`, muted conversations
     * excluded, counted over the {@see self::UNREAD_SCAN} most recent conversations; later pages answer null.
     *
     * @param Request $request The request (query `archived`, `cursor`, `limit`)
     *
     * @return JsonResponse `{conversations, nextCursor, unreadTotal, unreadTotalCapped}`
     */
    #[Route('/conversations', name: 'conversations', methods: ['GET'])]
    public function conversations(Request $request): JsonResponse
    {
        $call     = $this->guard->authenticate($request);
        $user     = $call->principal->user;
        $archived = $request->query->getBoolean('archived');
        $limit    = $this->limit($request, self::CONVERSATIONS_PAGE, self::CONVERSATIONS_PAGE_MAX);
        $cursor   = $request->query->get('cursor');
        $position = null;
        if ($cursor !== null) {
            $position = ChatClientCursor::read((string) $cursor);
            $this->deny($position === null, 'invalid_cursor', Response::HTTP_BAD_REQUEST);
        }
        $isAgent     = $this->access->isSupportAgent($user);
        $channelKeys = array_map(static fn (ChatChannel $channel): string => $channel->key, $this->channels->channelsFor($user));
        // Audit ZBWv-I2: the first page reads one row more than it counts, so `unreadTotalCapped` is true only when more exist.
        $scan   = $this->conversations->findListForUser($user, $channelKeys, $isAgent, $position === null ? self::UNREAD_SCAN + 1 : $limit + 1, $position[0] ?? null, $position[1] ?? null);
        $capped = $position === null ? count($scan) > self::UNREAD_SCAN : null;
        if ($capped === true) {
            $scan = array_slice($scan, 0, self::UNREAD_SCAN);
        }
        $page       = array_slice($scan, 0, $limit);
        $nextCursor = count($scan) > $limit ? ChatClientCursor::after($page[count($page) - 1]) : null;

        $visible          = $this->visible($call, $position === null ? $scan : $page);
        $ids              = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $visible);
        $unread           = $this->messages->countUnreadByConversation((string) ChatUserReferences::key($user), $ids);
        [$closed, $muted] = $this->participantStates($user, $ids);
        $pageIds          = array_flip(array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $page));
        $rows             = array_values(array_filter(
            $visible,
            static fn (ChatConversation $conversation): bool => isset($pageIds[(int) $conversation->getId()]) && isset($closed[(int) $conversation->getId()]) === $archived,
        ));

        return $this->json([
            'conversations' => $this->presentList($call, $rows, $unread, $muted, $archived),
            'nextCursor'    => $nextCursor,
            // A muted conversation keeps its own unread count but leaves the total (as the web UI).
            'unreadTotal' => $position === null ? array_sum(array_diff_key($unread, $muted)) : null,
            // The total was counted over the UNREAD_SCAN most recent conversations and more exist ("200+").
            'unreadTotalCapped' => $capped,
        ]);
    }

    /**
     * One conversation as the caller sees it, with its member count and what the caller may do (404 when not visible
     * or not in the request's context).
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The request
     *
     * @return JsonResponse `{conversation}`
     */
    #[Route('/conversations/{uuid}', name: 'conversation', requirements: ['uuid' => self::UUID], methods: ['GET'])]
    public function conversation(string $uuid, Request $request): JsonResponse
    {
        $call             = $this->guard->authenticate($request);
        $user             = $call->principal->user;
        $conversation     = $this->find($request, $call, $uuid);
        $id               = (int) $conversation->getId();
        [$closed, $muted] = $this->participantStates($user, [$id]);
        $unread           = $this->messages->countUnreadByConversation((string) ChatUserReferences::key($user), [$id]);
        $row              = $this->presentList($call, [$conversation], $unread, $muted, isset($closed[$id]))[0];

        return $this->json(['conversation' => $row + [
            'membersCount' => count($this->recipients->of($conversation)),
            // What the caller may do here (2.5: the write routes, when the server offers them, follow the same rule).
            'permissions' => ['send' => $row['canSend']],
        ]]);
    }

    /**
     * A page of messages, oldest first: the newest page, `?before=<id>` (older) or `?after=<id>` (catch-up, e.g. after
     * a realtime hint), `limit` 1–100 (default 30). Bodies are tokens; #references and link previews are resolved for
     * the reader.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The request (query `before`, `after`, `limit`)
     *
     * @return JsonResponse `{messages, hasMore, readUpTo}`
     */
    #[Route('/conversations/{uuid}/messages', name: 'messages', requirements: ['uuid' => self::UUID], methods: ['GET'])]
    public function messages(string $uuid, Request $request): JsonResponse
    {
        $call         = $this->guard->authenticate($request);
        $user         = $call->principal->user;
        $conversation = $this->find($request, $call, $uuid);
        $limit        = $this->limit($request, self::MESSAGES_PAGE, self::MESSAGES_PAGE_MAX);
        $before       = $this->messageId($request, 'before');
        $after        = $this->messageId($request, 'after');
        $this->deny($before !== null && $after !== null, 'invalid_cursor', Response::HTTP_BAD_REQUEST);
        $page    = $this->messages->findPage($conversation, $limit + 1, $before, $after);
        $hasMore = count($page) > $limit;
        if ($hasMore) {
            // Older pages drop their oldest extra row; catch-up pages their newest (as the web UI).
            $page = $after === null ? array_slice($page, 1) : array_slice($page, 0, $limit);
        }

        return $this->json([
            'messages' => $this->presentMessages($user, $page),
            'hasMore'  => $hasMore,
            'readUpTo' => $this->participants->findOneFor($conversation, $user)?->getLastReadMessageId(),
        ]);
    }

    /**
     * One message as the caller sees it (the client fetches it after a realtime hint). 404 when not visible.
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid Message uuid
     * @param Request $request The request
     *
     * @return JsonResponse `{message}`
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}', name: 'message', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['GET'])]
    public function message(string $uuid, string $messageUuid, Request $request): JsonResponse
    {
        $call    = $this->guard->authenticate($request);
        $message = $this->messages->findOneInConversation($this->find($request, $call, $uuid), $messageUuid);
        if (!$message instanceof ChatMessage || $message->getUuid() !== $messageUuid) {
            $this->guard->notFound($request, $call);
        }

        return $this->json(['message' => $this->presentMessages($call->principal->user, [$message])[0]]);
    }

    /**
     * Moves the caller's read cursor to `messageId` (JSON body), capped at the conversation's newest message and never
     * moved back (the cursor row is locked: two devices never lose an update). Scope `chat:read` (Q-BD-8: it changes the
     * reader's own state only); counts against the person's action budget, shared with the web UI.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request JSON body `{messageId}`
     *
     * @return JsonResponse `{ok, readUpTo}`
     */
    #[Route('/conversations/{uuid}/read', name: 'read', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function read(string $uuid, Request $request): JsonResponse
    {
        $call         = $this->guard->authenticate($request, ChatClientPrincipal::SCOPE_READ, ChatClientApiGuard::BUDGET_ACTION);
        $user         = $call->principal->user;
        $conversation = $this->find($request, $call, $uuid);
        $requested    = $this->payload($request)['messageId'] ?? null;
        $this->deny(!is_int($requested) || $requested < 1, 'invalid_message_id', Response::HTTP_UNPROCESSABLE_ENTITY);
        $latest = $this->messages->findLatest($conversation);
        // Never beyond the newest message of this conversation (ids are global).
        $upTo = $latest instanceof ChatMessage ? min($requested, (int) $latest->getId()) : 0;
        if ($upTo > 0) {
            $this->chat->markRead($user, $conversation, $upTo);
        }

        return $this->json(['ok' => true, 'readUpTo' => $this->participants->findOneFor($conversation, $user)?->getLastReadMessageId()]);
    }

    /**
     * A thread (owner 2026-11-25: threads in the first desktop version): its root and a page of replies, oldest first
     * (`?before=<id>` older, `?after=<id>` catch-up; 30 per page), with the reply count and the caller's thread read
     * cursor. A reply's uuid opens its root's thread. Same access as the conversation; 404 while threads are off
     * (`message_menu.threads.enabled`). Read-only: replying comes with the write routes.
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid The root's (or a reply's) uuid
     * @param Request $request The request (query `before`, `after`)
     *
     * @return JsonResponse `{root, replies, hasMore, replyCount, readUpTo, following}`
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}/thread', name: 'thread', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['GET'])]
    public function thread(string $uuid, string $messageUuid, Request $request): JsonResponse
    {
        $call    = $this->guard->authenticate($request);
        $threads = $this->threads;
        if (!$threads instanceof ChatThreads || !$threads->enabled()) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }
        $message = $this->messages->findOneInConversation($this->find($request, $call, $uuid), $messageUuid);
        if (!$message instanceof ChatMessage || $message->getUuid() !== $messageUuid) {
            $this->guard->notFound($request, $call);
        }
        $before = $this->messageId($request, 'before');
        $after  = $this->messageId($request, 'after');
        $this->deny($before !== null && $after !== null, 'invalid_cursor', Response::HTTP_BAD_REQUEST);
        $user = $call->principal->user;
        $root = ChatThreads::rootOf($message);
        $page = $threads->page($user, $root, $before, $after);
        $rows = $this->presentMessages($user, [$root, ...$page['replies']]);

        return $this->json([
            'root'       => $rows[0],
            'replies'    => array_slice($rows, 1),
            'hasMore'    => $page['hasMore'],
            'replyCount' => $root->getThreadReplyCount(),
            'readUpTo'   => $page['readUpTo'],
            'following'  => $page['following'],
        ]);
    }

    /**
     * The realtime hub and a short-lived subscriber token for the caller's own private topic only (R1), issued by the
     * host's realtime transport (Beacon: its Mercure hub); the bundle signs nothing itself unless it is the transport.
     * `events` says what hub events carry: `ids` (re-fetch through this API) or `full` (the web UI's payloads; treat
     * them as hints too). `enabled: false`: poll.
     *
     * @param Request $request The request
     *
     * @return JsonResponse `{enabled, hubUrl, token, topic, expiresAt, events}`
     */
    #[Route('/realtime', name: 'realtime', methods: ['GET'])]
    public function realtime(Request $request): JsonResponse
    {
        $call      = $this->guard->authenticate($request);
        $topic     = ChatTopics::forUser($call->principal->user->getChatPublicId());
        $expiresAt = $this->clock->now()->modify(self::REALTIME_TTL);
        $enabled   = $this->realtime->isEnabled();
        $token     = $enabled ? $this->realtime->subscriberToken([$topic], $expiresAt) : null;
        $hubUrl    = $enabled ? $this->realtime->browserHubUrl($request) : null;
        $on        = $token !== null && $hubUrl !== null;

        return $this->json([
            'enabled'   => $on,
            'hubUrl'    => $on ? $hubUrl : null,
            'token'     => $on ? $token : null,
            'topic'     => $topic,
            'expiresAt' => $expiresAt->format(DATE_ATOM),
            'events'    => $this->realtimeEvents,
        ]);
    }

    /**
     * 2.4.1: signs the client out: the host revokes the presented token (and only it), then ChatClientLoggedOutEvent is
     * dispatched. Always 204 with no body, also for a token that was already revoked, has expired or is unknown (never an
     * oracle), so a client may retry it freely. Without a configured revoker it still answers 204 and the token stays
     * valid until it expires: `me.features.serverLogout` tells the client so. Per-IP and per-token budgets apply (429).
     *
     * @param Request $request The request (Bearer token; no body)
     *
     * @return Response 204, empty
     */
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $this->guard->logout($request);

        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * 2.5: sends a message to the conversation, exactly as the web UI does: the same posting rights (read-only channels,
     * blocked direct messages, closed tickets: 403 `read_only`), the same pipeline (mentions resolved, #references
     * validated, links, support status, unread counters, realtime, Web Push, digest, search index, events). Needs
     * `chat:write` (and `chat:read`), `client_api.writes` and an `Idempotency-Key` (per token and conversation): a
     * retry with the same key and text answers the first message (200, `replayed: true`) without a second one.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request JSON body `{text}`, header `Idempotency-Key`
     *
     * @return JsonResponse `{message, replayed}`: 201 (200 for a replay)
     */
    #[Route('/conversations/{uuid}/messages', name: 'send', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function send(string $uuid, Request $request): JsonResponse
    {
        $call                 = $this->guard->authenticate($request, ChatClientPrincipal::SCOPE_WRITE, ChatClientApiGuard::BUDGET_SEND);
        [$chat, $idempotency] = $this->writers();
        $key                  = $this->idempotencyKey($request);
        $text                 = $this->text($request);
        $user                 = $call->principal->user;
        $conversation         = $this->find($request, $call, $uuid);
        $this->deny(!$this->voter->decide(ChatVoter::SEND, $conversation, $user), 'read_only', Response::HTTP_FORBIDDEN);
        [$messages, $replayed] = $this->writing(static fn (): array => $idempotency->run(
            $user,
            self::scopedKey($call, $conversation, $key),
            ChatIdempotency::hash(['client:send', $conversation->getUuid(), $text]),
            static fn (Closure $before, Closure $after): array => [$chat->sendWith($user, $conversation, $text, $before, $after)],
        ));
        $message = $messages[0] ?? $this->guard->notFound($request, $call);
        $this->guard->written($request, $call, ChatClientApiWriteEvent::SEND, $conversation->getUuid(), $message->getUuid(), $replayed);

        return $this->json(['message' => $this->presentMessages($user, [$message])[0], 'replayed' => $replayed], $replayed ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * 2.5: replies in the thread of a message (a reply's uuid replies in its root's thread), with the send pipeline
     * (followers notified, Web Push to them). 404 while threads are off; 403 `read_only` without posting rights; 422
     * `not_threadable` for a message that takes no thread (a line, a bot message, a tombstone). `Idempotency-Key` as send.
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid The root's (or a reply's) uuid
     * @param Request $request JSON body `{text}`, header `Idempotency-Key`
     *
     * @return JsonResponse `{message, root, replayed}`: 201 (200 for a replay)
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}/thread', name: 'thread_reply', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['POST'])]
    public function threadReply(string $uuid, string $messageUuid, Request $request): JsonResponse
    {
        $call = $this->guard->authenticate($request, ChatClientPrincipal::SCOPE_WRITE, ChatClientApiGuard::BUDGET_SEND);
        $this->writers();
        $threads = $this->threads;
        if (!$threads instanceof ChatThreads || !$threads->enabled()) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }
        $key          = $this->idempotencyKey($request);
        $text         = $this->text($request);
        $user         = $call->principal->user;
        $conversation = $this->find($request, $call, $uuid);
        $message      = $this->findMessage($request, $call, $conversation, $messageUuid);
        // Audit ZCG-I4 (defence in depth): the reply goes to its root's conversation, which must be the authorised one.
        if (ChatThreads::rootOf($message)->getConversation()->getId() !== $conversation->getId()) {
            $this->guard->notFound($request, $call);
        }
        $this->deny(!$this->voter->decide(ChatVoter::SEND, $conversation, $user), 'read_only', Response::HTTP_FORBIDDEN);
        $this->deny(!$this->voter->decide(ChatVoter::THREAD, $message, $user), 'not_threadable', Response::HTTP_UNPROCESSABLE_ENTITY);
        [$reply, $replayed] = $this->writing(static fn (): array => $threads->reply($user, $message, $text, self::scopedKey($call, $conversation, $key)));
        $root               = ChatThreads::rootOf($reply);
        $this->guard->written($request, $call, ChatClientApiWriteEvent::THREAD_REPLY, $conversation->getUuid(), $reply->getUuid(), $replayed);
        $rows = $this->presentMessages($user, [$reply, $root]);

        return $this->json(['message' => $rows[0], 'root' => $rows[1], 'replayed' => $replayed], $replayed ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * 2.5: sets (`PUT`) or removes (`DELETE`) the caller's reaction with the emoji of the path (URL-encoded; one of
     * `me.reactions.accepted`, else 422 `invalid_reaction`). Idempotent by itself: setting a reaction one has, or
     * removing one one does not have, changes nothing and publishes nothing; an `Idempotency-Key` is still required.
     * 403 `read_only` without posting rights; 422 `not_reactable` for a line, a buzz or a tombstone.
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid Message uuid
     * @param string $emoji The emoji
     * @param Request $request Header `Idempotency-Key`, no body
     *
     * @return JsonResponse `{reactions, replayed}` (`reactions`: the message's `{emoji, count, mine}` after the change)
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}/reactions/{emoji}', name: 'reaction', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID, 'emoji' => '[^/]{1,64}'], methods: ['PUT', 'DELETE'])]
    public function reaction(string $uuid, string $messageUuid, string $emoji, Request $request): JsonResponse
    {
        $call                 = $this->guard->authenticate($request, ChatClientPrincipal::SCOPE_WRITE, ChatClientApiGuard::BUDGET_ACTION);
        [$chat, $idempotency] = $this->writers();
        $key                  = $this->idempotencyKey($request);
        $user                 = $call->principal->user;
        $on                   = $request->getMethod() === Request::METHOD_PUT;
        $conversation         = $this->find($request, $call, $uuid);
        $message              = $this->findMessage($request, $call, $conversation, $messageUuid);
        $this->deny(!$this->voter->decide(ChatVoter::SEND, $conversation, $user), 'read_only', Response::HTTP_FORBIDDEN);
        $this->deny(!$this->voter->decide(ChatVoter::REACT, $message, $user), 'not_reactable', Response::HTTP_UNPROCESSABLE_ENTITY);
        // Audit ZCG-L1: before the request hash (JSON): a non-UTF-8 path segment or an emoji the server does not accept.
        $this->deny(!mb_check_encoding($emoji, 'UTF-8') || !in_array($emoji, $this->acceptedReactions, true), 'invalid_reaction', Response::HTTP_UNPROCESSABLE_ENTITY);
        [, $replayed] = $this->writing(static fn (): array => $idempotency->run(
            $user,
            self::scopedKey($call, $conversation, $key),
            ChatIdempotency::hash(['client:reaction', $message->getUuid(), $emoji, $on]),
            static function (Closure $before, Closure $after) use ($chat, $user, $message, $emoji, $on): array {
                $chat->setReaction($user, $message, $emoji, $on, $before, $after);

                return [$message];
            },
        ));
        $this->guard->written($request, $call, $on ? ChatClientApiWriteEvent::REACTION_ADD : ChatClientApiWriteEvent::REACTION_REMOVE, $conversation->getUuid(), $message->getUuid(), $replayed);
        $mid = (int) $message->getId();

        return $this->json(['reactions' => $this->reactionCounts($this->reactions->summaryFor([$mid])[$mid] ?? [], $user), 'replayed' => $replayed]);
    }

    /**
     * 2.5: edits one of the caller's own messages within the edit window, as the web UI (403 `read_only` without
     * posting rights, 403 `edit_forbidden` for someone else's message, a buzz, a tombstone or after the window). The
     * same text again changes nothing. `Idempotency-Key` as send.
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid Message uuid
     * @param Request $request JSON body `{text}`, header `Idempotency-Key`
     *
     * @return JsonResponse `{message, replayed}`
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}', name: 'edit', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['PATCH'])]
    public function edit(string $uuid, string $messageUuid, Request $request): JsonResponse
    {
        $call                 = $this->guard->authenticate($request, ChatClientPrincipal::SCOPE_WRITE, ChatClientApiGuard::BUDGET_ACTION);
        [$chat, $idempotency] = $this->writers();
        $key                  = $this->idempotencyKey($request);
        $text                 = $this->text($request);
        $user                 = $call->principal->user;
        $conversation         = $this->find($request, $call, $uuid);
        $message              = $this->findMessage($request, $call, $conversation, $messageUuid);
        $this->deny(!$this->voter->decide(ChatVoter::SEND, $conversation, $user), 'read_only', Response::HTTP_FORBIDDEN);
        $this->deny(!$this->voter->decide(ChatVoter::EDIT, $message, $user), 'edit_forbidden', Response::HTTP_FORBIDDEN);
        [, $replayed] = $this->writing(static fn (): array => $idempotency->run(
            $user,
            self::scopedKey($call, $conversation, $key),
            ChatIdempotency::hash(['client:edit', $message->getUuid(), $text]),
            static function (Closure $before, Closure $after) use ($chat, $message, $text): array {
                $chat->editWith($message, $text, $before, $after);

                return [$message];
            },
        ));
        // The key promises this message (its uuid is in the request hash): the one read above, as the write left it.
        $this->guard->written($request, $call, ChatClientApiWriteEvent::EDIT, $conversation->getUuid(), $message->getUuid(), $replayed);

        return $this->json(['message' => $this->presentMessages($user, [$message])[0], 'replayed' => $replayed]);
    }

    /**
     * 2.5: deletes one of the caller's own messages within the edit window, as the web UI (nobody deletes someone else's
     * message: 403 `delete_forbidden`). Deleting one's own message that is already deleted answers its tombstone again
     * (idempotent). `Idempotency-Key` as send.
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid Message uuid
     * @param Request $request Header `Idempotency-Key`, no body
     *
     * @return JsonResponse `{message, replayed}` (the tombstone)
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}', name: 'delete', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['DELETE'])]
    public function delete(string $uuid, string $messageUuid, Request $request): JsonResponse
    {
        $call                 = $this->guard->authenticate($request, ChatClientPrincipal::SCOPE_WRITE, ChatClientApiGuard::BUDGET_ACTION);
        [$chat, $idempotency] = $this->writers();
        $key                  = $this->idempotencyKey($request);
        $user                 = $call->principal->user;
        $conversation         = $this->find($request, $call, $uuid);
        $message              = $this->findMessage($request, $call, $conversation, $messageUuid);
        $again                = $message->isDeleted() && $message->isAuthoredBy($user);
        $this->deny(!$again && !$this->voter->decide(ChatVoter::DELETE, $message, $user), 'delete_forbidden', Response::HTTP_FORBIDDEN);
        [, $replayed] = $this->writing(static fn (): array => $idempotency->run(
            $user,
            self::scopedKey($call, $conversation, $key),
            ChatIdempotency::hash(['client:delete', $message->getUuid()]),
            static function (Closure $before, Closure $after) use ($chat, $user, $message): array {
                $chat->deleteWith($user, $message, $before, $after);

                return [$message];
            },
        ));
        $this->guard->written($request, $call, ChatClientApiWriteEvent::DELETE, $conversation->getUuid(), $message->getUuid(), $replayed);

        return $this->json(['message' => $this->presentMessages($user, [$message])[0], 'replayed' => $replayed]);
    }

    /**
     * 2.5: the services of the writes; 404 while `client_api.writes` is off or the host replaced ChatKit's chat service
     * (its writes cannot join an Idempotency-Key's unit of work).
     *
     * @return array{ChatService, ChatIdempotency}
     */
    private function writers(): array
    {
        $chat        = $this->chat;
        $idempotency = $this->idempotency;
        if (!$this->writable() || !$chat instanceof ChatService || !$idempotency instanceof ChatIdempotency) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }

        return [$chat, $idempotency];
    }

    /** 2.5: whether this server offers the write routes. */
    private function writable(): bool
    {
        return $this->guard->writes() && $this->chat instanceof ChatService && $this->idempotency instanceof ChatIdempotency;
    }

    /**
     * 2.5: runs a write, answering the service's refusals as 422 with the client API's codes.
     *
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    private function writing(Closure $write): mixed
    {
        try {
            return $write();
        } catch (InvalidArgumentException $refused) {
            $code = $refused->getMessage();
            if ($code === 'not_found') {
                throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND, $refused);
            }

            throw new ChatApiException(self::WRITE_ERRORS[$code] ?? 'invalid_request', Response::HTTP_UNPROCESSABLE_ENTITY, $refused);
        }
    }

    /**
     * 2.5: the required `Idempotency-Key` header: 1–80 characters of `[A-Za-z0-9_.:-]` (a UUID fits); 400 otherwise.
     */
    private function idempotencyKey(Request $request): string
    {
        $key = $request->headers->get(ChatIdempotency::HEADER);
        $this->deny($key === null, 'idempotency_key_required', Response::HTTP_BAD_REQUEST);
        $this->deny(!ChatIdempotency::isValidKey($key), 'idempotency_key_invalid', Response::HTTP_BAD_REQUEST);

        return (string) $key;
    }

    /**
     * 2.5: the stored key: the client's key scoped to the token and the conversation (a key reused by another device
     * of the person, or in another conversation, is another key). A hash, so it fits the column whatever the token id.
     */
    private static function scopedKey(ChatClientCall $call, ChatConversation $conversation, string $key): string
    {
        // Neither part can hold a line break (token ids and keys are restricted, uuids fixed): the join is unambiguous.
        return ChatIdempotency::CLIENT_PREFIX . hash('sha256', $call->principal->tokenId . "\n" . $conversation->getUuid() . "\n" . $key);
    }

    /**
     * 2.5: the `text` of a write body (JSON object, at most 48 KB). Any other field is refused (422
     * `unsupported_field`), so a client written for a later version (2.6 card references) never has part of its message
     * silently dropped; `text` must be a string (422 `invalid_text`). Emptiness and length are the service's (422
     * `empty_text`, `text_too_long`).
     */
    private function text(Request $request): string
    {
        $data  = $this->payload($request, self::MAX_WRITE_BODY_BYTES);
        $extra = array_keys(array_diff_key($data, array_flip(self::WRITE_FIELDS)));
        $this->deny($extra !== [], 'unsupported_field', Response::HTTP_UNPROCESSABLE_ENTITY);
        $text = $data['text'] ?? null;
        if (!is_string($text)) {
            throw new ChatApiException('invalid_text', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $text;
    }

    /** 2.5: the message of exactly this uuid in the (already visible) conversation; anything else 404 (audited). */
    private function findMessage(Request $request, ChatClientCall $call, ChatConversation $conversation, string $messageUuid): ChatMessage
    {
        $message = $this->messages->findOneInConversation($conversation, $messageUuid);
        if (!$message instanceof ChatMessage || $message->getUuid() !== $messageUuid) {
            $this->guard->notFound($request, $call);
        }

        return $message;
    }

    /**
     * Reactions as the reader sees them: counts and whether they reacted, never who.
     *
     * @param array<string, list<string>> $reactions emoji => public ids
     *
     * @return list<array{emoji: string, count: int, mine: bool}>
     */
    private function reactionCounts(array $reactions, ChatUserInterface $user): array
    {
        $counts = [];
        foreach ($reactions as $emoji => $people) {
            $counts[] = ['emoji' => (string) $emoji, 'count' => count($people), 'mine' => in_array($user->getChatPublicId(), $people, true)];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * The conversations the caller may read now and that belong to the request's context, in order.
     *
     * @param list<ChatConversation> $conversations
     *
     * @return list<ChatConversation>
     */
    private function visible(ChatClientCall $call, array $conversations): array
    {
        return array_values(array_filter($conversations, fn (ChatConversation $conversation): bool => $this->isVisible($call, $conversation)));
    }

    private function isVisible(ChatClientCall $call, ChatConversation $conversation): bool
    {
        $user = $call->principal->user;
        if (!$this->voter->decide(ChatVoter::VIEW, $conversation, $user)) {
            return false;
        }
        $policy = $this->guard->contexts();
        if (!$policy instanceof ChatClientContextPolicyInterface || $call->contextKey === null) {
            return true;
        }
        $members = [];
        if ($conversation->isDirect()) {
            $peer    = $this->access->directPeer($conversation, $user);
            $members = array_values(array_unique([$user->getChatPublicId(), ...($peer instanceof ChatUserInterface ? [$peer->getChatPublicId()] : [])]));
        }

        return $policy->includes($call->principal, $call->contextKey, new ChatClientConversationRef(
            $conversation->getUuid(),
            $conversation->getKind()->value,
            $conversation->getChannelKey(),
            $members,
        ));
    }

    /** The conversation of exactly this uuid the caller may see in the request's context; anything else 404 (audited). */
    private function find(Request $request, ChatClientCall $call, string $uuid): ChatConversation
    {
        $conversation = $this->conversations->findOneByUuid($uuid);
        if (!$conversation instanceof ChatConversation || $conversation->getUuid() !== $uuid || !$this->isVisible($call, $conversation)) {
            $this->guard->notFound($request, $call);
        }

        return $conversation;
    }

    /**
     * Rows of the conversation list (and of the detail).
     *
     * @param list<ChatConversation> $rows
     * @param array<int, int> $unread
     * @param array<int, DateTimeImmutable> $muted
     *
     * @return list<array<string, mixed>>
     */
    private function presentList(ChatClientCall $call, array $rows, array $unread, array $muted, bool $archived): array
    {
        $user    = $call->principal->user;
        $isAgent = $this->access->isSupportAgent($user);
        $ids     = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $rows);
        $latest  = $this->messages->findLatestByConversationIds($ids);
        $peers   = [];
        foreach ($rows as $conversation) {
            $peer = $conversation->isDirect() ? $this->access->directPeer($conversation, $user) : null;
            if ($peer instanceof ChatUserInterface) {
                $peers[(int) $conversation->getId()] = $peer;
            }
        }
        $presence = $this->presence->statusOf(array_values($peers));
        $shows    = $this->settings->forUser($user)->isPresenceVisible();
        $people   = [];
        foreach ($rows as $conversation) {
            $people[] = $this->presenter->avatarPerson($conversation, $user, $peers[(int) $conversation->getId()] ?? null, $isAgent);
        }
        $this->presenter->primeAvatars($people);

        $out = [];
        foreach ($rows as $conversation) {
            $id     = (int) $conversation->getId();
            $peer   = $peers[$id] ?? null;
            $status = $peer instanceof ChatUserInterface ? ChatPresence::forViewer($presence[$peer->getChatPublicId()] ?? null, $shows) : null;
            $view   = $this->presenter->conversation(
                $conversation,
                $user,
                $peer,
                $conversation->isChannel() ? $this->access->channel((string) $conversation->getChannelKey()) : null,
                $this->voter->decide(ChatVoter::SEND, $conversation, $user),
                $unread[$id] ?? 0,
                $latest[$id] ?? null,
                $isAgent,
                $status !== null && $status['status'] !== 'offline',
                $status,
            );
            $ticket = is_array($view['ticket']) ? $view['ticket'] : null;
            $out[]  = [
                'uuid'            => $view['uuid'],
                'kind'            => $view['kind'],
                'title'           => $view['title'],
                'subtitle'        => $view['subtitle'],
                'initials'        => $view['initials'],
                'avatarUrl'       => $view['avatarUrl'],
                'peerUuid'        => $view['peerUuid'],
                'peerStatus'      => $view['peerStatus'],
                'peerStatusText'  => $view['peerStatusText'],
                'peerStatusEmoji' => $view['peerStatusEmoji'],
                'channelKey'      => $view['channelKey'],
                'ticket'          => $ticket === null ? null : [
                    'number'      => $ticket['number'],
                    'topicLabel'  => $ticket['topicLabel'],
                    'status'      => $ticket['status'],
                    'statusLabel' => $ticket['statusLabel'],
                    'readOnly'    => $ticket['readOnly'],
                ],
                'canSend'       => $view['canSend'],
                'unread'        => $view['unread'],
                'archived'      => $archived,
                'mutedUntil'    => isset($muted[$id]) ? $muted[$id]->format(DATE_ATOM) : null,
                'lastMessageAt' => $view['lastMessageAt'],
                'lastMessageId' => $view['lastMessageId'],
                'lastMessage'   => $view['lastMessage'],
            ];
        }

        return $out;
    }

    /**
     * Messages as the reader sees them: tokens, reactions (counts), and their link previews.
     *
     * @param list<ChatMessage> $page
     *
     * @return list<array<string, mixed>>
     */
    private function presentMessages(ChatUserInterface $user, array $page): array
    {
        $reactions = $this->reactions->summaryFor(array_map(static fn (ChatMessage $message): int => (int) $message->getId(), $page));
        $this->presenter->primeAvatars(array_map(static fn (ChatMessage $message): ?ChatUserInterface => $message->getAuthor(), $page));
        $this->presenter->primeAuthorBadges($page);

        $rows = [];
        $urls = [];
        foreach ($page as $message) {
            $view = $this->presenter->message($message, $user);
            $body = match (true) {
                $message->isDeleted()                   => null,
                $message->isBot(), $message->isSystem() => ChatMessageTokens::plain($this->presenter->botText($message)),
                default                                 => $this->tokens->of($message->getBody(), $user),
            };
            $links = $body === null ? [] : array_slice(ChatMessageTokens::previewUrls($body), 0, self::PREVIEWS_PER_MESSAGE);
            foreach ($links as $url) {
                $urls[$url] = $url;
            }
            $rows[] = [$this->messageRow($message, $view, $body, $reactions[(int) $message->getId()] ?? [], $user), $links];
        }
        $previews = $this->previews($user, array_slice(array_values($urls), 0, self::PREVIEWS_MAX));

        return array_map(static function (array $row) use ($previews): array {
            [$message, $links]   = $row;
            $message['previews'] = array_values(array_filter(array_map(
                static fn (string $url): ?array => isset($previews[$url]) ? ['url' => $url] + $previews[$url]->toArray() : null,
                $links,
            )));

            return $message;
        }, $rows);
    }

    /**
     * @param array<string, mixed> $view ChatPresenter::message()
     * @param array{format: string, blocks: list<array<string, mixed>>}|null $body
     * @param array<string, list<string>> $reactions emoji => public ids
     *
     * @return array<string, mixed>
     */
    private function messageRow(ChatMessage $message, array $view, ?array $body, array $reactions, ChatUserInterface $user): array
    {
        $counts = $this->reactionCounts($reactions, $user);
        $row    = [
            'id'     => $view['id'],
            'uuid'   => $view['uuid'],
            'kind'   => $view['kind'],
            'author' => [
                'uuid'      => $view['authorUuid'],
                'name'      => $view['authorName'],
                'initials'  => $view['authorInitials'],
                'avatarUrl' => $view['authorAvatarUrl'],
                'bot'       => $message->isBot(),
                'badges'    => $view['authorBadges'] ?? [],
            ],
            'mine'          => $view['mine'],
            'createdAt'     => $view['createdAt'],
            'edited'        => $view['edited'],
            'deleted'       => $view['deleted'],
            'moderated'     => $view['moderated'],
            'pinned'        => $view['pinned'],
            'pinnedMessage' => $view['pinnedMessage'],
            'mentionsMe'    => $view['mentionsMe'],
            'reactions'     => $counts,
            'body'          => $body,
        ];
        // `mentionAll` is the enum `channel` | `here` (anything else stored in a row's meta is left out).
        if (in_array($view['mentionAll'] ?? null, self::MENTION_ALL, true)) {
            $row['mentionAll'] = $view['mentionAll'];
        }
        foreach (['forwarded', 'threadRootUuid', 'thread'] as $optional) {
            if (array_key_exists($optional, $view)) {
                $row[$optional] = $view[$optional];
            }
        }

        return $row;
    }

    /**
     * Link previews for the reader (the provider filters what the reader may see), 10 URLs per call.
     *
     * @param list<string> $urls
     *
     * @return array<string, ChatLinkPreview>
     */
    private function previews(ChatUserInterface $user, array $urls): array
    {
        $previews = [];
        foreach (array_chunk($urls, 10) as $chunk) {
            // A provider answering more keys than asked never leaks them.
            foreach (array_intersect_key($this->linkPreviews->previews($user, $chunk), array_flip($chunk)) as $url => $preview) {
                $previews[(string) $url] = $preview;
            }
        }

        return $previews;
    }

    /**
     * Conversations the user closed and that stay closed now, and those muted now, in one query.
     *
     * @param list<int> $ids
     *
     * @return array{array<int, true>, array<int, DateTimeImmutable>}
     */
    private function participantStates(ChatUserInterface $user, array $ids): array
    {
        $now    = $this->clock->now();
        $closed = [];
        $muted  = [];
        foreach ($ids === [] ? [] : $this->participants->findForUser($user, $ids) as $id => $participant) {
            if ($participant->isArchived($now)) {
                $closed[$id] = true;
            }
            $mutedUntil = $participant->getMutedUntil();
            if ($mutedUntil instanceof DateTimeImmutable && $participant->isMuted($now)) {
                $muted[$id] = $mutedUntil;
            }
        }

        return [$closed, $muted];
    }

    private function limit(Request $request, int $default, int $max): int
    {
        $value = $request->query->get('limit');
        if ($value === null) {
            return $default;
        }
        $this->deny(!is_string($value) || preg_match('/^[1-9]\d{0,2}$/D', $value) !== 1 || (int) $value > $max, 'invalid_limit', Response::HTTP_BAD_REQUEST);

        return (int) $value;
    }

    private function messageId(Request $request, string $name): ?int
    {
        $value = $request->query->get($name);
        if ($value === null) {
            return null;
        }
        $this->deny(!is_string($value) || preg_match('/^[1-9]\d{0,17}$/D', $value) !== 1, 'invalid_cursor', Response::HTTP_BAD_REQUEST);

        return (int) $value;
    }

    /**
     * The JSON object of the body (at most 4 KB, 2.5: 48 KB for a write); 400 `invalid_json` otherwise.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request, int $maxBytes = self::MAX_JSON_BODY_BYTES): array
    {
        $content = $request->getContent();
        $this->deny(strlen($content) > $maxBytes, 'invalid_json', Response::HTTP_BAD_REQUEST);
        try {
            $data = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ChatApiException('invalid_json', Response::HTTP_BAD_REQUEST);
        }
        $this->deny(!is_array($data) || ($data !== [] && array_is_list($data)), 'invalid_json', Response::HTTP_BAD_REQUEST);

        /* @var array<string, mixed> $data */
        return $data;
    }

    private function deny(bool $condition, string $error, int $status): void
    {
        if ($condition) {
            throw new ChatApiException($error, $status);
        }
    }
}
