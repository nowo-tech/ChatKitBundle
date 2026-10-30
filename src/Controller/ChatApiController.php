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
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatContact;
use Nowo\ChatKitBundle\Model\ChatLinkPreview;
use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Realtime\ChatTopics;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatQuickReplyRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatSupportInbox;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
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
    private const int MEMBERS_LIMIT       = 20;

    /**
     * Builds the API controller from the chat services, repositories, realtime helpers and the rate limiters
     * (send, action, typing, buzz per conversation, buzz per minute, read, reference search).
     *
     * @param ClockInterface $clock Clock for the realtime token expiry
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
        private readonly ChatSupportTickets $tickets,
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
        #[Autowire(service: 'limiter.nowo_chat_reference')]
        private readonly RateLimiterFactoryInterface $referenceLimiter,
        private readonly ChatReferences $references,
        private readonly ClockInterface $clock = new Clock(),
        private readonly ?ChatQuickReplyRepository $quickReplies = null,
    ) {
    }

    /**
     * Lists the caller's conversations (newest activity first) with unread counts, last messages and peer
     * presence, plus the total unread count. 1.10: conversations the user closed are left out (`?archived=1` lists only
     * them); a closed one is back as soon as a message arrives, unless muted or a resolved / closed ticket.
     *
     * @param Request $request The request (query `archived`)
     *
     * @return JsonResponse `{conversations, unreadTotal}`
     */
    #[Route('/conversations', name: 'conversations', methods: ['GET'])]
    public function conversations(Request $request): JsonResponse
    {
        $user        = $this->reader();
        $archived    = $request->query->getBoolean('archived');
        $channelKeys = array_map(static fn (ChatChannel $channel): string => $channel->key, $this->channels->channelsFor($user));
        // Participant rows outlive access (left channel, demoted agent): list only what the user may read today.
        $list = array_values(array_filter(
            $this->conversations->findListForUser($user, $channelKeys, $this->access->isSupportAgent($user), self::LIST_LIMIT),
            fn (ChatConversation $conversation): bool => $this->access->canView($conversation, $user),
        ));
        $ids    = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $list);
        $unread = $this->messages->countUnreadByConversation((int) $user->getId(), $ids);
        $closed = $this->closedIds($user, $ids);
        $list   = array_values(array_filter($list, static fn (ChatConversation $conversation): bool => isset($closed[(int) $conversation->getId()]) === $archived));
        $ids    = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $list);
        $latest = $this->messages->findLatestByConversationIds($ids);
        // Peers of direct threads in one query (memoized in ChatAccess, so the per-row SEND vote reuses them) and
        // their presence and status in one cache round trip (the custom text shows even to viewers who hide presence).
        $peerIds = [];
        foreach ($list as $conversation) {
            $peerIds = [...$peerIds, ...$conversation->directUserIds()];
        }
        $peers    = $this->access->usersByIds(array_values(array_unique($peerIds)));
        $presence = $this->presence->statusOf(array_values($peers));

        $rows = [];
        foreach ($list as $conversation) {
            $id     = (int) $conversation->getId();
            $peerId = $conversation->peerIdOf($user);
            $rows[] = ['archived' => $archived] + $this->present($conversation, $user, $peerId === null ? null : ($peers[$peerId] ?? null), $unread[$id] ?? 0, $latest[$id] ?? null, $presence);
        }

        return $this->json(['conversations' => $rows, 'unreadTotal' => array_sum($unread)]);
    }

    /**
     * 1.10: closes (archives) the conversation in the caller's list only — nothing is deleted, the others keep it; or
     * reopens it. The caller's other tabs refresh their list.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The request (CSRF header)
     *
     * @return JsonResponse `{archived}`
     */
    #[Route('/conversations/{uuid}/archive', name: 'archive', requirements: ['uuid' => self::UUID], methods: ['POST', 'DELETE'])]
    public function archive(string $uuid, Request $request): JsonResponse
    {
        $user     = $this->writer($request);
        $archived = $request->isMethod('POST');
        $this->chat->archive($user, [$this->find($uuid, ChatVoter::VIEW)], $archived);
        $this->notifier->listChanged($user);

        return $this->json(['archived' => $archived]);
    }

    /**
     * 1.10: the conversation's pinned messages (latest pin first) and whether the caller may pin; same access as
     * reading it (unknown or foreign → 404).
     *
     * @param string $uuid Conversation uuid
     *
     * @return JsonResponse `{pins: list<message>, canPin}`
     */
    #[Route('/conversations/{uuid}/pins', name: 'pins', requirements: ['uuid' => self::UUID], methods: ['GET'])]
    public function pins(string $uuid): JsonResponse
    {
        $user         = $this->reader();
        $conversation = $this->find($uuid, ChatVoter::VIEW);

        return $this->json($this->pinsOf($conversation, $user));
    }

    /**
     * 1.10: pins a message (`POST`) or unpins it (`DELETE`): both people of a direct conversation, agents in tickets,
     * whom the application allows in channels (403 `pin_forbidden` otherwise); a "pinned a message" line follows and
     * every reader's pinned bar refreshes.
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid Message uuid
     * @param Request $request The request (CSRF header)
     *
     * @return JsonResponse `{pins, canPin}`
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}/pin', name: 'pin', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['POST', 'DELETE'])]
    public function pin(string $uuid, string $messageUuid, Request $request): JsonResponse
    {
        $user    = $this->writer($request);
        $message = $this->messages->findOneInConversation($this->find($uuid, ChatVoter::VIEW), $messageUuid);
        $this->deny(!$message instanceof ChatMessage, 'not_found', Response::HTTP_NOT_FOUND);
        assert($message instanceof ChatMessage);
        $this->deny(!$this->access->canPin($message->getConversation(), $user), 'pin_forbidden', Response::HTTP_FORBIDDEN);
        try {
            $this->chat->pin($user, $message, $request->isMethod('POST'));
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json($this->pinsOf($message->getConversation(), $user));
    }

    /**
     * 1.10: "Close all read" — closes every conversation of the caller's list without unread messages.
     *
     * @param Request $request The request (CSRF header)
     *
     * @return JsonResponse `{archived: count}`
     */
    #[Route('/conversations/archive-read', name: 'archive_read', methods: ['POST'])]
    public function archiveRead(Request $request): JsonResponse
    {
        $user        = $this->writer($request);
        $channelKeys = array_map(static fn (ChatChannel $channel): string => $channel->key, $this->channels->channelsFor($user));
        $list        = array_values(array_filter(
            $this->conversations->findListForUser($user, $channelKeys, $this->access->isSupportAgent($user), self::LIST_LIMIT),
            fn (ChatConversation $conversation): bool => $this->access->canView($conversation, $user),
        ));
        $ids    = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $list);
        $unread = $this->messages->countUnreadByConversation((int) $user->getId(), $ids);
        $closed = $this->closedIds($user, $ids);
        $read   = array_values(array_filter($list, static fn (ChatConversation $conversation): bool => ($unread[(int) $conversation->getId()] ?? 0) === 0 && !isset($closed[(int) $conversation->getId()])));
        $this->chat->archive($user, $read, true);
        $this->notifier->listChanged($user);

        return $this->json(['archived' => count($read)]);
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
     * Opens support for the caller: the active ticket, or the intake draft (its summary carries `intake`: pick a topic
     * or "continue the last ticket or open a new one?"). Records the optional `context` (`url`, `label`).
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

        return $this->json(['conversation' => $this->present($this->tickets->open($user, is_array($context) ? $context : null), $user)]);
    }

    /**
     * The caller's tickets, newest activity first; an agent may pass `?owner=<public id>` for a requester's tickets.
     *
     * @param Request $request The request (query `owner`)
     *
     * @return JsonResponse `{tickets}`
     */
    #[Route('/support/tickets', name: 'support_tickets', methods: ['GET'])]
    public function supportTickets(Request $request): JsonResponse
    {
        $user  = $this->reader();
        $owner = $user;
        $asked = (string) $request->query->get('owner', '');
        if ($asked !== '' && $asked !== $user->getChatPublicId()) {
            $owner = $this->access->isSupportAgent($user) ? $this->directory->findByPublicId($asked) : null;
            $this->deny(!$owner instanceof ChatUserInterface, 'not_found', Response::HTTP_NOT_FOUND);
            assert($owner instanceof ChatUserInterface);
        }

        return $this->json(['tickets' => $this->tickets->tickets($owner)]);
    }

    /**
     * Chooses the topic: the requester's draft becomes a ticket (number, automatic replies); an agent changes the
     * topic of a ticket. Unknown topic → 422 `topic`.
     *
     * @param string $uuid Draft or ticket uuid
     * @param Request $request JSON body `{topic}`
     *
     * @return JsonResponse `{conversation, messages}` (the bot lines posted)
     */
    #[Route('/support/{uuid}/topic', name: 'support_topic', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function supportTopic(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->writer($request);
        $conversation = $this->findSupport($uuid);
        $this->deny(
            $conversation->isSupportDraft() ? !$this->owns($conversation, $user) : !$this->access->isSupportAgent($user),
            'not_found',
            Response::HTTP_NOT_FOUND,
        );
        try {
            $posted = $this->tickets->chooseTopic($user, $conversation, (string) ($this->payload($request)['topic'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json([
            'conversation' => $this->present($conversation, $user),
            'messages'     => array_map(fn (ChatMessage $message): array => $this->presenter->message($message, $user), $posted),
        ]);
    }

    /**
     * The requester continues an earlier ticket (`uuid`); the messages of their draft (`draft`, optional) move into it
     * and it goes back to open. Read-only ticket → 422 `read_only`.
     *
     * @param string $uuid Ticket uuid
     * @param Request $request JSON body `{draft?}`
     *
     * @return JsonResponse `{conversation}` (the ticket)
     */
    #[Route('/support/{uuid}/resume', name: 'support_resume', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function supportResume(string $uuid, Request $request): JsonResponse
    {
        $user   = $this->writer($request);
        $ticket = $this->findSupport($uuid);
        $this->deny(!$ticket->isTicket() || !$this->owns($ticket, $user), 'not_found', Response::HTTP_NOT_FOUND);
        $draft = $this->conversations->findOneByUuid((string) ($this->payload($request)['draft'] ?? ''));
        try {
            $this->tickets->resume($user, $ticket, $draft instanceof ChatConversation && $this->owns($draft, $user) ? $draft : null);
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json(['conversation' => $this->present($ticket, $user)]);
    }

    /**
     * Changes a ticket's status: agents any status, the requester only a reopen (`open`). Unknown or forbidden status
     * → 422 `status`; read-only ticket (requester) → 422 `read_only`.
     *
     * @param string $uuid Ticket uuid
     * @param Request $request JSON body `{status}`
     *
     * @return JsonResponse `{conversation}`
     */
    #[Route('/support/{uuid}/status', name: 'support_status', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function supportStatus(string $uuid, Request $request): JsonResponse
    {
        $user    = $this->writer($request);
        $ticket  = $this->findSupport($uuid);
        $isAgent = $this->access->isSupportAgent($user);
        $this->deny(!$ticket->isTicket() || !$isAgent && !$this->owns($ticket, $user), 'not_found', Response::HTTP_NOT_FOUND);
        $status = SupportStatus::tryFrom((string) ($this->payload($request)['status'] ?? ''));
        $this->deny(!$status instanceof SupportStatus, 'status', Response::HTTP_UNPROCESSABLE_ENTITY);
        assert($status instanceof SupportStatus);
        try {
            $this->tickets->changeStatus($user, $ticket, $status, $isAgent);
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json(['conversation' => $this->present($ticket, $user)]);
    }

    /**
     * An agent assigns a ticket (`assignee`: an agent's public id, or null to unassign). Not an agent → 422 `assignee`.
     *
     * @param string $uuid Ticket uuid
     * @param Request $request JSON body `{assignee}`
     *
     * @return JsonResponse `{conversation}`
     */
    #[Route('/support/{uuid}/assign', name: 'support_assign', requirements: ['uuid' => self::UUID], methods: ['POST'])]
    public function supportAssign(string $uuid, Request $request): JsonResponse
    {
        $user   = $this->writer($request);
        $ticket = $this->findSupport($uuid);
        $this->deny(!$ticket->isTicket() || !$this->access->isSupportAgent($user), 'not_found', Response::HTTP_NOT_FOUND);
        $given    = $this->payload($request)['assignee'] ?? null;
        $assignee = is_string($given) && $given !== '' ? $this->directory->findByPublicId($given) : null;
        $this->deny(is_string($given) && $given !== '' && !$assignee instanceof ChatUserInterface, 'assignee', Response::HTTP_UNPROCESSABLE_ENTITY);
        try {
            $this->tickets->assign($user, $ticket, $assignee);
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json(['conversation' => $this->present($ticket, $user)]);
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
     * Support agents' inbox (`?status=open|waiting|resolved|closed|mine|unassigned|all&topic=&q=`) with the open
     * count and the open + waiting count per topic; 404 for everyone else.
     *
     * @param Request $request The request (query `status`, `topic`, `q`)
     *
     * @return JsonResponse `{threads, open, topics}`
     */
    #[Route('/support/inbox', name: 'support_inbox', methods: ['GET'])]
    public function supportInbox(Request $request): JsonResponse
    {
        $user = $this->reader();
        $this->deny(!$this->access->isSupportAgent($user), 'not_found', Response::HTTP_NOT_FOUND);
        $query = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);
        $topic = mb_substr(trim((string) $request->query->get('topic', '')), 0, ChatConversation::TOPIC_MAX_LENGTH);

        return $this->json([
            'threads' => $this->supportInbox->list($user, (string) $request->query->get('status', 'open'), $query === '' ? null : $query, 50, $topic === '' ? null : $topic),
            'open'    => $this->supportInbox->countOpen(),
            'topics'  => $this->supportInbox->countByTopic(),
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
        // The page's #references for this reader in one resolve() per type (no N+1).
        $this->references->prime($user, $page);

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
     * Members of the conversation the caller may @mention (`?q=` filters by name, at most 80 characters): the people
     * who may read it now, without the caller, sorted by name, at most 20. Same access as reading the conversation
     * (unknown or foreign → 404).
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The request (query `q`)
     *
     * @return JsonResponse `{members: list<{uuid, name, initials, status, statusText, statusEmoji}>}`
     */
    #[Route('/conversations/{uuid}/members', name: 'members', requirements: ['uuid' => self::UUID], methods: ['GET'])]
    public function members(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->reader();
        $conversation = $this->find($uuid, ChatVoter::VIEW);
        $query        = mb_strtolower(mb_substr(trim((string) $request->query->get('q', '')), 0, 80));
        $members      = array_values(array_filter(
            $this->recipients->of($conversation),
            static fn (ChatUserInterface $member): bool => $member->getId() !== $user->getId()
                && ($query === '' || str_contains(mb_strtolower($member->getChatDisplayName()), $query)),
        ));
        usort($members, static fn (ChatUserInterface $a, ChatUserInterface $b): int => strnatcasecmp($a->getChatDisplayName(), $b->getChatDisplayName()));
        $members  = array_slice($members, 0, self::MEMBERS_LIMIT);
        $statuses = $this->presence->statusOf($members);
        $shows    = $this->showsPresence($user);

        return $this->json([
            'members' => array_map(static function (ChatUserInterface $member) use ($statuses, $shows): array {
                $status = ChatPresence::forViewer($statuses[$member->getChatPublicId()] ?? null, $shows);

                return [
                    'uuid'        => $member->getChatPublicId(),
                    'name'        => $member->getChatDisplayName(),
                    'initials'    => $member->getChatInitials(),
                    'status'      => $status['status'],
                    'statusText'  => $status['text'],
                    'statusEmoji' => $status['emoji'],
                ];
            }, $members),
        ]);
    }

    /**
     * #references the caller may insert (rate limited: an enumeration surface). Without `type`: the commands offered in
     * this conversation (`/task` only for someone who may open a task in its scope). With `type` (a key, or `*` for
     * every offered type) and `q` (at most 80 characters): the matching items every person of the conversation's
     * audience may open — the caller, and the other person of a direct conversation; a project channel keeps its
     * project's items (the providers' job). An empty result says nothing about what exists. Same access as reading the
     * conversation (unknown or foreign → 404).
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The request (query `type`, `q`)
     *
     * @return JsonResponse `{types: list<{key, label, icon}>, references: list<{type, id, title, url, subtitle, badge, tone}>}`
     */
    #[Route('/conversations/{uuid}/references', name: 'references', requirements: ['uuid' => self::UUID], methods: ['GET'])]
    public function references(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->reader();
        $conversation = $this->find($uuid, ChatVoter::VIEW);
        $this->deny(!$this->referenceLimiter->create('nowo_chat_reference_' . $user->getChatPublicId())->consume()->isAccepted(), 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        $scope = $this->references->scopeFor($conversation, $user);
        $type  = (string) $request->query->get('type', '');
        $query = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);

        return $this->json([
            'types'      => $type === '' ? $this->references->types($scope) : [],
            'references' => $type === '' ? [] : array_map(static fn (ChatReference $reference): array => $reference->toArray(), $this->references->search($scope, $type, $query)),
        ]);
    }

    /**
     * One message as the caller sees it (its #reference cards resolved for the caller): the dock fetches it after a
     * viewer-neutral realtime payload. Same access as reading the conversation (unknown or foreign → 404).
     *
     * @param string $uuid Conversation uuid
     * @param string $messageUuid Message uuid
     *
     * @return JsonResponse `{message}`
     */
    #[Route('/conversations/{uuid}/messages/{messageUuid}', name: 'message', requirements: ['uuid' => self::UUID, 'messageUuid' => self::UUID], methods: ['GET'])]
    public function message(string $uuid, string $messageUuid): JsonResponse
    {
        $user    = $this->reader();
        $message = $this->messages->findOneInConversation($this->find($uuid, ChatVoter::VIEW), $messageUuid);
        $this->deny(!$message instanceof ChatMessage, 'not_found', Response::HTTP_NOT_FOUND);
        assert($message instanceof ChatMessage);

        return $this->json(['message' => $this->presenter->message($message, $user, $this->reactionsOf($message))]);
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
     * Deletes the caller's own message within the edit window (nobody deletes someone else's message).
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
        $expiresAt = $this->clock->now()->modify('+15 minutes');
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
     * Tab heartbeat; answers who of the caller's direct contacts is online (reciprocal opt-out), what status each
     * shows, and the caller's own status (a status that expired went back to available).
     *
     * @param Request $request JSON body `{visible, idle}` (`idle`: no input in any tab for the auto-away delay)
     *
     * @return JsonResponse `{online, statuses, me}` public ids of online peers, `{status, text, emoji}` by public id, own status
     */
    #[Route('/presence', name: 'presence', methods: ['POST'])]
    public function presence(Request $request): JsonResponse
    {
        $user    = $this->writer($request);
        $payload = $this->payload($request);
        $this->presence->heartbeat($user, true === ($payload['visible'] ?? false), true === ($payload['idle'] ?? false));
        $shows    = $this->showsPresence($user);
        $online   = [];
        $statuses = [];
        foreach ($this->presence->statusOf($this->directPeers($user)) as $publicId => $status) {
            if ($shows && $status['online']) {
                $online[] = $publicId;
            }
            $statuses[$publicId] = ChatPresence::forViewer($status, $shows);
        }

        return $this->json(['online' => $online, 'statuses' => (object) $statuses, 'me' => $this->settings->forUser($user)->statusView($this->clock->now())]);
    }

    /**
     * Sets the caller's chat status from the dock or the chat page: available, busy, away, dnd (no sounds, buzz or
     * push) or invisible (offline to everyone), an optional custom text (at most 80 characters) and emoji, and when it
     * clears itself. Direct contacts get the change live (an invisible user is offline to them, without text).
     *
     * @param Request $request JSON body `{status, text?, emoji?, clearAfter?}` (`clearAfter`: never, 30m, 1h, 2h, 4h, today, week)
     *
     * @return JsonResponse `{status: {status, text, emoji, expiresAt}}`
     */
    #[Route('/status', name: 'status', methods: ['POST'])]
    public function status(Request $request): JsonResponse
    {
        $user        = $this->writer($request);
        $payload     = $this->payload($request);
        $now         = $this->clock->now();
        $preferences = $this->settings->forUser($user);
        $text        = $payload['text'] ?? null;
        $emoji       = $payload['emoji'] ?? null;
        $after       = $payload['clearAfter'] ?? 'never';
        try {
            if (!is_string($payload['status'] ?? null) || !is_string($after) || ($text !== null && !is_string($text)) || ($emoji !== null && !is_string($emoji))) {
                throw new InvalidArgumentException('invalid_status');
            }
            // @igor-ignore - the caller's own settings row (a Doctrine entity), not a shared worker service.
            $preferences->setStatus($payload['status'], $text, $emoji, ChatUserSettings::statusExpiry($after, $now));
        } catch (InvalidArgumentException) {
            throw new ChatApiException('invalid_status', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->settings->save($preferences);
        $own     = $preferences->statusView($now);
        $visible = ChatPresence::forViewer($this->presence->statusOf([$user])[$user->getChatPublicId()] ?? null, true);
        $this->notifier->statusChanged($user, $visible, $own, $this->directPeers($user));

        return $this->json(['status' => $own]);
    }

    /**
     * 1.11: the caller's automatic reply — on / off, the Markdown text (at most 500 characters), when it applies
     * (`status`: while away, busy or in do not disturb; `range`: between `from` and `until`, ISO 8601) and whether it
     * also answers @mentions in channels.
     *
     * @param Request $request JSON body `{enabled, text, when, from?, until?, mentions}`
     *
     * @return JsonResponse `{autoReply}`
     */
    #[Route('/auto-reply', name: 'auto_reply', methods: ['POST'])]
    public function autoReply(Request $request): JsonResponse
    {
        $user    = $this->writer($request);
        $payload = $this->payload($request);
        $mine    = $this->settings->forUser($user);
        $date    = static function (mixed $value): ?DateTimeImmutable {
            if (!is_string($value) || $value === '') {
                return null;
            }
            $parsed = date_create_immutable($value);

            return $parsed === false ? null : $parsed;
        };
        $text = $payload['text'] ?? null;
        $when = $payload['when'] ?? 'status';
        try {
            if (($text !== null && !is_string($text)) || !is_string($when)) {
                throw new InvalidArgumentException('invalid_auto_reply');
            }
            // @igor-ignore - the caller's own settings row (a Doctrine entity), not a shared worker service.
            $mine->setAutoReply(true === ($payload['enabled'] ?? false), $text, $when, $date($payload['from'] ?? null), $date($payload['until'] ?? null), true === ($payload['mentions'] ?? false));
        } catch (InvalidArgumentException) {
            throw new ChatApiException('invalid_auto_reply', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->settings->save($mine);

        return $this->json(['autoReply' => $mine->autoReplyView()]);
    }

    /**
     * 1.11: the caller's quick replies (snippets), by title.
     *
     * @return JsonResponse `{quickReplies: list<{id, title, body}>}`
     */
    #[Route('/quick-replies', name: 'quick_replies', methods: ['GET'])]
    public function quickReplies(): JsonResponse
    {
        $user = $this->reader();

        return $this->json(['quickReplies' => array_map(static fn (ChatQuickReply $reply): array => $reply->toArray(), $this->quickReplies?->forUser($user) ?? [])]);
    }

    /**
     * 1.11: saves a new quick reply (at most 50 per person; title ≤ 80, text ≤ 2000 characters).
     *
     * @param Request $request JSON body `{title, body}`
     *
     * @return JsonResponse `{quickReply}` with status 201
     */
    #[Route('/quick-replies', name: 'quick_reply_create', methods: ['POST'])]
    public function createQuickReply(Request $request): JsonResponse
    {
        $user = $this->writer($request);
        $this->deny(count($this->quickReplies?->forUser($user) ?? []) >= ChatQuickReply::MAX_PER_USER, 'too_many_quick_replies', Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = $this->payload($request);
        try {
            $reply = new ChatQuickReply($user, $this->text($payload, 'title'), $this->text($payload, 'body'), $this->clock->now());
        } catch (InvalidArgumentException) {
            throw new ChatApiException('invalid_quick_reply', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->quickReplies?->save($reply);

        return $this->json(['quickReply' => $reply->toArray()], Response::HTTP_CREATED);
    }

    /**
     * 1.11: changes (`PATCH {title, body}`) or deletes (`DELETE`) one of the caller's quick replies (others' → 404).
     *
     * @param int $id Quick reply id
     * @param Request $request The request (CSRF header)
     *
     * @return JsonResponse `{quickReply}` or `{deleted: true}`
     */
    #[Route('/quick-replies/{id}', name: 'quick_reply', requirements: ['id' => '\d+'], methods: ['PATCH', 'DELETE'])]
    public function quickReply(int $id, Request $request): JsonResponse
    {
        $user  = $this->writer($request);
        $reply = $this->quickReplies?->findOwned($user, $id);
        $this->deny(!$reply instanceof ChatQuickReply, 'not_found', Response::HTTP_NOT_FOUND);
        assert($reply instanceof ChatQuickReply);
        if ($request->isMethod('DELETE')) {
            $this->quickReplies?->remove($reply);

            return $this->json(['deleted' => true]);
        }
        $payload = $this->payload($request);
        try {
            $reply->change($this->text($payload, 'title'), $this->text($payload, 'body'));
        } catch (InvalidArgumentException) {
            throw new ChatApiException('invalid_quick_reply', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->quickReplies?->save($reply);

        return $this->json(['quickReply' => $reply->toArray()]);
    }

    /**
     * Changes one chat preference of the caller (sounds, volume, buzz, opening the dock, send key, push preview,
     * presence, e-mail digest); the caller's other tabs apply it live.
     *
     * @param Request $request JSON body `{key, value}` (key: ChatUserSettings::PREFERENCE_KEYS)
     *
     * @return JsonResponse `{prefs}` every preference after the change
     */
    #[Route('/settings', name: 'settings', methods: ['POST'])]
    public function settings(Request $request): JsonResponse
    {
        $user    = $this->writer($request);
        $payload = $this->payload($request);
        $mine    = $this->settings->forUser($user);
        try {
            $mine->applyPreference(is_string($payload['key'] ?? null) ? $payload['key'] : '', $payload['value'] ?? null);
        } catch (InvalidArgumentException) {
            throw new ChatApiException('invalid_setting', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->settings->save($mine);
        $preferences = $mine->preferences();
        $this->notifier->preferencesChanged($user, $preferences);

        return $this->json(['prefs' => $preferences]);
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
     * @param array<string, array{online: bool, appOpen: bool, status: string, text: string|null, emoji: string|null}>|null $presence precomputed by the list endpoint
     *
     * @return array<string, mixed>
     */
    private function present(ChatConversation $conversation, ChatUserInterface $user, ?ChatUserInterface $peer = null, int $unread = 0, ?ChatMessage $last = null, ?array $presence = null): array
    {
        $peer ??= $this->access->directPeer($conversation, $user);
        $status = null;
        if ($peer instanceof ChatUserInterface) {
            $presence ??= $this->presence->statusOf([$peer]);
            $status = ChatPresence::forViewer($presence[$peer->getChatPublicId()] ?? null, $this->showsPresence($user));
        }

        $summary = $this->presenter->conversation(
            $conversation,
            $user,
            $peer,
            $conversation->isChannel() ? $this->access->channel((string) $conversation->getChannelKey()) : null,
            $this->isGranted(ChatVoter::SEND, $conversation),
            $unread,
            $last,
            $this->access->isSupportAgent($user),
            $status !== null && $status['status'] !== 'offline',
            $status,
        );
        $summary['canPin'] = $this->access->canPin($conversation, $user);
        if ($conversation->isSupportDraft() && $this->owns($conversation, $user)) {
            // The requester's draft: the topic question (or "continue or new?") and their earlier tickets.
            $summary['intake'] = $this->tickets->intake($user);
        }

        return $summary;
    }

    /** Whether the user is the requester of the support conversation. */
    private function owns(ChatConversation $conversation, ChatUserInterface $user): bool
    {
        return $conversation->isSupport() && $conversation->getSupportOwner()?->getId() === $user->getId();
    }

    /** A support conversation the caller may view (anything else → 404). */
    private function findSupport(string $uuid): ChatConversation
    {
        $conversation = $this->find($uuid, ChatVoter::VIEW);
        $this->deny(!$conversation->isSupport(), 'not_found', Response::HTTP_NOT_FOUND);

        return $conversation;
    }

    /**
     * @return array{pins: list<array<string, mixed>>, canPin: bool}
     */
    private function pinsOf(ChatConversation $conversation, ChatUserInterface $user): array
    {
        return [
            'pins'   => array_map(fn (ChatMessage $message): array => $this->presenter->message($message, $user), $this->messages->findPinned($conversation)),
            'canPin' => $this->access->canPin($conversation, $user),
        ];
    }

    /**
     * Conversations of the list the user closed and that stay closed now.
     *
     * @param list<int> $ids Conversation ids
     *
     * @return array<int, true> conversation id => closed
     */
    private function closedIds(ChatUserInterface $user, array $ids): array
    {
        $now    = $this->clock->now();
        $closed = [];
        foreach ($this->participants->findForUser($user, $ids) as $id => $participant) {
            if ($participant->isArchived($now)) {
                $closed[$id] = true;
            }
        }

        return $closed;
    }

    /**
     * People with a direct conversation with the user, still reachable.
     *
     * @return list<ChatUserInterface>
     */
    private function directPeers(ChatUserInterface $user): array
    {
        $peerIds = $this->conversations->findDirectPeerIds($user);

        return array_values(array_filter($peerIds === [] ? [] : $this->directory->findByIds($peerIds), $this->access->isReachable(...)));
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
     * A string field of the JSON body ('' when missing or not a string).
     *
     * @param array<string, mixed> $payload
     */
    private function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? $value : '';
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
