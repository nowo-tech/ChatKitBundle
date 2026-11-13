<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatChannelKey;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ChatUserReferenceInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;

use function in_array;
use function strlen;

use const SORT_STRING;

/**
 * A conversation: one per user pair (direct), one per application channel key, and support tickets. A support
 * conversation without a ticket number is the user's intake draft (topic not chosen yet); choosing a topic turns
 * it into a numbered ticket. One user may hold several tickets. Unique keys enforce the direct / channel / ticket
 * number cardinalities in the database.
 */
#[ORM\Entity(repositoryClass: ChatConversationRepository::class)]
#[ORM\Table(name: 'nowo_chat_conversation')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_conversation_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_conversation_direct', columns: ['direct_key'])]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_conversation_channel', columns: ['channel_key'])]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_conversation_ticket', columns: ['ticket_number'])]
#[ORM\Index(name: 'idx_nowo_chat_conversation_activity', columns: ['kind', 'last_message_at'])]
#[ORM\Index(name: 'idx_nowo_chat_conversation_support', columns: ['support_owner_id', 'last_message_at'])]
#[ORM\Index(name: 'idx_nowo_chat_conversation_assignee', columns: ['assignee_id'])]
class ChatConversation
{
    use PublicIdTrait;

    public const int CHANNEL_KEY_MAX_LENGTH = 120;

    public const int TOPIC_MAX_LENGTH = 64;

    /** 1.27: prefix of the direct key of a pair of string references. */
    public const string HASHED_DIRECT_KEY = 'r';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Direct only: "<lower user id>:<higher user id>", or (1.27, string references) `r` + 40 hex digits of the pair's hash. */
    #[ORM\Column(name: 'direct_key', length: 41, nullable: true)]
    private ?string $directKey = null;

    /**
     * 1.27, direct only, string user references (ChatUserReferenceInterface): the two references, lower first (byte
     * order). Mapped by UserReferenceListener in `doctrine.user_reference: id` mode only (columns `direct_user_low` /
     * `direct_user_high`); null for integer pairs, whose ids are in `directKey`.
     */
    private ?string $directUserLow = null;

    /** 1.27: see `directUserLow`. */
    private ?string $directUserHigh = null;

    /** Channel only: the application key (ChatChannelProviderInterface), e.g. `project:<uuid>`. */
    #[ORM\Column(name: 'channel_key', length: self::CHANNEL_KEY_MAX_LENGTH, nullable: true)]
    private ?string $channelKey = null;

    #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
    #[ORM\JoinColumn(name: 'support_owner_id', nullable: true, onDelete: 'CASCADE')]
    private ?ChatUserInterface $supportOwner = null;

    /** 1.24: `supportOwner` as a reference (`doctrine.user_reference: id` maps this instead of the relation). */
    private ?string $supportOwnerRef = null;

    #[ORM\Column(name: 'support_status', length: 16, nullable: true, enumType: SupportStatus::class)]
    private ?SupportStatus $supportStatus = null;

    #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
    #[ORM\JoinColumn(name: 'resolved_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?ChatUserInterface $resolvedBy = null;

    /** 1.24: `resolvedBy` as a reference (`doctrine.user_reference: id` maps this instead of the relation). */
    private ?string $resolvedByRef = null;

    /**
     * Support only: where the user reported the problem from (`url`, `label`), shown to agents as a context chip.
     *
     * @var array{url?: string, label?: string}|null
     */
    #[ORM\Column(name: 'support_context', type: 'json', nullable: true)]
    private ?array $supportContext = null;

    #[ORM\Column(name: 'resolved_at', nullable: true)]
    private ?DateTimeImmutable $resolvedAt = null;

    /** Support only: sequential ticket number (per application); null while the topic is not chosen (intake draft). */
    #[ORM\Column(name: 'ticket_number', nullable: true)]
    private ?int $ticketNumber = null;

    /** Support only: key of the ticket topic (`support.topics`); null for drafts and tickets created before 1.8. */
    #[ORM\Column(name: 'ticket_topic', length: self::TOPIC_MAX_LENGTH, nullable: true)]
    private ?string $ticketTopic = null;

    /** Support only: the agent handling the ticket; null when unassigned. */
    #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
    #[ORM\JoinColumn(name: 'assignee_id', nullable: true, onDelete: 'SET NULL')]
    private ?ChatUserInterface $assignee = null;

    /** 1.24: `assignee` as a reference (`doctrine.user_reference: id` maps this instead of the relation). */
    private ?string $assigneeRef = null;

    /** Support only: when the ticket was closed (read-only from then on). */
    #[ORM\Column(name: 'closed_at', nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    /** Set from creation so list ordering needs no COALESCE and stays indexed. */
    #[ORM\Column(name: 'last_message_at')]
    private DateTimeImmutable $lastMessageAt;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    private function __construct(
        #[ORM\Column(length: 16, enumType: ConversationKind::class)]
        private ConversationKind $kind,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->initUuid();
        $this->createdAt     = $createdAt ?? new DateTimeImmutable();
        $this->lastMessageAt = $this->createdAt;
    }

    /**
     * Creates a direct conversation between two persisted users (keyed by the user pair).
     *
     * @param ChatUserInterface $a One person
     * @param ChatUserInterface $b The other person
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     *
     * @throws LogicException When the users are the same or not persisted
     *
     * @return self The new, unpersisted conversation
     */
    public static function direct(ChatUserInterface $a, ChatUserInterface $b, ?DateTimeImmutable $createdAt = null): self
    {
        $conversation            = new self(ConversationKind::Direct, $createdAt);
        $conversation->directKey = self::directKeyFor($a, $b);
        if (str_starts_with($conversation->directKey, self::HASHED_DIRECT_KEY)) {
            $keys = [(string) ChatUserReferences::key($a), (string) ChatUserReferences::key($b)];
            sort($keys, SORT_STRING);
            [$conversation->directUserLow, $conversation->directUserHigh] = $keys;
        }

        return $conversation;
    }

    /**
     * Creates the conversation of an application channel.
     *
     * @param string $key Channel key (ChatChannelKey: 1–120 bytes, no padding or control characters)
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     *
     * @throws LogicException When the key breaks the ChatChannelKey format rule
     *
     * @return self The new, unpersisted conversation
     */
    public static function channel(string $key, ?DateTimeImmutable $createdAt = null): self
    {
        ChatChannelKey::assertValid($key);
        $conversation             = new self(ConversationKind::Channel, $createdAt);
        $conversation->channelKey = $key;

        return $conversation;
    }

    /**
     * Creates the user's support thread, open.
     *
     * @param ChatUserInterface $owner The user asking for support
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     *
     * @return self The new, unpersisted conversation
     */
    public static function support(ChatUserInterface $owner, ?DateTimeImmutable $createdAt = null): self
    {
        $conversation                  = new self(ConversationKind::Support, $createdAt);
        $conversation->supportOwner    = $owner;
        $conversation->supportOwnerRef = ChatUserReferences::reference($owner);
        $conversation->supportStatus   = SupportStatus::Open;

        return $conversation;
    }

    /**
     * Order-independent key of a user pair; both users must be persisted. Two users with integer ids give
     * `<lower id>:<higher id>` (as before 1.27); 1.27: when either implements ChatUserReferenceInterface (string
     * references, `doctrine.user_reference: id`), `r` followed by 40 hex digits of the SHA-256 of both references (byte
     * order, NUL separated), which fits the same column.
     *
     * @param ChatUserInterface $a One person
     * @param ChatUserInterface $b The other person
     *
     * @throws LogicException When the users are the same or not persisted
     *
     * @return string The pair key
     */
    public static function directKeyFor(ChatUserInterface $a, ChatUserInterface $b): string
    {
        $keys = [ChatUserReferences::key($a), ChatUserReferences::key($b)];
        if ($keys[0] === null || $keys[1] === null || $keys[0] === $keys[1]) {
            throw new LogicException('A direct conversation needs two different persisted users.');
        }
        if (!$a instanceof ChatUserReferenceInterface && !$b instanceof ChatUserReferenceInterface) {
            $ids = [(int) $a->getId(), (int) $b->getId()];
            sort($ids);

            return $ids[0] . ':' . $ids[1];
        }
        sort($keys, SORT_STRING);

        return self::HASHED_DIRECT_KEY . substr(hash('sha256', $keys[0] . "\0" . $keys[1]), 0, 40);
    }

    /**
     * Doctrine identifier.
     *
     * @return int|null The id; null before the conversation is persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Kind of the conversation.
     *
     * @return ConversationKind Direct, channel or support
     */
    public function getKind(): ConversationKind
    {
        return $this->kind;
    }

    /**
     * Whether this is a 1:1 conversation.
     *
     * @return bool True for a direct conversation
     */
    public function isDirect(): bool
    {
        return $this->kind === ConversationKind::Direct;
    }

    /**
     * Whether this is an application channel conversation.
     *
     * @return bool True for a channel conversation
     */
    public function isChannel(): bool
    {
        return $this->kind === ConversationKind::Channel;
    }

    /**
     * Whether this is a support thread.
     *
     * @return bool True for a support thread
     */
    public function isSupport(): bool
    {
        return $this->kind === ConversationKind::Support;
    }

    /**
     * Direct only: the user pair key.
     *
     * @return string|null `<lower id>:<higher id>`, or null for other kinds
     */
    public function getDirectKey(): ?string
    {
        return $this->directKey;
    }

    /**
     * Direct only: ids of both users (integer pairs; 1.27: empty for a pair of string references, see
     * directUserKeys()).
     *
     * @return list<int> The two user ids, lower first; empty for other kinds
     */
    public function directUserIds(): array
    {
        if ($this->directKey === null || $this->directUserLow !== null || str_starts_with($this->directKey, self::HASHED_DIRECT_KEY)) {
            return [];
        }

        return array_map('intval', explode(':', $this->directKey));
    }

    /**
     * 1.27, direct only: the keys of both users (ChatUserReferences::key(): the id as a string, or the string
     * reference), lower first.
     *
     * @return list<string> The two keys; empty for other kinds
     */
    public function directUserKeys(): array
    {
        if ($this->directUserLow !== null && $this->directUserHigh !== null) {
            return [$this->directUserLow, $this->directUserHigh];
        }

        return array_map(strval(...), $this->directUserIds());
    }

    /**
     * Direct only: whether the user is one of the pair (keys compared byte for byte).
     *
     * @param ChatUserInterface $user The user to check
     *
     * @return bool True when the conversation is direct and the user is part of it
     */
    public function involvesDirectly(ChatUserInterface $user): bool
    {
        $key = ChatUserReferences::key($user);

        return $this->isDirect() && $key !== null && in_array($key, $this->directUserKeys(), true);
    }

    /**
     * Direct only: the other user's id, null when `$user` is not part of the pair (1.27: and for a pair of string
     * references, see peerKeyOf()).
     *
     * @param ChatUserInterface $user One of the pair
     *
     * @return int|null The other user's id, or null
     */
    public function peerIdOf(ChatUserInterface $user): ?int
    {
        $peer = $this->directUserIds() === [] ? null : $this->peerKeyOf($user);

        return $peer === null ? null : (int) $peer;
    }

    /**
     * 1.27, direct only: the other user's key, null when `$user` is not part of the pair.
     *
     * @param ChatUserInterface $user One of the pair
     *
     * @return string|null The other user's key, or null
     */
    public function peerKeyOf(ChatUserInterface $user): ?string
    {
        if (!$this->involvesDirectly($user)) {
            return null;
        }
        [$low, $high] = $this->directUserKeys();

        return $low === ChatUserReferences::key($user) ? $high : $low;
    }

    /**
     * Channel only: the application channel key.
     *
     * @return string|null The key, or null for other kinds
     */
    public function getChannelKey(): ?string
    {
        return $this->channelKey;
    }

    /**
     * Support only: the user the thread belongs to.
     *
     * @return ChatUserInterface|null The owner, or null for other kinds
     */
    public function getSupportOwner(): ?ChatUserInterface
    {
        return $this->supportOwner;
    }

    /**
     * 1.24: the stored reference of `supportOwner` (its id as a string) — what `doctrine.user_reference: id` keeps instead of a
     * relation, readable without loading the user; null when there is none.
     *
     * @return string|null The reference
     */
    public function getSupportOwnerReference(): ?string
    {
        return $this->supportOwnerRef ?? ChatUserReferences::reference($this->supportOwner);
    }

    /**
     * Support only: open, waiting for the user, resolved or closed.
     *
     * @return SupportStatus|null The status, or null for other kinds
     */
    public function getSupportStatus(): ?SupportStatus
    {
        return $this->supportStatus;
    }

    /**
     * Whether the support thread is resolved.
     *
     * @return bool True when resolved
     */
    public function isResolved(): bool
    {
        return $this->supportStatus === SupportStatus::Resolved;
    }

    /**
     * Marks the support thread resolved by the agent.
     *
     * @param ChatUserInterface $agent The support agent
     * @param DateTimeImmutable|null $at Resolution time; null for now
     *
     * @throws LogicException When the conversation is not a support thread
     */
    public function resolve(ChatUserInterface $agent, ?DateTimeImmutable $at = null): void
    {
        $this->assertSupport();
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->supportStatus = SupportStatus::Resolved;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->resolvedBy = $agent;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->resolvedByRef = ChatUserReferences::reference($agent);
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->resolvedAt = $at ?? new DateTimeImmutable();
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->closedAt = null;
    }

    /**
     * Reopens the support thread and clears who / when resolved or closed it.
     *
     * @throws LogicException When the conversation is not a support thread
     */
    public function reopen(): void
    {
        $this->assertSupport();
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->supportStatus = SupportStatus::Open;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->resolvedBy = null;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->resolvedByRef = null;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->resolvedAt = null;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->closedAt = null;
    }

    /**
     * Sets the ticket status: resolved records who / when, closed records when, open clears both, waiting keeps them.
     *
     * @param SupportStatus $status The new status
     * @param ChatUserInterface|null $by Who changed it (recorded as the resolver for `resolved`)
     * @param DateTimeImmutable|null $at Time of the change; null for now
     *
     * @throws LogicException When the conversation is not a support thread
     */
    public function changeSupportStatus(SupportStatus $status, ?ChatUserInterface $by = null, ?DateTimeImmutable $at = null): void
    {
        $this->assertSupport();
        $at ??= new DateTimeImmutable();
        match ($status) {
            SupportStatus::Open     => $this->reopen(),
            SupportStatus::Resolved => $this->resolve($by ?? $this->supportOwner ?? throw new LogicException('A resolved ticket needs who resolved it.'), $at),
            SupportStatus::Closed   => $this->close($at),
            SupportStatus::Waiting  => $this->wait(),
        };
    }

    /**
     * Support only: whether the conversation is a numbered ticket (not an intake draft).
     *
     * @return bool True for a ticket
     */
    public function isTicket(): bool
    {
        return $this->isSupport() && $this->ticketNumber !== null;
    }

    /**
     * Support only: whether this is the owner's intake draft (no topic chosen yet, no number).
     *
     * @return bool True for a draft
     */
    public function isSupportDraft(): bool
    {
        return $this->isSupport() && $this->ticketNumber === null;
    }

    /**
     * Support only: the sequential ticket number.
     *
     * @return int|null The number; null for drafts and other kinds
     */
    public function getTicketNumber(): ?int
    {
        return $this->ticketNumber;
    }

    /**
     * Support only: key of the ticket topic.
     *
     * @return string|null The topic key; null when none was chosen
     */
    public function getTicketTopic(): ?string
    {
        return $this->ticketTopic;
    }

    /**
     * Turns the intake draft into an open ticket with its number and topic.
     *
     * @param int $number The sequential ticket number (positive)
     * @param string $topic The topic key (1–64 characters)
     *
     * @throws LogicException When the conversation is not a draft or the number / topic is invalid
     */
    public function openTicket(int $number, string $topic): void
    {
        if (!$this->isSupportDraft() || $number < 1) {
            throw new LogicException('Only a support draft becomes a ticket, with a positive number.');
        }
        $this->changeTopic($topic);
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->ticketNumber = $number;
        $this->reopen();
    }

    /**
     * Changes the ticket topic.
     *
     * @param string $topic The topic key (1–64 characters)
     *
     * @throws LogicException When the conversation is not a support thread or the key is invalid
     */
    public function changeTopic(string $topic): void
    {
        $this->assertSupport();
        if ($topic === '' || strlen($topic) > self::TOPIC_MAX_LENGTH) {
            throw new LogicException('A topic key must be 1–64 characters.');
        }
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->ticketTopic = $topic;
    }

    /**
     * Support only: the agent handling the ticket.
     *
     * @return ChatUserInterface|null The assignee; null when unassigned or the account is gone
     */
    public function getAssignee(): ?ChatUserInterface
    {
        return $this->assignee;
    }

    /**
     * 1.24: the stored reference of `assignee` (its id as a string) — what `doctrine.user_reference: id` keeps instead of a
     * relation, readable without loading the user; null when there is none.
     *
     * @return string|null The reference
     */
    public function getAssigneeReference(): ?string
    {
        return $this->assigneeRef ?? ChatUserReferences::reference($this->assignee);
    }

    /**
     * Assigns the ticket to an agent (null: unassigned).
     *
     * @param ChatUserInterface|null $assignee The agent; null to unassign
     *
     * @throws LogicException When the conversation is not a support thread
     */
    public function assign(?ChatUserInterface $assignee): void
    {
        $this->assertSupport();
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->assignee = $assignee;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->assigneeRef = ChatUserReferences::reference($assignee);
    }

    /**
     * Support only: when the ticket was closed.
     *
     * @return DateTimeImmutable|null The closing time; null unless closed
     */
    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    /**
     * Support only: where the user reported the problem from.
     *
     * @return array{url?: string, label?: string}|null The context, or null when none was recorded
     */
    public function getSupportContext(): ?array
    {
        return $this->supportContext;
    }

    /**
     * Root-relative path of the application (`/…`) and a short label; anything else is dropped. The context comes from
     * the reporter's browser, so absolute, protocol-relative (`//host`, `/\host`) URLs and control characters never
     * reach the agents (no off-site link behind a trusted label).
     *
     * @param string|null $url Page path (trimmed, at most 500 characters)
     * @param string|null $label Page label (control and format characters removed, whitespace collapsed, at most 120 characters)
     *
     * @throws LogicException When the conversation is not a support thread
     */
    public function setSupportContext(?string $url, ?string $label): void
    {
        $this->assertSupport();
        $url = $url === null ? '' : mb_substr(trim($url), 0, 500);
        if ($url !== '' && preg_match('~^/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*$~', $url) !== 1) {
            $url = '';
        }
        $label   = $label === null ? '' : (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $label);
        $label   = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $label)), 0, 120);
        $context = array_filter(['url' => $url, 'label' => $label], static fn (string $value): bool => $value !== '');
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->supportContext = $context === [] ? null : $context;
    }

    /**
     * Support only: the agent who resolved the thread.
     *
     * @return ChatUserInterface|null The agent, or null when open or the account is gone
     */
    public function getResolvedBy(): ?ChatUserInterface
    {
        return $this->resolvedBy;
    }

    /**
     * 1.24: the stored reference of `resolvedBy` (its id as a string) — what `doctrine.user_reference: id` keeps instead of a
     * relation, readable without loading the user; null when there is none.
     *
     * @return string|null The reference
     */
    public function getResolvedByReference(): ?string
    {
        return $this->resolvedByRef ?? ChatUserReferences::reference($this->resolvedBy);
    }

    /**
     * Support only: when the thread was resolved.
     *
     * @return DateTimeImmutable|null The resolution time, or null when open
     */
    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /**
     * Time of the latest activity (creation time until the first message).
     *
     * @return DateTimeImmutable The last activity time
     */
    public function getLastMessageAt(): DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    /**
     * Records new activity (list ordering).
     *
     * @param DateTimeImmutable $at Time of the new message
     */
    public function touch(DateTimeImmutable $at): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->lastMessageAt = $at;
    }

    /**
     * Creation time.
     *
     * @return DateTimeImmutable When the conversation was created
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function close(DateTimeImmutable $at): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->supportStatus = SupportStatus::Closed;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->closedAt = $at;
    }

    private function wait(): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->supportStatus = SupportStatus::Waiting;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->closedAt = null;
    }

    private function assertSupport(): void
    {
        if (!$this->isSupport()) {
            throw new LogicException('Only support conversations can be resolved or reopened.');
        }
    }
}
