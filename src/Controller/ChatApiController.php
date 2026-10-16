<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatContact;
use Nowo\ChatKitBundle\Model\ChatLinkPreview;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
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
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatSupportInbox;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

use function array_slice;
use function assert;
use function count;
use function is_array;
use function is_string;
use function strlen;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * JSON API of the floating chat. Every mutation needs the `nowo_chat` CSRF header token; every conversation
 * and message is resolved by UUID and checked with ChatVoter (unknown or foreign → 404, never 403, so UUIDs
 * cannot be probed). Responses are never cached.
 */
#[Route('/api', name: 'nowo_chat_api_')]
final class ChatApiController extends AbstractController
{
    public const string CSRF_ID           = 'nowo_chat';
    public const string UUID              = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    private const int MAX_JSON_BODY_BYTES = 32_768;
    private const int LIST_LIMIT          = 50;
    private const int PAGE_SIZE           = 30;
    private const int CONTACTS_LIMIT      = 30;

    /**
     * Builds the API controller from the chat services, repositories, realtime helpers and the rate limiters
     * (send, action, typing, buzz per conversation, buzz per minute, read).
     */
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatPresenter $presenter,
        private readonly ChatAccess $access,
        private readonly ChatDirectoryInterface $directory,
        private readonly ChatChannelProviderInterface $channels,
        private readonly ChatConversationRepository $conversations,
        private readonly ChatMessageRepository $messages,
        private readonly ChatParticipantRepository $participants,
        private readonly ChatReactionRepository $reactions,
        private readonly ChatUserSettingsRepository $settings,
        private readonly ChatNotifier $notifier,
        private readonly ChatPresence $presence,
        private readonly ChatRecipients $recipients,
        private readonly ChatRealtimeInterface $realtime,
        private readonly ChatSupportInbox $supportInbox,
        private readonly ChatLinkPreviewProviderInterface $linkPreviews,
        #[Autowire(service: 'limiter.nowo_chat_send')]
        private readonly RateLimiterFactoryInterface $sendLimiter,
        #[Autowire(service: 'limiter.nowo_chat_action')]
        private readonly RateLimiterFactoryInterface $actionLimiter,
        #[Autowire(service: 'limiter.nowo_chat_typing')]
        private readonly RateLimiterFactoryInterface $typingLimiter,
        #[Autowire(service: 'limiter.nowo_chat_buzz')]
        private readonly RateLimiterFactoryInterface $buzzLimiter,
        #[Autowire(service: 'limiter.nowo_chat_buzz_global')]
        private readonly RateLimiterFactoryInterface $buzzGlobalLimiter,
        #[Autowire(service: 'limiter.nowo_chat_read')]
        private readonly RateLimiterFactoryInterface $readLimiter,
    ) {
    }

    /**
     * Lists the caller's conversations (newest activity first) with unread counts, last messages and peer
     * presence, plus the total unread count.
     *
     * @return JsonResponse `{conversations, unreadTotal}`
     */
    #[Route('/conversations', name: 'conversations', methods: ['GET'])]
    public function conversations(): JsonResponse
    {
        $user        = $this->reader();
        $channelKeys = array_map(static fn (ChatChannel $channel): string => $channel->key, $this->channels->channelsFor($user));
        // Participant rows outlive access (left channel, demoted agent): list only what the user may read today.
        $list = array_values(array_filter(
            $this->conversations->findListForUser($user, $channelKeys, $this->access->isSupportAgent($user), self::LIST_LIMIT),
            fn (ChatConversation $conversation): bool => $this->access->canView($conversation, $user),
        ));
        $ids    = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $list);
        $unread = $this->messages->countUnreadByConversation((int) $user->getId(), $ids);
        $latest = $this->messages->findLatestByConversationIds($ids);
        // Peers of direct threads in one query and their presence in one cache round trip.
        $peerIds = [];
        foreach ($list as $conversation) {
            $peerIds = [...$peerIds, ...$conversation->directUserIds()];
        }
        $peers = [];
        foreach ($this->directory->findByIds(array_values(array_unique($peerIds))) as $peer) {
            $peers[(int) $peer->getId()] = $peer;
        }
        $presence = $this->showsPresence($user) ? $this->presence->statusOf(array_values($peers)) : [];

        $rows = [];
        foreach ($list as $conversation) {
            $id     = (int) $conversation->getId();
            $peerId = $conversation->peerIdOf($user);
            $rows[] = $this->present($conversation, $user, $peerId === null ? null : ($peers[$peerId] ?? null), $unread[$id] ?? 0, $latest[$id] ?? null, $presence);
        }

        return $this->json(['conversations' => $rows, 'unreadTotal' => array_sum($unread)]);
    }

    /**
     * People the caller may start a direct conversation with (`?q=` search, at most 80 characters).
     *
     * @param Request $request The request (query `q`)
     *
     * @return JsonResponse `{contacts}`
     */
    #[Route('/contacts', name: 'contacts', methods: ['GET'])]
    public function contacts(Request $request): JsonResponse
    {
        $user     = $this->reader();
        $query    = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);
        $contacts = array_values(array_filter(
            $this->directory->contactsFor($user, $query === '' ? null : $query, self::CONTACTS_LIMIT),
            fn (ChatContact $contact): bool => $this->access->canOpenDirect($user, $contact->user),
        ));

        return $this->json([
            'contacts' => array_map(static fn (ChatContact $contact): array => [
                'uuid'     => $contact->user->getChatPublicId(),
                'name'     => $contact->user->getChatDisplayName(),
                'email'    => $contact->user->getChatEmail(),
                'initials' => $contact->user->getChatInitials(),
                'source'   => $contact->source,
                'via'      => $contact->via,
            ], $contacts),
        ]);
    }

    /**
     * Opens (or creates) the direct conversation with the user of the posted `userUuid`; 404 when not allowed.
     *
     * @param Request $request JSON body `{userUuid}`
     *
     * @return JsonResponse `{conversation}`
     */
    #[Route('/direct', name: 'open_direct', methods: ['POST'])]
    public function openDirect(Request $request): JsonResponse
    {
        $user  = $this->writer($request);
        $other = $this->directory->findByPublicId((string) ($this->payload($request)['userUuid'] ?? ''));
        if (!$other instanceof ChatUserInterface || !$this->access->canOpenDirect($user, $other)) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }

        return $this->json(['conversation' => $this->present($this->chat->openDirect($user, $other), $user)]);
    }

    /**
     * Opens (or creates) the conversation of the posted `channelKey`; 404 when the channel is unknown or not usable.
     *
     * @param Request $request JSON body `{channelKey}`
     *
     * @return JsonResponse `{conversation}`
     */
    #[Route('/channel', name: 'open_channel', methods: ['POST'])]
    public function openChannel(Request $request): JsonResponse
    {
        $user    = $this->writer($request);
        $key     = (string) ($this->payload($request)['channelKey'] ?? '');
        $channel = $this->access->channel($key);
        if (!$channel instanceof ChatChannel || !$this->access->canUseChannel($user, $key)) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }

        return $this->json(['conversation' => $this->present($this->chat->openChannel($user, $channel), $user)]);
    }

    /**
     * Opens (or creates) the caller's support thread, recording the optional `context` (`url`, `label`).
     *
     * @param Request $request JSON body `{context?}`
     *
     * @return JsonResponse `{conversation}`
     */
    #[Route('/support', name: 'open_support', methods: ['POST'])]
    public function openSupport(Request $request): JsonResponse
    {
        $user = $this->writer($request);

        $context = $this->payload($request)['context'] ?? null;

        return $this->json(['conversation' => $this->present($this->chat->openSupport($user, is_array($context) ? $context : null), $user)]);
    }

    /**
     * Previews of the given links for the caller (`?url[]=…`, at most 10; invisible ones are left out).
     *
     * @param Request $request The request (query `url[]`)
     *
     * @return JsonResponse `{previews}` keyed by URL
     */
    #[Route('/link-previews', name: 'link_previews', methods: ['GET'])]
    public function linkPreviews(Request $request): JsonResponse
    {
        $user  = $this->reader();
        $urls  = [];
        $given = $request->query->all()['url'] ?? [];
        foreach (is_array($given) ? $given : [] as $url) {
            if (is_string($url) && strlen($url) <= 500 && preg_match('~^(/(?!/)|https?://)~i', $url) === 1) {
                $urls[$url] = $url;
            }
        }
        $urls     = array_slice(array_values($urls), 0, 10);
        $previews = $urls === [] ? [] : $this->linkPreviews->previews($user, $urls);

        return $this->json(['previews' => array_map(static fn (ChatLinkPreview $preview): array => $preview->toArray(), array_intersect_key($previews, array_flip($urls)))]);
    }

    /**
     * Support agents' inbox (`?status=open|resolved|mine|all&q=`); 404 for everyone else.
     *
     * @param Request $request The request (query `status`, `q`)
     *
     * @return JsonResponse `{threads, open}`
     */
    #[Route('/support/inbox', name: 'support_inbox', methods: ['GET'])]
    public function supportInbox(Request $request): JsonResponse
    {
        $user = $this->reader();
        $this->deny(!$this->access->isSupportAgent($user), 'not_found', Response::HTTP_NOT_FOUND);
        $query = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);

        return $this->json([
            'threads' => $this->supportInbox->list($user, (string) $request->query->get('status', 'open'), $query === '' ? null : $query),
            'open'    => $this->supportInbox->countOpen(),
        ]);
    }

    /**
     * One conversation summary as the caller sees it (404 when not visible).
     *
     * @param string $uuid Conversation uuid
     *
     * @return JsonResponse `{conversation}`
     */
    #[Route('/conversations/{uuid}', name: 'conversation', requirements: ['uuid' => self::UUID], methods: ['GET'])]
    public function conversation(string $uuid): JsonResponse
    {
        $user         = $this->reader();
        $conversation = $this->find($uuid, ChatVoter::VIEW);
        $id           = (int) $conversation->getId();

        return $this->json(['conversation' => $this->present(
            $conversation,
            $user,
            $this->access->directPeer($conversation, $user),
            $this->messages->countUnreadByConversation((int) $user->getId(), [$id])[$id] ?? 0,
            $this->messages->findLatest($conversation),
        )]);
    }

    /**
     * A page of messages (`?before=` older page, `?after=` catch-up) with reactions, the caller's read cursor
     * and the other readers' cursors.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The request (query `before`, `after`)
     *
     * @return JsonResponse `{messages, hasMore, readUpTo, readers}`
     */
    #[Route('/conversations/{uuid}/messages', name: 'messages', requirements: ['uuid' => self::UUID], methods: ['GET'])]
    public function messages(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->reader();
        $conversation = $this->find($uuid, ChatVoter::VIEW);
        $before       = $request->query->getInt('before') ?: null;
        $after        = $request->query->getInt('after') ?: null;
        $page         = $this->messages->findPage($conversation, self::PAGE_SIZE + 1, $before, $after);
        $hasMore      = count($page) > self::PAGE_SIZE;
        if ($hasMore) {
            // Older pages drop their oldest extra row; catch-up pages their newest.
            $page = $after === null ? array_slice($page, 1) : array_slice($page, 0, self::PAGE_SIZE);
        }
        $reactions = $this->reactions->summaryFor(array_map(static fn (ChatMessage $message): int => (int) $message->getId(), $page));

        return $this->json([
            'messages' => array_map(fn (ChatMessage $message): array => $this->presenter->message($message, $user, $reactions[(int) $message->getId()] ?? []), $page),
            'hasMore'  => $hasMore,
            'readUpTo' => $this->participants->findOneBy(['conversation' => $conversation, 'user' => $user])?->getLastReadMessageId(),
            // Other people's read cursors (✓✓ / "Seen by N").
            'readers' => array_map(static fn (ChatParticipant $participant): array => [
                'userUuid'  => $participant->getUser()->getChatPublicId(),
                'name'      => $participant->getUser()->getChatDisplayName(),
                'messageId' => $participant->getLastReadMessageId(),
            ], $this->currentReaders($conversation, $user)),
        ]);
    }

    /**
     * Sends a message (rate limited); 403 `read_only` when the caller may not write, 422 for an empty or too long body.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request JSON body `{body}`
     *
     * @return JsonResponse `{message}` with status 201
     */
    #[Route('/conversations/{uuid}/messages', name: 'send', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function send(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->writer($request);
        $conversation = $this->find($uuid, ChatVoter::VIEW);
        $this->deny(!$this->isGranted(ChatVoter::SEND, $conversation), 'read_only', Response::HTTP_FORBIDDEN);
        $this->deny(!$this->sendLimiter->create('nowo_chat_send_' . $user->getChatPublicId())->consume()->isAccepted(), 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        try {
            $message = $this->chat->send($user, $conversation, (string) ($this->payload($request)['body'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json(['message' => $this->presenter->message($message, $user)], Response::HTTP_CREATED);
    }

    /**
     * Edits one of the caller's messages within the edit window; 422 for an empty or too long body.
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid Message uuid
     * @param Request $request JSON body `{body}`
     *
     * @return JsonResponse `{message}`
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}', name: 'edit', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['PATCH'])]
    public function edit(string $uuid, string $messageUuid, Request $request): JsonResponse
    {
        $user    = $this->writer($request);
        $message = $this->findMessage($uuid, $messageUuid, ChatVoter::EDIT);
        try {
            $this->chat->edit($message, (string) ($this->payload($request)['body'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json(['message' => $this->presenter->message($message, $user, $this->reactionsOf($message))]);
    }

    /**
     * Deletes a message (own message within the window, or moderation by a support agent).
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid Message uuid
     * @param Request $request The request (CSRF header)
     *
     * @return JsonResponse `{message}` (the tombstone)
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}', name: 'delete', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['DELETE'])]
    public function delete(string $uuid, string $messageUuid, Request $request): JsonResponse
    {
        $user    = $this->writer($request);
        $message = $this->findMessage($uuid, $messageUuid, ChatVoter::DELETE);
        $this->chat->delete($user, $message);

        return $this->json(['message' => $this->presenter->message($message, $user)]);
    }

    /**
     * Toggles the caller's reaction with the posted emoji; 422 when the emoji is not offered.
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid Message uuid
     * @param Request $request JSON body `{emoji}`
     *
     * @return JsonResponse `{reactions}` after the change
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}/reactions', name: 'react', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['POST'])]
    public function react(string $uuid, string $messageUuid, Request $request): JsonResponse
    {
        $user    = $this->writer($request);
        $message = $this->findMessage($uuid, $messageUuid, ChatVoter::REACT);
        try {
            $reactions = $this->chat->toggleReaction($user, $message, (string) ($this->payload($request)['emoji'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json(['reactions' => $reactions]);
    }

    /**
     * Moves the caller's read cursor to the posted `messageId`, capped at the conversation's newest message.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request JSON body `{messageId}`
     *
     * @return JsonResponse `{ok: true}`
     */
    #[Route('/conversations/{uuid}/read', name: 'read', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function read(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->writer($request);
        $conversation = $this->find($uuid, ChatVoter::VIEW);
        $latest       = $this->messages->findLatest($conversation);
        $requested    = (int) ($this->payload($request)['messageId'] ?? 0);
        // Never beyond the newest message of this conversation (ids are global).
        $upTo = $latest instanceof ChatMessage ? min($requested, (int) $latest->getId()) : 0;
        if ($upTo > 0) {
            $this->chat->markRead($user, $conversation, $upTo);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Hub URL and a short-lived subscriber token for the caller's own topic only.
     *
     * @param Request $request The request (to build the browser hub URL)
     *
     * @return JsonResponse `{enabled, hubUrl, token, topic, expiresAt}`
     */
    #[Route('/realtime', name: 'realtime', methods: ['GET'])]
    public function realtime(Request $request): JsonResponse
    {
        $user  = $this->reader();
        $topic = ChatTopics::forUser($user->getChatPublicId());
        // Short-lived (it may travel in the hub URL and end up in access logs); the client refreshes it.
        $expiresAt = new DateTimeImmutable('+15 minutes');
        $enabled   = $this->realtime->isEnabled();
        $token     = $enabled ? $this->realtime->subscriberToken([$topic], $expiresAt) : null;
        $hubUrl    = $enabled ? $this->realtime->browserHubUrl($request) : null;

        return $this->json([
            'enabled'   => $token !== null && $hubUrl !== null,
            'hubUrl'    => $hubUrl,
            'token'     => $token,
            'topic'     => $topic,
            'expiresAt' => $expiresAt->format(DATE_ATOM),
        ]);
    }

    /**
     * Tab heartbeat; answers who of the caller's direct contacts is online (reciprocal opt-out).
     *
     * @param Request $request JSON body `{visible}`
     *
     * @return JsonResponse `{online}` public ids of online peers
     */
    #[Route('/presence', name: 'presence', methods: ['POST'])]
    public function presence(Request $request): JsonResponse
    {
        $user = $this->writer($request);
        $this->presence->heartbeat($user, true === ($this->payload($request)['visible'] ?? false));
        $online = [];
        if ($this->showsPresence($user)) {
            $peerIds = $this->conversations->findDirectPeerIds($user);
            $peers   = array_values(array_filter($peerIds === [] ? [] : $this->directory->findByIds($peerIds), $this->access->isReachable(...)));
            foreach ($this->presence->statusOf($peers) as $publicId => $status) {
                if ($status['online']) {
                    $online[] = $publicId;
                }
            }
        }

        return $this->json(['online' => $online]);
    }

    /**
     * "Is typing" — ephemeral, never stored.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The request (CSRF header)
     *
     * @return JsonResponse `{ok: true}`, also when the ping is dropped by the rate limiter
     */
    #[Route('/conversations/{uuid}/typing', name: 'typing', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function typing(string $uuid, Request $request): JsonResponse
    {
        $user = $this->chatUser();
        $this->checkCsrf($request);
        // Limiter before the voter: over the limit the ping is dropped without touching the database.
        if (!$this->typingLimiter->create('nowo_chat_typing_' . $user->getChatPublicId())->consume()->isAccepted()) {
            return $this->json(['ok' => true]);
        }
        $conversation = $this->find($uuid, ChatVoter::SEND);
        $this->notifier->typing($conversation, $user);

        return $this->json(['ok' => true]);
    }

    /**
     * Buzz: direct conversations only, one per conversation and sender per interval, capped per minute.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The request (CSRF header)
     *
     * @return JsonResponse `{message}` (the buzz) with status 201
     */
    #[Route('/conversations/{uuid}/buzz', name: 'buzz', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function buzz(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->writer($request);
        $conversation = $this->find($uuid, ChatVoter::VIEW);
        $this->deny(!$this->isGranted(ChatVoter::SEND, $conversation), 'read_only', Response::HTTP_FORBIDDEN);
        // A buzz is a person-to-person nudge: never a channel- or support-wide alarm.
        $this->deny(!$conversation->isDirect(), 'buzz_direct_only', Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->deny(
            !$this->buzzGlobalLimiter->create('nowo_chat_buzz_global_' . $user->getChatPublicId())->consume()->isAccepted()
            || !$this->buzzLimiter->create('nowo_chat_buzz_' . $user->getChatPublicId() . '_' . $conversation->getUuid())->consume()->isAccepted(),
            'buzz_too_soon',
            Response::HTTP_TOO_MANY_REQUESTS,
        );

        return $this->json(['message' => $this->presenter->message($this->chat->buzz($user, $conversation), $user)], Response::HTTP_CREATED);
    }

    /**
     * A support agent marks a support thread resolved (the owner's next message reopens it).
     *
     * @param string $uuid Support thread uuid
     * @param Request $request The request (CSRF header)
     *
     * @return JsonResponse `{conversation}`
     */
    #[Route('/conversations/{uuid}/resolve', name: 'resolve', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function resolve(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->writer($request);
        $conversation = $this->find($uuid, ChatVoter::RESOLVE);
        $this->chat->resolveSupport($user, $conversation);

        return $this->json(['conversation' => $this->present($conversation, $user)]);
    }

    /**
     * A support agent reopens a resolved support thread.
     *
     * @param string $uuid Support thread uuid
     * @param Request $request The request (CSRF header)
     *
     * @return JsonResponse `{conversation}`
     */
    #[Route('/conversations/{uuid}/reopen', name: 'reopen', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function reopen(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->writer($request);
        $conversation = $this->find($uuid, ChatVoter::RESOLVE);
        $this->chat->reopenSupport($user, $conversation);

        return $this->json(['conversation' => $this->present($conversation, $user)]);
    }

    /**
     * Chat data is private and changes constantly: never cached by the browser, a proxy or a service worker.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $context
     */
    protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @param array<string, array{online: bool, appOpen: bool}>|null $presence precomputed by the list endpoint
     *
     * @return array<string, mixed>
     */
    private function present(ChatConversation $conversation, ChatUserInterface $user, ?ChatUserInterface $peer = null, int $unread = 0, ?ChatMessage $last = null, ?array $presence = null): array
    {
        $peer ??= $this->access->directPeer($conversation, $user);
        $online = false;
        if ($peer instanceof ChatUserInterface && $this->showsPresence($user)) {
            $presence ??= $this->presence->statusOf([$peer]);
            $online = $presence[$peer->getChatPublicId()]['online'] ?? false;
        }

        return $this->presenter->conversation(
            $conversation,
            $user,
            $peer,
            $conversation->isChannel() ? $this->access->channel((string) $conversation->getChannelKey()) : null,
            $this->isGranted(ChatVoter::SEND, $conversation),
            $unread,
            $last,
            $this->access->isSupportAgent($user),
            $online,
        );
    }

    /** Reciprocal: hiding your own presence also hides everyone else's. */
    private function showsPresence(ChatUserInterface $user): bool
    {
        return $this->settings->forUser($user)->isPresenceVisible();
    }

    /**
     * Read cursors of people who can still see the conversation (former members and unreachable accounts are
     * left out).
     *
     * @return list<ChatParticipant>
     */
    private function currentReaders(ChatConversation $conversation, ChatUserInterface $user): array
    {
        $allowed = [];
        foreach ($this->recipients->of($conversation) as $recipient) {
            $allowed[(int) $recipient->getId()] = true;
        }

        return array_values(array_filter(
            $this->participants->findReaders($conversation, $user),
            static fn (ChatParticipant $participant): bool => isset($allowed[(int) $participant->getUser()->getId()]),
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    private function reactionsOf(ChatMessage $message): array
    {
        return $this->reactions->summaryFor([(int) $message->getId()])[(int) $message->getId()] ?? [];
    }

    private function chatUser(): ChatUserInterface
    {
        $user = $this->getUser();
        $this->deny(!$user instanceof ChatUserInterface || !$this->access->isReachable($user), 'unauthorized', Response::HTTP_UNAUTHORIZED);
        assert($user instanceof ChatUserInterface);

        return $user;
    }

    /** Signed-in user within the read budget. */
    private function reader(): ChatUserInterface
    {
        $user = $this->chatUser();
        $this->deny(!$this->readLimiter->create('nowo_chat_read_' . $user->getChatPublicId())->consume()->isAccepted(), 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);

        return $user;
    }

    /** Signed-in user with a valid CSRF header, within the action budget. */
    private function writer(Request $request): ChatUserInterface
    {
        $user = $this->chatUser();
        $this->checkCsrf($request);
        $this->deny(!$this->actionLimiter->create('nowo_chat_action_' . $user->getChatPublicId())->consume()->isAccepted(), 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);

        return $user;
    }

    private function checkCsrf(Request $request): void
    {
        $this->deny(!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-TOKEN', '')), 'invalid_csrf', Response::HTTP_FORBIDDEN);
    }

    /** Unknown, foreign or forbidden → 404 (never 403, so UUIDs cannot be probed). */
    private function find(string $uuid, string $attribute): ChatConversation
    {
        $conversation = $this->conversations->findOneByUuid($uuid);
        if (!$conversation instanceof ChatConversation || !$this->isGranted($attribute, $conversation)) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }

        return $conversation;
    }

    private function findMessage(string $uuid, string $messageUuid, string $attribute): ChatMessage
    {
        $message = $this->messages->findOneInConversation($this->find($uuid, ChatVoter::VIEW), $messageUuid);
        if (!$message instanceof ChatMessage || !$this->isGranted($attribute, $message)) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }

        return $message;
    }

    private function deny(bool $condition, string $error, int $status): void
    {
        if ($condition) {
            throw new ChatApiException($error, $status);
        }
    }

    /**
     * JSON body, bounded (oversized or malformed bodies count as empty).
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ((int) $request->headers->get('Content-Length', '0') > self::MAX_JSON_BODY_BYTES) {
            return [];
        }
        $content = $request->getContent();
        if (strlen($content) > self::MAX_JSON_BODY_BYTES) {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
