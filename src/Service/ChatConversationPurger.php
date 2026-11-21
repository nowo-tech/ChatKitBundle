<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagedRows;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatReadMark;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Entity\ChatThreadRead;
use Nowo\ChatKitBundle\Event\ChatConversationDeletedEvent;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_chunk;
use function array_map;
use function array_values;

/**
 * 2.0: deletes a whole conversation through ChatKit's own (private) entity manager, for
 * ChatMessagingInterface::deleteConversation() and purgeChannel(). A system call: no user, no access check.
 *
 * One unit of work on ChatKit's runner: the conversation row is locked first (`SELECT … FOR UPDATE`), then every row
 * that hangs on it goes, children before parents (no database cascade is relied on): the mention, reaction, saved,
 * link-index, previous-text and thread-read rows of its messages, the read times and the participant rows, the pinned
 * links, the messages (thread replies before their roots) and the conversation. Forwards of its messages in other
 * conversations keep their neutral "Forwarded" marker (the pointers become null). ChatKit stores no files (no
 * attachments, no StorageKit objects), so nothing is left outside the database.
 *
 * Idempotent under concurrency (REQ-RUNTIME-003): a second call for the same conversation waits on the row lock and
 * then finds it gone (false), never an error. Stateless (FrankenPHP worker mode).
 *
 * @internal Call it through ChatMessagingInterface
 */
final readonly class ChatConversationPurger
{
    /** Message ids per bulk statement (keeps the `IN` lists bounded). */
    public const int CHUNK = 500;

    private TransactionRunnerInterface $transactions;

    /**
     * @param EntityManagerInterface $entityManager ChatKit's private entity manager
     * @param ChatConversationRepository $conversations Finds and locks the conversation
     * @param TransactionRunnerInterface|null $transactions ChatKit's runner; null builds the default one
     * @param EventDispatcherInterface|null $events Receives ChatConversationDeletedEvent; null dispatches none
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatConversationRepository $conversations,
        ?TransactionRunnerInterface $transactions = null,
        private ?EventDispatcherInterface $events = null,
    ) {
        $this->transactions = $transactions ?? new ChatKitTransactionRunner($entityManager);
    }

    /**
     * Deletes the conversation whose public uuid is exactly `$uuid` (compared byte for byte after the SQL lookup).
     *
     * @param string $uuid Public uuid of the conversation
     *
     * @return bool True when this call deleted it; false when no conversation has exactly this uuid (or another call deleted it first)
     */
    public function purgeConversation(string $uuid): bool
    {
        $conversation = $this->conversations->findOneByUuid($uuid);

        return $this->purge($conversation instanceof ChatConversation && $conversation->getUuid() === $uuid ? $conversation : null);
    }

    /**
     * Deletes the conversation of the application channel whose key is exactly `$channelKey` (house rule LQ: SQL lookup,
     * then a byte-for-byte re-check; a look-alike key or an invalid one finds nothing).
     *
     * @param string $channelKey The application channel key
     *
     * @return bool True when this call deleted it; false when no channel conversation has exactly this key
     */
    public function purgeChannel(string $channelKey): bool
    {
        return $this->purge($this->conversations->findChannel($channelKey));
    }

    private function purge(?ChatConversation $conversation): bool
    {
        $id = $conversation?->getId();
        if (!$conversation instanceof ChatConversation || $id === null) {
            return false;
        }
        $uuid = $conversation->getUuid();
        $kind = $conversation->getKind()->value;
        $key  = $conversation->getChannelKey();

        /** @var list<int>|null $messageIds */
        $messageIds = ChatKitWrites::runRecovering($this->transactions, $this->entityManager, fn (): ?array => $this->deleteRows($conversation, $id));
        if ($messageIds === null) {
            return false;
        }
        // After the commit: the bulk deletes bypassed ChatKit's manager, so every managed instance of a deleted row is
        // detached (a later find() answers null, in this request and in the next one of a worker), and managed rows
        // elsewhere that pointed at them (forwards) drop the pointer the database dropped. Nothing else is detached.
        ChatKitManagedRows::forgetGone($this->entityManager, ChatKitManagedRows::CONVERSATION_ROWS);
        $this->events?->dispatch(new ChatConversationDeletedEvent($id, $uuid, $kind, $key, $messageIds));

        return true;
    }

    /**
     * @return list<int>|null The deleted message ids; null when the row was gone once locked
     */
    private function deleteRows(ChatConversation $conversation, int $id): ?array
    {
        // The row lock first: a concurrent purge (or a writer of the conversation) queues here, and finds it gone after.
        if ($this->conversations->lockActivity($conversation) === null) {
            return null;
        }
        $qb         = $this->entityManager->createQueryBuilder(...);
        $messageIds = array_values(array_map(intval(...), $qb()->select('m.id')->from(ChatMessage::class, 'm')
            ->where('IDENTITY(m.conversation) = :c')->setParameter('c', $id)->getQuery()->getSingleColumnResult()));

        foreach (array_chunk($messageIds, self::CHUNK) as $ids) {
            foreach ([[ChatMessageMention::class, 'message'], [ChatReaction::class, 'message'], [ChatSavedMessage::class, 'message'], [ChatMessageLink::class, 'message'], [ChatMessageRevision::class, 'message'], [ChatThreadRead::class, 'root']] as [$entity, $field]) {
                $qb()->delete($entity, 'x')->where('IDENTITY(x.' . $field . ') IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
            }
            // Forwards of these messages (here or in other conversations) keep their neutral "Forwarded" marker.
            $qb()->update(ChatMessage::class, 'f')->set('f.forwardedFrom', ':none')->set('f.forwardedFromConversation', ':none')
                ->where('IDENTITY(f.forwardedFrom) IN (:ids)')->setParameter('none', null)->setParameter('ids', $ids)->getQuery()->execute();
        }
        $qb()->update(ChatMessage::class, 'f')->set('f.forwardedFromConversation', ':none')
            ->where('IDENTITY(f.forwardedFromConversation) = :c')->setParameter('none', null)->setParameter('c', $id)->getQuery()->execute();

        $participantIds = $qb()->select('p.id')->from(ChatParticipant::class, 'p')->where('IDENTITY(p.conversation) = :c')->setParameter('c', $id)->getQuery()->getSingleColumnResult();
        foreach (array_chunk($participantIds, self::CHUNK) as $ids) {
            $qb()->delete(ChatReadMark::class, 'rm')->where('IDENTITY(rm.participant) IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
        }
        $qb()->delete(ChatParticipant::class, 'p')->where('IDENTITY(p.conversation) = :c')->setParameter('c', $id)->getQuery()->execute();
        $qb()->delete(ChatConversationLink::class, 'l')->where('IDENTITY(l.conversation) = :c')->setParameter('c', $id)->getQuery()->execute();
        // Thread replies before their roots (the self-reference), then the rest, then the conversation.
        $qb()->delete(ChatMessage::class, 'm')->where('IDENTITY(m.conversation) = :c AND m.threadRoot IS NOT NULL')->setParameter('c', $id)->getQuery()->execute();
        $qb()->delete(ChatMessage::class, 'm')->where('IDENTITY(m.conversation) = :c')->setParameter('c', $id)->getQuery()->execute();
        $qb()->delete(ChatConversation::class, 'c')->where('c.id = :c')->setParameter('c', $id)->getQuery()->execute();

        return $messageIds;
    }
}
