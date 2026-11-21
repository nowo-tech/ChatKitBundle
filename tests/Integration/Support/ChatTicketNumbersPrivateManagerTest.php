<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Support;

use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.30: with the private entity manager the ticket number is written beside its unit of work, at the ticket's
 * transaction level, and the manager knows it (a rollback of that level makes its identity map stale).
 */
#[Group('private-manager')]
final class ChatTicketNumbersPrivateManagerTest extends DatabaseTestCase
{
    protected function hostFixtures(): bool
    {
        return true;
    }

    public function testTheNumberIsWrittenThroughThePrivateManager(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $ana     = $this->user('Ana');
        $draft   = $tickets->open($ana);
        $tickets->chooseTopic($ana, $draft, 'billing');

        self::assertInstanceOf(ChatKitEntityManager::class, $this->get(ChatKitEntityManager::class));
        self::assertSame(1, $draft->getTicketNumber());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT ticket_number FROM nowo_chat_conversation WHERE id = ?', [$draft->getId()]));
    }
}
