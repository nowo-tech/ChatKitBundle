<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function array_key_exists;
use function count;
use function in_array;

/**
 * Queries for @mentions: the rows of one message (kept in step by ChatMentions) and "messages that mention me" for a
 * host inbox, limited to conversations the user may still read.
 *
 * @extends ServiceEntityRepository<ChatMessageMention>
 */
class ChatMessageMentionRepository extends ServiceEntityRepository
{
    /** 1.27: rows read at most to fill one page with exact matches (REQ-SEC-006 item 6). */
    private const int EXACT_SCAN = 1000;

    /**
     * Builds the repository for ChatMessageMention entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatMessageMention::class);
    }

    /**
     * Ids of the users the message mentions.
     *
     * @param ChatMessage $message The message (a new, unpersisted message has none)
     *
     * @return list<int|string> User ids (1.27: or string references, ChatUserReferences::idOrKey())
     */
    public function userIdsOf(ChatMessage $message): array
    {
        if ($message->getId() === null) {
            return [];
        }
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('mm')
            ->select($this->refs->identity('mm', 'user'))
            ->where('mm.message = :message')
            ->setParameter('message', $message)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(ChatUserReferences::idOrKey(...), $ids));
    }

    /**
     * Removes the message's mentions of everyone except the kept users (all of them when none is kept).
     *
     * @param ChatMessage $message The message
     * @param list<int|string> $keepUserIds User keys whose mention stays
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
            $qb->andWhere($this->refs->identity('mm', 'user') . ' NOT IN (:keep)')->setParameter('keep', $this->refs->ids($keepUserIds));
        }

        return (int) ChatKitSavepoint::write($this->getEntityManager(), static fn (): mixed => $qb->getQuery()->execute());
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

        if ($this->refs->isIdMode()) {
            // 1.27 (audit OJ-M1, REQ-SEC-006 item 2): select, keep the rows of exactly this user, update them by key.
            $key = ChatUserReferences::key($user);
            $ids = [];
            foreach ($this->createQueryBuilder('mm')
                ->select('mm.id AS id', 'mm.userRef AS ref')
                ->where('mm.userRef = :user AND mm.readAt IS NULL')
                ->andWhere('IDENTITY(mm.message) IN (' . $messages->getDQL() . ')')
                ->setParameter('user', $this->refs->value($user))
                ->setParameter('conversation', $conversation)
                ->setParameter('upTo', $messageId)
                ->getQuery()
                ->getArrayResult() as $row) {
                if ($key !== null && $row['ref'] === $key) {
                    $ids[] = (int) $row['id'];
                }
            }

            return $ids === [] ? 0 : (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->getEntityManager()->createQueryBuilder()
                ->update(ChatMessageMention::class, 'mm')
                ->set('mm.readAt', ':now')
                ->where('mm.id IN (:ids)')
                ->setParameter('now', $now)
                ->setParameter('ids', $ids)
                ->getQuery()
                ->execute());
        }

        return (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->getEntityManager()->createQueryBuilder()
            ->update(ChatMessageMention::class, 'mm')
            ->set('mm.readAt', ':now')
            ->where($this->refs->field('mm', 'user') . ' = :user')
            ->andWhere('mm.readAt IS NULL')
            ->andWhere('IDENTITY(mm.message) IN (' . $messages->getDQL() . ')')
            ->setParameter('now', $now)
            ->setParameter('user', $this->refs->value($user))
            ->setParameter('conversation', $conversation)
            ->setParameter('upTo', $messageId)
            ->getQuery()
            ->execute());
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

        // The SQL IN runs on the column collation: keep only the exact channel keys (look-alikes, house rule LQ) and
        // (1.27, audit OJ-M1) the rows of exactly this user; pages are read until `$limit` exact rows are found, so a
        // look-alike's rows never push the user's own past the limit (REQ-SEC-006 item 6).
        $kept   = [];
        $offset = 0;
        do {
            /** @var list<ChatMessageMention> $rows */
            $rows = $this->visibleTo($user, $channelKeys, $supportAgent, $unreadOnly, $since, $channelsOnly, true)
                ->addSelect('m', 'c')
                ->orderBy('mm.createdAt', 'DESC')
                ->addOrderBy('mm.id', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
            foreach ($rows as $mention) {
                if (count($kept) < $limit && self::exactChannel($mention->getMessage()->getConversation(), $channelKeys) && ChatUserReferences::same($mention->getUser(), $user)) {
                    $kept[] = $mention;
                }
            }
            $offset += $limit;
        } while (count($rows) === $limit && count($kept) < $limit && $offset < self::EXACT_SCAN);

        return $kept;
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
        // One row per conversation, so the channel key is matched again in PHP (look-alikes, house rule LQ).
        // 1.27 (audit OJ-M1): per stored user reference too, counted only for exactly this user.
        $qb = $this->visibleTo($user, $channelKeys, $supportAgent, $unreadOnly, $since, $channelsOnly)
            ->select('c.channelKey AS k', 'COUNT(mm.id) AS n')
            ->groupBy('c.id');
        if ($this->refs->isIdMode()) {
            $qb->addSelect('mm.userRef AS ref')->addGroupBy('mm.userRef');
        }
        /** @var list<array{k: string|null, n: int|string, ref?: string|null}> $rows */
        $rows  = $qb->getQuery()->getArrayResult();
        $key   = ChatUserReferences::key($user);
        $count = 0;
        foreach ($rows as $row) {
            $exactUser = !array_key_exists('ref', $row) || ($key !== null && $row['ref'] === $key);
            $count += $exactUser && ($row['k'] === null || in_array($row['k'], $channelKeys, true)) ? (int) $row['n'] : 0;
        }

        return $count;
    }

    /**
     * Whether a mention's conversation passes the PHP re-check: not a channel, or a channel whose key is exactly one of
     * the given keys.
     *
     * @param list<string> $channelKeys
     */
    private static function exactChannel(ChatConversation $conversation, array $channelKeys): bool
    {
        return !$conversation->isChannel() || in_array($conversation->getChannelKey(), $channelKeys, true);
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
        $row = $this->findOneBy(['id' => $id] + $this->refs->criteria('user', $user));

        // REQ-SEC-006 item 2: exactly this user's row.
        return $row instanceof ChatMessageMention && ChatUserReferences::same($row->getUser(), $user) ? $row : null;
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
        $qb = $this->createQueryBuilder('mm')
            ->select('IDENTITY(m.conversation) AS cid', 'COUNT(mm.id) AS n')
            ->innerJoin('mm.message', 'm')
            ->where($this->refs->field('mm', 'user') . ' = :user')
            ->andWhere('mm.readAt IS NULL')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('IDENTITY(m.conversation) IN (:ids)')
            ->setParameter('user', $this->refs->value($user))
            ->setParameter('ids', $conversationIds)
            ->groupBy('m.conversation');
        if ($this->refs->isIdMode()) {
            // 1.27 (audit OJ-M1): counted per stored reference, kept only for exactly this user.
            $qb->addSelect('mm.userRef AS ref')->addGroupBy('mm.userRef');
        }
        /** @var list<array{cid: int|string, n: int|string, ref?: string|null}> $rows */
        $rows   = $qb->getQuery()->getArrayResult();
        $key    = ChatUserReferences::key($user);
        $counts = [];
        foreach ($rows as $row) {
            if (array_key_exists('ref', $row) && ($key === null || $row['ref'] !== $key)) {
                continue;
            }
            $counts[(int) $row['cid']] = ($counts[(int) $row['cid']] ?? 0) + (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @param list<string> $channelKeys
     */
    private function visibleTo(ChatUserInterface $user, array $channelKeys, bool $supportAgent, bool $unreadOnly, ?DateTimeImmutable $since, bool $channelsOnly, bool $withAuthors = false): QueryBuilder
    {
        $qb = $this->createQueryBuilder('mm')
            ->innerJoin('mm.message', 'm')
            ->innerJoin('m.conversation', 'c')
            ->where($this->refs->field('mm', 'user') . ' = :user')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('user', $this->refs->value($user));
        if ($withAuthors) {
            $this->refs->join($qb, 'm.author', 'author');
        }

        $visible = [];
        if ($channelKeys !== []) {
            $visible[] = 'c.channelKey IN (:channels)';
            $qb->setParameter('channels', $channelKeys);
        }
        if (!$channelsOnly) {
            // Both people of a direct thread keep reading it; a support thread stays the owner's (any for an agent).
            $visible[] = 'c.kind = :direct';
            $visible[] = $supportAgent ? 'c.kind = :support' : '(c.kind = :support AND ' . $this->refs->field('c', 'supportOwner') . ' = :user)';
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
