<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Support;

use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Event\SupportTicketAssignedEvent;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Configured topics: a topic's default assignee takes new tickets (only when they answer support), a reply "" posts
 * none; the entity refuses invalid ticket data.
 */
final class ChatSupportDefaultAssigneeTest extends DatabaseTestCase
{
    private const string AGENT = '00000000-0000-7000-8000-000000000001';

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['support' => ['ticket_prefix' => 'T-', 'ticket_digits' => 3, 'topics' => [
            ['key' => 'sales', 'assignee' => self::AGENT, 'reply' => ''],
            ['key' => 'misc', 'assignee' => 'nobody'],
        ]]]]];
    }

    public function testTheTopicsDefaultAgentTakesTheTicket(): void
    {
        $agent = new TestUser('Agent Sales', null);
        (new ReflectionProperty(TestUser::class, 'publicId'))->setValue($agent, self::AGENT);
        $this->em->persist($agent);
        $this->em->flush();
        $assigned = [];
        $this->get(EventDispatcherInterface::class)->addListener(SupportTicketAssignedEvent::class, static function (SupportTicketAssignedEvent $event) use (&$assigned): void {
            $assigned[] = $event;
        });
        $tickets = $this->get(ChatSupportTickets::class);
        $ana     = $this->user('Ana');

        $ticket = $tickets->open($ana);
        $posted = $tickets->chooseTopic($ana, $ticket, 'sales');
        self::assertCount(1, $posted, 'reply "" posts no automatic reply');
        self::assertSame($agent, $ticket->getAssignee());
        self::assertCount(1, $assigned);
        self::assertSame($ana, $assigned[0]->by);
        self::assertSame('T-001', $this->get(ChatPresenter::class)->ticket($ticket)['number'] ?? null);

        $other = $tickets->open($this->user('Bob'));
        $tickets->chooseTopic($other->getSupportOwner() ?? $ana, $other, 'misc');
        self::assertNull($other->getAssignee(), 'an unknown assignee is ignored');
    }

    public function testTheEntityRefusesInvalidTicketData(): void
    {
        $draft = ChatConversation::support(new TestUser('Ana', null));
        foreach ([static fn () => $draft->openTicket(0, 'billing'), static fn () => $draft->changeTopic('')] as $attempt) {
            try {
                $attempt();
                self::fail('invalid');
            } catch (LogicException) {
                self::assertTrue($draft->isSupportDraft());
            }
        }
    }
}
