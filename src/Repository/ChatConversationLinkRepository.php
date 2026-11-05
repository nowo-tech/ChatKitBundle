<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;

/**
 * 1.19: the pinned links of a conversation.
 *
 * @extends ServiceEntityRepository<ChatConversationLink>
 */
class ChatConversationLinkRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatConversationLink entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatConversationLink::class);
    }

    /**
     * The pinned links of a conversation, oldest first (their authors fetched in the same query).
     *
     * @param ChatConversation $conversation The conversation
     *
     * @return list<ChatConversationLink>
     */
    public function forConversation(ChatConversation $conversation): array
    {
        /** @var list<ChatConversationLink> $links */
        $links = $this->createQueryBuilder('l')
            ->addSelect('author')
            ->leftJoin('l.createdBy', 'author')
            ->where('l.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $links;
    }

    /**
     * One pinned link of the conversation by its public id.
     *
     * @param ChatConversation $conversation The conversation
     * @param string $uuid The link's public id
     *
     * @return ChatConversationLink|null The link, or null when it is not one of the conversation's
     */
    public function findOneInConversation(ChatConversation $conversation, string $uuid): ?ChatConversationLink
    {
        return $this->findOneBy(['conversation' => $conversation, 'uuid' => $uuid]);
    }
}
