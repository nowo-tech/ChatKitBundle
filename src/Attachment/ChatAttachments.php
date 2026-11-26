<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Attachment\Message\ScanChatAttachment;
use Nowo\ChatKitBundle\Contract\ChatAttachmentPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatAttachmentsInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatAttachment;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Event\ChatAttachmentBlockedEvent;
use Nowo\ChatKitBundle\Event\ChatAttachmentRemovedEvent;
use Nowo\ChatKitBundle\Event\ChatAttachmentUploadedEvent;
use Nowo\ChatKitBundle\Model\ChatAttachmentUrl;
use Nowo\ChatKitBundle\Model\ChatAttachmentView;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatIdempotency;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\StorageKitBundle\Lifecycle\FileRemoverInterface;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Upload\FileUploaderInterface;
use Nowo\StorageKitBundle\Upload\StoredFileSource;
use Nowo\StorageKitBundle\Upload\StoredFileView;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

use function array_filter;
use function array_unique;
use function array_values;
use function clearstatcache;
use function count;
use function filesize;
use function in_array;
use function is_file;
use function is_link;

/**
 * 2.7 (spec 259 block B): the default ChatAttachmentsInterface (aliased to it). See docs/USAGE.md, "Attachments", and
 * docs/CONCURRENCY.md for every write unit:
 *
 * - **U1 upload**: the name sanitized, the type sniffed and allow-listed, the bytes streamed to StorageKit and
 *   referenced **before** any lock; then one short unit (conversation row lock, write access and the person's draft
 *   quota re-checked, savepoint insert under the `Idempotency-Key`). A unit that fails expires the stored file.
 * - **U2 send**: ChatService's send unit with the files attached as its last statement: rows locked `FOR UPDATE` in id
 *   order after the conversation's lock, each one a clean draft of the author in this conversation, or nothing is
 *   written (`attachment_not_ready` / `attachment_invalid`).
 * - **C3 remove**, **C4 block**: compare-and-set under the conversation's lock; objects removed after the commit.
 *
 * Unknown, foreign and forbidden ids are one answer (ChatNotFoundException). Stateless (FrankenPHP worker mode).
 *
 * @phpstan-import-type AttachmentRow from ChatAttachmentRepository
 */
final readonly class ChatAttachments implements ChatAttachmentsInterface
{
    /** The variant every 2.7 URL serves. */
    public const string ORIGINAL = 'original';

    /** StorageKit reference type of an attachment row (`FileReferencesInterface::sync()`). */
    public const string REFERENCE_TYPE = 'chat_attachment';

    /**
     * @param EntityManagerInterface $entityManager ChatKit's private manager
     * @param TransactionRunnerInterface $transactions ChatKit's runner
     * @param ChatAttachmentRepository $rows The attachment rows
     * @param ChatConversationRepository $conversations Conversations by uuid (and their row lock)
     * @param ChatMessageRepository $messages Messages by uuid
     * @param ChatVoter $voter Read and write access, checked live on every call
     * @param ChatAccess $access Reachability
     * @param ChatService $chat The send pipeline
     * @param ChatIdempotency $idempotency `Idempotency-Key` of a send
     * @param ChatAttachmentTypes $types Name sanitizer, sniffer and allow-list
     * @param ChatAttachmentOptions $options Limits
     * @param ChatAttachmentSigner $signer Download URL signatures
     * @param ChatAttachmentCleanup $cleanup Object removal after the commit
     * @param FileUploaderInterface $uploader StorageKit's generic upload
     * @param FileRemoverInterface $remover StorageKit's remover (a failed unit expires its file)
     * @param FileReferencesInterface $references StorageKit's references (files are born orphaned)
     * @param FileUris $uris StorageKit's `storage-file:` URIs
     * @param UrlGeneratorInterface $urls The download route
     * @param MessageBusInterface|null $bus Queues the scans; null leaves them to the sweep
     * @param ChatNotifier|null $notifier `message_updated` after a sent file is removed or blocked
     * @param ChatMarkdownRenderer|null $markdown Renders the views of a sent message
     * @param EventDispatcherInterface|null $events Receives the attachment events
     * @param ClockInterface $clock Every time written
     * @param LoggerInterface|null $logger Logs failed reference / scan dispatches (ids only)
     * @param ChatAttachmentPolicyInterface|null $policy FR-010: who may attach where; null: whoever may write
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRunnerInterface $transactions,
        private ChatAttachmentRepository $rows,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatVoter $voter,
        private ChatAccess $access,
        private ChatService $chat,
        private ChatIdempotency $idempotency,
        private ChatAttachmentTypes $types,
        private ChatAttachmentOptions $options,
        private ChatAttachmentSigner $signer,
        private ChatAttachmentCleanup $cleanup,
        private FileUploaderInterface $uploader,
        private FileRemoverInterface $remover,
        private FileReferencesInterface $references,
        private FileUris $uris,
        private UrlGeneratorInterface $urls,
        private ?MessageBusInterface $bus = null,
        private ?ChatNotifier $notifier = null,
        private ?ChatMarkdownRenderer $markdown = null,
        private ?EventDispatcherInterface $events = null,
        private ClockInterface $clock = new Clock(),
        private ?LoggerInterface $logger = null,
        private ?ChatAttachmentPolicyInterface $policy = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function upload(ChatUserInterface $uploader, string $conversationUuid, string $path, string $name, ?string $idempotencyKey = null, ?string $contextKey = null): ChatAttachmentView
    {
        $conversation = $this->writable($uploader, $conversationUuid);
        $this->assertMayAttach($uploader, $conversation, $contextKey);
        $uploaderKey        = (string) ChatUserReferences::key($uploader);
        [$name, $extension] = ChatAttachmentTypes::sanitizeName($name) ?? throw new ChatAttachmentRefused(ChatAttachmentRefused::INVALID_FILE_NAME);
        if ($path === '' || !StoredFileSource::isLocal($path) || is_link($path) || !is_file($path)) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::INVALID_FILE);
        }
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        if ($size === 0) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::EMPTY_FILE);
        }
        if ($size > $this->options->effectiveMaxBytes()) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::FILE_TOO_LARGE);
        }
        if ($idempotencyKey !== null && (!ChatIdempotency::isValidKey($idempotencyKey))) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::IDEMPOTENCY_MISMATCH);
        }
        $hash = $idempotencyKey === null ? null : ChatIdempotency::hash(['upload', $conversationUuid, $name, $size]);
        if ($idempotencyKey !== null && $hash !== null) {
            $replay = $this->replay($uploaderKey, $idempotencyKey, $hash);
            if ($replay instanceof ChatAttachmentView) {
                return $replay;
            }
        }
        [$mediaType, $kind] = $this->types->classify($path, $extension);
        // A cheap early refusal before any byte travels (re-checked under the lock below).
        if ($this->rows->countDrafts($uploaderKey) >= $this->options->maxPendingPerUser) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::TOO_MANY_PENDING);
        }

        // REQ-RUNTIME-003 rule 9: the transfer runs with no lock and no transaction of ours.
        $stored = $this->store($uploader, $conversationUuid, $path, $size, $name, $mediaType);
        $row    = new ChatAttachment($conversation, $uploader, $stored->uuid, $name, $extension, $mediaType, $kind->value, $stored->size, $stored->sha256, $idempotencyKey, $hash, $this->clock->now());
        try {
            // Files are born orphaned in StorageKit: the reference keeps StorageKit's purge away from it.
            $this->references->sync(self::REFERENCE_TYPE, $row->getUuid(), '![](' . $this->uris->uri($stored->uuid) . ')', [ChatAttachmentObjects::CONTEXT, $conversationUuid]);
            $lost = ChatKitWrites::runRecovering($this->transactions, $this->entityManager, function () use ($conversation, $uploader, $uploaderKey, $row): bool {
                $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
                if (!$this->conversations->lockActivity($conversation) instanceof DateTimeImmutable) {
                    throw new ChatNotFoundException('conversation', $conversation->getUuid());
                }
                if (!$this->voter->decide(ChatVoter::SEND, $conversation, $uploader)) {
                    throw new ChatAttachmentRefused(ChatAttachmentRefused::READ_ONLY);
                }
                if ($this->rows->countDrafts($uploaderKey) >= $this->options->maxPendingPerUser) {
                    throw new ChatAttachmentRefused(ChatAttachmentRefused::TOO_MANY_PENDING);
                }

                return $this->rows->insert($row) instanceof UniqueConstraintViolationException;
            });
        } catch (Throwable $failure) {
            $this->discard($stored, $conversationUuid);

            throw $failure;
        }
        if ($lost) {
            // Another request with the same key wrote first: its answer, and this copy goes.
            $this->discard($stored, $conversationUuid);

            return $this->replay($uploaderKey, (string) $idempotencyKey, (string) $hash) ?? throw new ChatAttachmentRefused(ChatAttachmentRefused::IDEMPOTENCY_MISMATCH);
        }
        $written = $this->rows->findRow($row->getUuid()) ?? throw new ChatNotFoundException('attachment', $row->getUuid());
        $this->events?->dispatch(new ChatAttachmentUploadedEvent($written['uuid'], $conversationUuid, $uploader->getChatPublicId(), $mediaType, $stored->size));
        $this->queueScan($written['id']);

        return ChatAttachmentViews::view($written, null);
    }

    /**
     * {@inheritDoc}
     */
    public function drafts(ChatUserInterface $uploader, string $conversationUuid): array
    {
        $this->readable($uploader, $conversationUuid);
        $views = [];
        foreach ($this->rows->drafts((string) ChatUserReferences::key($uploader), $conversationUuid) as $row) {
            $views[] = ChatAttachmentViews::view($row, null);
        }

        return $views;
    }

    /**
     * {@inheritDoc}
     */
    public function attachmentsOf(ChatUserInterface $viewer, string $conversationUuid, string $messageUuid): array
    {
        $message = $this->messages->findOneInConversation($this->readable($viewer, $conversationUuid), $messageUuid);
        if (!$message instanceof ChatMessage || $message->getUuid() !== $messageUuid || $message->isDeleted()) {
            throw new ChatNotFoundException('message', $messageUuid);
        }

        return (new ChatAttachmentViews($this->rows))->forMessages([$message])[(int) $message->getId()] ?? [];
    }

    /**
     * {@inheritDoc}
     */
    public function find(ChatUserInterface $viewer, string $attachmentUuid): ChatAttachmentView
    {
        [$row, $message] = $this->visible($viewer, $attachmentUuid);

        return ChatAttachmentViews::view($row, $message?->getUuid());
    }

    /**
     * {@inheritDoc}
     */
    public function downloadUrl(ChatUserInterface $viewer, string $attachmentUuid, ?string $tokenId = null, ?string $contextKey = null): ChatAttachmentUrl
    {
        [$row] = $this->visible($viewer, $attachmentUuid);
        // A draft is downloadable by its uploader only once clean (never an unscanned byte, even to its owner).
        if ($row['state'] !== ChatAttachmentState::Clean->value) {
            throw new ChatNotFoundException('attachment', $attachmentUuid);
        }
        $expires = $this->clock->now()->getTimestamp() + $this->options->urlTtlSeconds;
        $api     = $tokenId !== null;
        $query   = ['e' => (string) $expires, 's' => $this->signer->sign($row['uuid'], self::ORIGINAL, $viewer->getChatPublicId(), $contextKey, $tokenId, $expires)];
        if (!$api && !$this->options->bindSession) {
            $query['v'] = $viewer->getChatPublicId();
        }
        $route = $api ? 'nowo_chat_api_v1_file' : 'nowo_chat_file';

        return new ChatAttachmentUrl($this->urls->generate($route, ['uuid' => $row['uuid'], 'variant' => self::ORIGINAL] + $query), $this->clock->now()->setTimestamp($expires));
    }

    /**
     * {@inheritDoc}
     */
    public function remove(ChatUserInterface $actor, string $attachmentUuid): void
    {
        [$row, $message] = $this->visible($actor, $attachmentUuid);
        if ($message instanceof ChatMessage && !$this->voter->decide(ChatVoter::DELETE, $message, $actor)) {
            throw new ChatNotFoundException('attachment', $attachmentUuid);
        }
        $conversation = $this->conversationOf($row);
        $now          = $this->clock->now();
        $removed      = ChatKitWrites::runRecovering($this->transactions, $this->entityManager, function () use ($conversation, $row, $now): bool {
            // The same order as a send (conversation first): a remove that races a send serializes behind it.
            if ($conversation instanceof ChatConversation) {
                $this->conversations->lockActivity(ChatKitWrites::attach($this->entityManager, $conversation));
            }
            $locked = $this->rows->findRow($row['uuid'], true);
            // A draft that a send attached meanwhile is no longer a draft: only its message's deleter removes it.
            if ($locked === null || $locked['message_id'] !== $row['message_id']) {
                return false;
            }

            return $this->rows->markDeleted($locked['id'], $now);
        });
        if (!$removed) {
            return;
        }
        $this->cleanup->afterCommit([$row['id']]);
        $this->events?->dispatch(new ChatAttachmentRemovedEvent($row['uuid'], $row['conversation_uuid'], $message?->getUuid(), $actor->getChatPublicId()));
        if ($message instanceof ChatMessage) {
            $this->notifier?->messageUpdated($message);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function send(ChatUserInterface $author, string $conversationUuid, string $body, array $attachmentUuids, ?string $idempotencyKey = null): ChatMessageView
    {
        $conversation = $this->writable($author, $conversationUuid);
        $uuids        = array_values(array_unique($attachmentUuids));
        if ($idempotencyKey === null) {
            return ChatMessageView::of($this->sendWith($author, $conversation, $body, $uuids), $this->markdown);
        }
        if (!ChatIdempotency::isValidKey($idempotencyKey) || ChatIdempotency::isClientKey($idempotencyKey)) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::IDEMPOTENCY_MISMATCH);
        }
        [$messages] = $this->idempotency->run($author, $idempotencyKey, ChatIdempotency::hash(['send', $conversationUuid, $body, $uuids]), fn (Closure $before, Closure $after): array => [$this->sendWith($author, $conversation, $body, $uuids, $before, $after)]);

        return ChatMessageView::of($messages[0] ?? throw new ChatNotFoundException('message', ''), $this->markdown);
    }

    /**
     * U2: ChatService's send with the files attached as the unit's last statement (the bundle's routes use it with
     * their own idempotency wrapper).
     *
     * @param list<string> $uuids Draft uuids (unique), in order
     * @param (Closure(): bool)|null $before First statement of the unit (an idempotency claim)
     * @param (Closure(list<ChatMessage>): void)|null $after Last statement of the unit
     * @param string|null $cardContext The client API context (typed cards)
     * @param bool $cardApi Written through the client API
     *
     * @throws ChatAttachmentRefused `too_many_attachments`, `attachment_not_ready`, `attachment_invalid`
     * @throws InvalidArgumentException The send's own refusals (`empty`, `too_long`, …)
     *
     * @internal
     */
    public function sendWith(ChatUserInterface $author, ChatConversation $conversation, string $body, array $uuids, ?Closure $before = null, ?Closure $after = null, ?string $cardContext = null, bool $cardApi = false): ChatMessage
    {
        if (count($uuids) > $this->options->maxPerMessage) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::TOO_MANY_ATTACHMENTS);
        }
        if ($uuids === []) {
            return $this->chat->sendWith($author, $conversation, $body, $before, $after, $cardContext, $cardApi);
        }
        // FR-010: asked again on send (a permission withdrawn since the upload refuses the files).
        $this->assertMayAttach($author, $conversation, $cardContext);
        $authorKey = (string) ChatUserReferences::key($author);
        $attach    = function (array $messages) use ($uuids, $authorKey, $conversation, $after): void {
            $message = $messages[0] ?? null;
            if ($message instanceof ChatMessage) {
                // The message's id (the attachment rows point at it).
                $this->entityManager->flush();
                $this->attachAll($message, $conversation, $uuids, $authorKey);
            }
            if ($after instanceof Closure) {
                $after(array_values(array_filter($messages, static fn (mixed $row): bool => $row instanceof ChatMessage)));
            }
        };

        return $this->chat->sendWith($author, $conversation, $body, $before, $attach, $cardContext, $cardApi, true);
    }

    /**
     * {@inheritDoc}
     */
    public function block(string $attachmentUuid): bool
    {
        $row = $this->rows->findRow($attachmentUuid);
        if ($row === null) {
            return false;
        }
        $conversation = $this->conversationOf($row);
        $blocked      = ChatKitWrites::runRecovering($this->transactions, $this->entityManager, function () use ($conversation, $row): bool {
            if ($conversation instanceof ChatConversation) {
                $this->conversations->lockActivity(ChatKitWrites::attach($this->entityManager, $conversation));
            }

            return $this->rows->block($row['id']);
        });
        if (!$blocked) {
            return false;
        }
        $this->cleanup->afterCommit([$row['id']]);
        $message = $row['message_id'] === null ? null : $this->messages->find($row['message_id']);
        $this->events?->dispatch(new ChatAttachmentBlockedEvent($row['uuid'], $row['conversation_uuid'], $message?->getUuid(), ChatAttachmentBlockedEvent::BY_HOST));
        if ($message instanceof ChatMessage) {
            $this->notifier?->messageUpdated($message);
        }

        return true;
    }

    /**
     * The row and its message when the viewer may see the file now: a clean file of a message they may read (live
     * membership, not deleted), or one of their own drafts. Anything else is "not found".
     *
     * @throws ChatNotFoundException
     *
     * @return array{0: AttachmentRow, 1: ChatMessage|null}
     */
    public function visible(ChatUserInterface $viewer, string $attachmentUuid): array
    {
        $row = $this->rows->findRow($attachmentUuid);
        if ($row === null || $row['state'] === ChatAttachmentState::Deleted->value || !$this->access->isReachable($viewer)) {
            throw new ChatNotFoundException('attachment', $attachmentUuid);
        }
        $conversation = $this->conversationOf($row);
        if (!$conversation instanceof ChatConversation || !$this->voter->decide(ChatVoter::VIEW, $conversation, $viewer)) {
            throw new ChatNotFoundException('attachment', $attachmentUuid);
        }
        if ($row['message_id'] === null) {
            // A draft: its uploader only (exact key), never anyone else whatever its state.
            if ($row['attached_at'] !== null || $row['uploader_id'] === null || $row['uploader_id'] !== ChatUserReferences::key($viewer)) {
                throw new ChatNotFoundException('attachment', $attachmentUuid);
            }

            return [$row, null];
        }
        $message = $this->messages->find($row['message_id']);
        if ($row['state'] !== ChatAttachmentState::Clean->value || !$message instanceof ChatMessage || $message->isDeleted()
            || $message->getConversation()->getId() !== $conversation->getId()) {
            throw new ChatNotFoundException('attachment', $attachmentUuid);
        }

        return [$row, $message];
    }

    /**
     * Inside the send's unit, after the message was written: locks the drafts (id order) and attaches them, or refuses
     * the whole send.
     *
     * @param list<string> $uuids
     */
    private function attachAll(ChatMessage $message, ChatConversation $conversation, array $uuids, string $authorKey): void
    {
        $locked = $this->rows->lockForSend($uuids);
        $now    = $this->clock->now();
        foreach ($uuids as $position => $uuid) {
            $row = $locked[$uuid] ?? null;
            if ($row === null || $row['uploader_id'] !== $authorKey || $row['conversation_id'] !== $conversation->getId()
                || $row['message_id'] !== null || $row['attached_at'] !== null) {
                throw new ChatAttachmentRefused(ChatAttachmentRefused::INVALID);
            }
            if (in_array($row['state'], [ChatAttachmentState::Uploaded->value, ChatAttachmentState::Scanning->value], true)) {
                throw new ChatAttachmentRefused(ChatAttachmentRefused::NOT_READY);
            }
            if ($row['state'] !== ChatAttachmentState::Clean->value || !$this->rows->attach($row['id'], (int) $message->getId(), $position, $now)) {
                throw new ChatAttachmentRefused(ChatAttachmentRefused::INVALID);
            }
        }
    }

    /**
     * FR-010: the host's attach policy, when it has one (deny unless it allows; an exception is a refusal).
     *
     * @throws ChatAttachmentRefused `attachments_not_allowed`
     */
    private function assertMayAttach(ChatUserInterface $user, ChatConversation $conversation, ?string $contextKey): void
    {
        if (!$this->policy instanceof ChatAttachmentPolicyInterface) {
            return;
        }
        try {
            $allowed = $this->policy->mayAttach(new ChatComposerScope($user->getChatPublicId(), $conversation->getUuid(), $conversation->getKind()->value, $conversation->getChannelKey(), $contextKey));
        } catch (Throwable $exception) {
            $this->logger?->warning('ChatKit: the attachment policy failed; the files are refused.', ['policy' => $this->policy::class, 'exception' => $exception::class]);
            $allowed = false;
        }
        if (!$allowed) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::NOT_ALLOWED);
        }
    }

    /** The conversation the person may write in (read-only: `read_only`; unknown or unreadable: not found). */
    private function writable(ChatUserInterface $user, string $uuid): ChatConversation
    {
        $conversation = $this->readable($user, $uuid);
        if (!$this->voter->decide(ChatVoter::SEND, $conversation, $user)) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::READ_ONLY);
        }

        return $conversation;
    }

    /** The conversation whose uuid is exactly `$uuid`, when the person may read it now. */
    private function readable(ChatUserInterface $user, string $uuid): ChatConversation
    {
        $conversation = $this->conversations->findOneByUuid($uuid);
        if (!$conversation instanceof ChatConversation || $conversation->getUuid() !== $uuid || !$this->access->isReachable($user) || !$this->voter->decide(ChatVoter::VIEW, $conversation, $user)) {
            throw new ChatNotFoundException('conversation', $uuid);
        }

        return $conversation;
    }

    /**
     * @param AttachmentRow $row
     */
    private function conversationOf(array $row): ?ChatConversation
    {
        $conversation = $row['conversation_id'] === null ? null : $this->conversations->find($row['conversation_id']);

        return $conversation instanceof ChatConversation && $conversation->getUuid() === $row['conversation_uuid'] ? $conversation : null;
    }

    /** The answer of an earlier upload with the key, or null when the key is new. */
    private function replay(string $uploaderKey, string $key, string $hash): ?ChatAttachmentView
    {
        $row = $this->rows->findByKey($uploaderKey, $key);
        if ($row === null) {
            return null;
        }
        if ($row['request_hash'] !== $hash) {
            throw new ChatAttachmentRefused(ChatAttachmentRefused::IDEMPOTENCY_MISMATCH);
        }

        return ChatAttachmentViews::view($row, null);
    }

    /**
     * The bytes to StorageKit (outside any transaction), its refusals mapped to ChatKit's codes.
     */
    private function store(ChatUserInterface $uploader, string $conversationUuid, string $path, int $size, string $name, string $mediaType): StoredFileView
    {
        try {
            return $this->uploader->uploadFile($uploader, ChatAttachmentObjects::CONTEXT, $conversationUuid, new StoredFileSource($path, $size), $name, $mediaType);
        } catch (UploadRefused $refused) {
            throw match ($refused->reason) {
                UploadRefused::CONTEXT                    => new ChatNotFoundException('conversation', $conversationUuid),
                UploadRefused::TYPE                       => new ChatAttachmentRefused(ChatAttachmentRefused::TYPE_MISMATCH, $refused),
                UploadRefused::SIZE, UploadRefused::LIMIT => new ChatAttachmentRefused(ChatAttachmentRefused::FILE_TOO_LARGE, $refused),
                UploadRefused::EMPTY                      => new ChatAttachmentRefused(ChatAttachmentRefused::EMPTY_FILE, $refused),
                UploadRefused::SOURCE                     => new ChatAttachmentRefused(ChatAttachmentRefused::INVALID_FILE, $refused),
                default                                   => new ChatAttachmentRefused(ChatAttachmentRefused::STORAGE_UNAVAILABLE, $refused),
            };
        }
    }

    /** A stored copy whose row was not written: expired for StorageKit's purge (no bucket call, never throws). */
    private function discard(StoredFileView $stored, string $conversationUuid): void
    {
        try {
            $this->remover->expire($stored->uuid, ChatAttachmentObjects::CONTEXT, $conversationUuid);
        } catch (Throwable $exception) {
            $this->logger?->warning('ChatKit: a stored attachment copy could not be expired; StorageKit\'s purge removes orphans.', ['exception' => $exception::class]);
        }
    }

    private function queueScan(int $id): void
    {
        try {
            $this->bus?->dispatch(new ScanChatAttachment($id));
        } catch (Throwable $exception) {
            $this->logger?->warning('ChatKit: an attachment scan could not be queued; nowo:chat:attachments:sweep queues it.', ['exception' => $exception::class]);
        }
    }
}
