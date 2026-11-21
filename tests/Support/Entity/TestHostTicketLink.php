<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Entity\ChatConversation;

/**
 * 2.0 (audit lesson W1-2): a host entity relation-mapped to a ChatKit entity, written and flushed by the application's
 * own manager (a host "my tickets" row pointing at a support conversation, owned by a host user).
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_host_ticket_link')]
class TestHostTicketLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TestUser::class)]
        #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
        private TestUser $owner,
        #[ORM\ManyToOne(targetEntity: ChatConversation::class)]
        #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
        private ChatConversation $conversation,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): TestUser
    {
        return $this->owner;
    }

    public function getConversation(): ChatConversation
    {
        return $this->conversation;
    }
}
