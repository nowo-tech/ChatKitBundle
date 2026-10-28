<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Queries for @mentions: the rows of one message (kept in step by ChatMentions) and "messages that mention me" for a
 * host inbox, limited to conversations the user may still read.
 *
 * @extends ServiceEntityRepository<ChatMessageMention>
 */
class ChatMessageMentionRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatMessageMention entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessageMention::class);
    }

    /**
     * Ids of the users the message mentions.
     *
     * @param ChatMessage $message The message (a new, unpersisted message has none)
     *
     * @return list<int> User ids
     */
    public function userIdsOf(ChatMessage $message): array
    {
        if ($message->getId() === null) {
            return [];
        }
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('mm')
            ->select('IDENTITY(mm.user)')
            ->where('mm.message = :message')
            ->setParameter('message', $message)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    /**
     * Removes the message's mentions of everyone except the kept users (all of them when none is kept).
     *
     * @param ChatMessage $message The message
     * @param list<int> $keepUserIds User ids whose mention stays
     *
     * @return int Rows removed
     */
    public function removeFor(ChatMessage $message, array $keepUserIds = []): int
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->delete(ChatMessageMention::class, 'mm')
            ->where('mm.message = :message')
            ->setParameter('message', $message);
        if ($keepUserIds !== []) {
            $qb->andWhere('IDENTITY(mm.user) NOT IN (:keep)')->setParameter('keep', $keepUserIds);
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * Marks read the user's mentions in the conversation up to a message (the user read the thread that far).
     *
     * @param ChatUserInterface $user The reader
     * @param ChatConversation $conversation The conversation
     * @param int $messageId Id of the last message read
     * @param DateTimeImmutable $now Read time
     *
     * @return int Mentions marked read
     */
    public function markReadUpTo(ChatUserInterface $user, ChatConversation $conversation, int $messageId, DateTimeImmutable $now): int
    {
        $messages = $this->getEntityManager()->createQueryBuilder()
            ->select('m.id')
            ->from(ChatMessage::class, 'm')
            ->where('m.conversation = :conversation')
            ->andWhere('m.id <= :upTo');

        return (int) $this->getEntityManager()->createQueryBuilder()
            ->update(ChatMessageMention::class, 'mm')
            ->set('mm.readAt', ':now')
            ->where('mm.user = :user')
            ->andWhere('mm.readAt IS NULL')
            ->andWhere('IDENTITY(mm.message) IN (' . $messages->getDQL() . ')')
            ->setParameter('now', $now)
            ->setParameter('user', $user)
            ->setParameter('conversation', $conversation)
            ->setParameter('upTo', $messageId)
            ->getQuery()
            ->execute();
    }

    /**
     * The newest mentions of the user (message, conversation and author fetched in the same query), in
     * conversations the user may still read: direct threads, the given channels and, for support, the threads the
     * user owns (any thread for an agent). Deleted messages never count.
     *
     * @param ChatUserInterface $user The mentioned user
     * @param list<string> $channelKeys Channels the user may use today (ChatChannelProviderInterface::channelsFor())
     * @param bool $supportAgent Whether the user answers the support inbox
     * @param int $limit Maximum rows
     * @param bool $unreadOnly Only unread mentions
     * @param DateTimeImmutable|null $since Only mentions created at or after this time
     * @param bool $channelsOnly Only channel conversations (e.g. the host filters by one project)
     *
     * @return list<ChatMessageMention> Newest first
     */
    public function findForUser(
        ChatUserInterface $user,
        array $channelKeys,
        bool $supportAgent,
        int $limit,
        bool $unreadOnly = false,
        ?DateTimeImmutable $since = null,
        bool $channelsOnly = false,
    ): array {
        if ($limit <= 0) {
            return [];
        }
        /** @var list<ChatMessageMention> $rows */
        $rows = $this->visibleTo($user, $channelKeys, $supportAgent, $unreadOnly, $since, $channelsOnly)
            ->addSelect('m', 'c', 'author')
            ->leftJoin('m.author', 'author')
            ->orderBy('mm.createdAt', 'DESC')
            ->addOrderBy('mm.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Counts the mentions findForUser() would list (same arguments, no limit).
     *
     * @param ChatUserInterface $user The mentioned user
     * @param list<string> $channelKeys Channels the user may use today
     * @param bool $supportAgent Whether the user answers the support inbox
     * @param bool $unreadOnly Only unread mentions
     * @param DateTimeImmutable|null $since Only mentions created at or after this time
     * @param bool $channelsOnly Only channel conversations
     *
     * @return int The count
     */
    public function countForUser(
        ChatUserInterface $user,
        array $channelKeys,
        bool $supportAgent,
        bool $unreadOnly = false,
        ?DateTimeImmutable $since = null,
        bool $channelsOnly = false,
    ): int {
        return (int) $this->visibleTo($user, $channelKeys, $supportAgent, $unreadOnly, $since, $channelsOnly)
            ->select('COUNT(mm.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * One of the user's mentions by id.
     *
     * @param ChatUserInterface $user The mentioned user
     * @param int $id Mention id
     *
     * @return ChatMessageMention|null The mention, or null when it is not the user's
     */
    public function findOneForUser(ChatUserInterface $user, int $id): ?ChatMessageMention
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * Unread mentions of the user per conversation (one query), for an "@" badge in a conversation list.
     *
     * @param ChatUserInterface $user The mentioned user
     * @param list<int> $conversationIds Conversation ids
     *
     * @return array<int, int> conversation id => unread mentions (conversations without any are left out)
     */
    public function countUnreadByConversation(ChatUserInterface $user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }
        /** @var list<array{cid: int|string, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('mm')
            ->select('IDENTITY(m.conversation) AS cid', 'COUNT(mm.id) AS n')
            ->innerJoin('mm.message', 'm')
            ->where('mm.user = :user')
            ->andWhere('mm.readAt IS NULL')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('IDENTITY(m.conversation) IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $conversationIds)
            ->groupBy('m.conversation')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['cid']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @param list<string> $channelKeys
     */
    private function visibleTo(ChatUserInterface $user, array $channelKeys, bool $supportAgent, bool $unreadOnly, ?DateTimeImmutable $since, bool $channelsOnly): QueryBuilder
    {
        $qb = $this->createQueryBuilder('mm')
            ->innerJoin('mm.message', 'm')
            ->innerJoin('m.conversation', 'c')
            ->where('mm.user = :user')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('user', $user);

        $visible = [];
        if ($channelKeys !== []) {
            $visible[] = 'c.channelKey IN (:channels)';
            $qb->setParameter('channels', $channelKeys);
        }
        if (!$channelsOnly) {
            // Both people of a direct thread keep reading it; a support thread stays the owner's (any for an agent).
            $visible[] = 'c.kind = :direct';
            $visible[] = $supportAgent ? 'c.kind = :support' : '(c.kind = :support AND c.supportOwner = :user)';
            $qb->setParameter('direct', ConversationKind::Direct)->setParameter('support', ConversationKind::Support);
        }
        $qb->andWhere($visible === [] ? '1 = 0' : '(' . implode(' OR ', $visible) . ')');
        if ($unreadOnly) {
            $qb->andWhere('mm.readAt IS NULL');
        }
        if ($since instanceof DateTimeImmutable) {
            $qb->andWhere('mm.createdAt >= :since')->setParameter('since', $since);
        }

        return $qb;
    }
}
