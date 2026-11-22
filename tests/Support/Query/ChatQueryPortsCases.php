<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Query;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatAccessInterface;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Contract\ChatQueryInterface;
use Nowo\ChatKitBundle\Contract\ChatSupportInboxInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatConversationListItem;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatSupportTicketItem;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_column;
use function array_keys;
use function array_map;
use function assert;
use function strlen;
use function strtoupper;

/**
 * 2.2 (DDD-host checklist B7/B8): the host query ports — ChatQueryInterface, ChatAccessInterface and
 * ChatSupportInboxInterface — answer with views, uuids and numbers only; by a user object or its public id; with the JSON
 * API's rules (the access answers equal ChatMessagingInterface::can()); and unknown, look-alike (other case, padded) and
 * forbidden ids give the same answer. Used on SQLite in both user-reference modes (tests/Integration/Query) and on the
 * servers of CHAT_KIT_RACE_DATABASE_URL (tests/Database), where the SQL compare of a folding collation matches the
 * look-alike and only the PHP re-check refuses it.
 */
trait ChatQueryPortsCases
{
    public function testTheQueryPortListsWhatTheUserMayReadAsViews(): void
    {
        [$ana, $luis, $eve, $stranger, $agent] = $this->people();
        $messaging                             = $this->port(ChatMessagingInterface::class);
        $query                                 = $this->port(ChatQueryInterface::class);

        $direct  = $messaging->openDirect($ana, $luis);
        $hello   = $messaging->send($ana, $direct->uuid, 'hello');
        $hi      = $messaging->send($luis, $direct->uuid, 'hi');
        $channel = $messaging->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $messaging->send($ana, $channel->uuid, 'channel news');
        $ticket = $this->ticket($ana);

        // Luis: the direct conversation and the channel, never Ana's ticket; by object and by public id alike.
        $list = $query->conversationsFor($luis);
        self::assertContainsOnlyInstancesOf(ChatConversationListItem::class, $list);
        self::assertSame([$channel->uuid, $direct->uuid], $this->uuids($list));
        self::assertSame($this->uuids($list), $this->uuids($query->conversationsFor($luis->getChatPublicId())));
        $byUuid = [];
        foreach ($list as $item) {
            $byUuid[$item->conversation->uuid] = $item;
        }
        self::assertSame([0, 'hi', false, null], [$byUuid[$direct->uuid]->unread, $byUuid[$direct->uuid]->lastMessage?->body, $byUuid[$direct->uuid]->archived, $byUuid[$direct->uuid]->mutedUntil]);
        self::assertSame(1, $byUuid[$channel->uuid]->unread);
        self::assertSame([$direct->uuid], $this->uuids($query->conversationsFor($luis, kinds: ['direct', 'nope'])));
        self::assertSame([$channel->uuid], $this->uuids($query->conversationsFor($luis, limit: 1)));
        self::assertSame([], $this->uuids($query->conversationsFor($luis, before: new DateTimeImmutable('-1 day'))), 'an older page');
        self::assertCount(2, $query->conversationsFor($luis, before: new DateTimeImmutable('+1 day')));
        // An agent sees every ticket holding a message; a stranger no channel.
        self::assertContains($ticket, $this->uuids($query->conversationsFor($agent, kinds: ['support'])));
        self::assertSame([], $this->uuids($query->conversationsFor($stranger)));
        foreach (['', 'nobody', strtoupper($luis->getChatPublicId()), ' ' . $luis->getChatPublicId(), $luis->getChatPublicId() . "\u{200B}"] as $unknown) {
            self::assertSame([], $query->conversationsFor($unknown), 'unknown and look-alike public ids: ' . $unknown);
        }

        // Closing moves a conversation to the archived list; muting shows.
        $messaging->archive($luis, [$direct->uuid], true);
        $messaging->mute($luis, $channel->uuid, new DateTimeImmutable('+1 hour'));
        self::assertSame([$channel->uuid], $this->uuids($query->conversationsFor($luis)));
        self::assertNotNull($query->conversationsFor($luis)[0]->mutedUntil);
        $closed = $query->conversationsFor($luis, archived: true);
        self::assertSame([[$direct->uuid, true]], array_map(static fn (ChatConversationListItem $item): array => [$item->conversation->uuid, $item->archived], $closed));

        // One conversation and its messages: readable, or the same null / [] for unknown, look-alike and forbidden.
        self::assertInstanceOf(ChatConversationView::class, $query->conversation($luis, $direct->uuid));
        self::assertSame($direct->uuid, $query->conversation($luis->getChatPublicId(), $direct->uuid)?->uuid);
        $page = $query->messages($luis, $direct->uuid);
        self::assertContainsOnlyInstancesOf(ChatMessageView::class, $page);
        self::assertSame([$hello->uuid, $hi->uuid], array_column($page, 'uuid'));
        self::assertSame([$hello->uuid], array_column($query->messages($luis, $direct->uuid, beforeId: $hi->id), 'uuid'));
        self::assertSame([$hi->uuid], array_column($query->messages($luis, $direct->uuid, afterId: $hello->id), 'uuid'));
        self::assertSame([$hi->uuid], array_column($query->messages($luis, $direct->uuid, 0), 'uuid'), 'the limit is at least 1');
        foreach ($this->lookAlikes($direct->uuid) as $uuid) {
            self::assertNull($query->conversation($luis, $uuid));
            self::assertSame([], $query->messages($luis, $uuid));
        }
        foreach ([$eve, $stranger, $eve->getChatPublicId(), 'nobody'] as $outsider) {
            self::assertNull($query->conversation($outsider, $direct->uuid));
            self::assertSame([], $query->messages($outsider, $direct->uuid));
        }

        // Unread counts: only the readable conversations, exactly the uuids asked.
        $asked = [$direct->uuid, $channel->uuid, $ticket, strtoupper($direct->uuid), $direct->uuid . ' ', '00000000-0000-7000-8000-000000000000', ''];
        self::assertSame([$channel->uuid => 1], $query->unreadCounts($luis, [$channel->uuid]));
        self::assertSame([$direct->uuid => 0, $channel->uuid => 1], $query->unreadCounts($luis, $asked));
        self::assertSame([$direct->uuid => 1], $query->unreadCounts($ana->getChatPublicId(), [$direct->uuid]));
        self::assertSame([$channel->uuid => 1], $query->unreadCounts($eve, $asked), 'Eve reads the channel only');
        self::assertSame([], $query->unreadCounts('nobody', $asked));

        // System reads: exact uuids and channel keys, no access check.
        self::assertSame([$direct->uuid, $channel->uuid, $ticket], array_keys($query->conversationsByUuids($asked)));
        self::assertSame([], $query->conversationsByUuids([]));
        $keys = [TestChannelProvider::KEY, strtoupper(TestChannelProvider::KEY), TestChannelProvider::KEY . ' ', ' ' . TestChannelProvider::KEY, TestChannelProvider::ARCHIVE_KEY, ''];
        self::assertSame([TestChannelProvider::KEY], array_keys($query->channelConversations($keys)));
        self::assertSame($channel->uuid, $query->channelConversations($keys)[TestChannelProvider::KEY]->uuid);
        self::assertSame([], $query->channelConversations(['', " \t"]));
        self::assertSame(1, $query->countChannelConversations($keys));
        self::assertSame(0, $query->countChannelConversations([strtoupper(TestChannelProvider::KEY)]));
    }

    public function testTheUsageCountsAreExact(): void
    {
        [$ana, $luis, $eve] = $this->people();
        $messaging          = $this->port(ChatMessagingInterface::class);
        $query              = $this->port(ChatQueryInterface::class);
        $direct             = $messaging->openDirect($ana, $luis);
        $messaging->send($ana, $direct->uuid, 'hello');
        $messaging->send($ana, $direct->uuid, 'again');
        $messaging->send($luis, $direct->uuid, 'hi');
        $channel = $messaging->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $messaging->send($luis, $channel->uuid, 'channel news');

        self::assertSame(strlen('channel news'), $query->channelBodyLength([TestChannelProvider::KEY, TestChannelProvider::KEY]));
        self::assertSame(0, $query->channelBodyLength([strtoupper(TestChannelProvider::KEY), TestChannelProvider::KEY . ' ', TestChannelProvider::ARCHIVE_KEY]));
        self::assertSame(0, $query->channelBodyLength([]));
        // Direct messages of the authors only (Luis' channel message is not counted).
        self::assertSame(strlen('helloagain'), $query->directBodyLengthByAuthors([$ana->getChatPublicId()]));
        self::assertSame(strlen('helloagainhi'), $query->directBodyLengthByAuthors([$ana->getChatPublicId(), $luis->getChatPublicId(), $luis->getChatPublicId(), $eve->getChatPublicId()]));
        self::assertSame(0, $query->directBodyLengthByAuthors([strtoupper($ana->getChatPublicId()), $ana->getChatPublicId() . ' ', 'nobody', '']));
    }

    public function testAccessByUuidMatchesTheMessagingRules(): void
    {
        [$ana, $luis, $eve, $stranger, $agent] = $this->people();
        $reader                                = $this->person('Reader Rita');
        $messaging                             = $this->port(ChatMessagingInterface::class);
        $access                                = $this->port(ChatAccessInterface::class);
        $direct                                = $messaging->openDirect($ana, $luis);
        $messaging->send($ana, $direct->uuid, 'hello');
        $channel = $messaging->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $archive = $messaging->openChannel($ana, new ChatChannel(TestChannelProvider::ARCHIVE_KEY, 'Archive'));
        $ticket  = $this->ticket($ana);
        $draft   = $this->port(ChatSupportTickets::class)->open($luis)->getUuid();

        $same = [
            'canView' => ChatMessagingInterface::VIEW,
            'canPost' => ChatMessagingInterface::SEND,
            'canPin'  => ChatMessagingInterface::PIN,
        ];
        foreach ([$ana, $luis, $eve, $stranger, $agent, $reader] as $person) {
            foreach ([$direct->uuid, $channel->uuid, $archive->uuid, $ticket, $draft] as $uuid) {
                foreach ($same as $method => $action) {
                    $expected = $messaging->can($person, $uuid, $action);
                    self::assertSame($expected, $access->{$method}($person, $uuid), $method . ' ' . $person->getChatDisplayName());
                    self::assertSame($expected, $access->{$method}($person->getChatPublicId(), $uuid), $method . ' by public id');
                    foreach ($this->lookAlikes($uuid) as $lookAlike) {
                        self::assertFalse($access->{$method}($person, $lookAlike));
                    }
                }
            }
        }
        // A few answers spelled out.
        self::assertSame([true, false, false], [$access->canView($luis, $direct->uuid), $access->canView($eve, $direct->uuid), $access->canView('nobody', $direct->uuid)]);
        self::assertSame([true, false], [$access->canView($reader, $channel->uuid), $access->canPost($reader, $channel->uuid)], 'a reader of the channel');
        self::assertFalse($access->canPost($ana, $archive->uuid), 'a read-only channel');
        self::assertSame([true, false, false], [$access->canManageLinks($ana, $direct->uuid), $access->canManageLinks($eve, $direct->uuid), $access->canManageLinks($reader, $channel->uuid)]);
        self::assertTrue($access->canManageLinks($luis, $channel->uuid), 'every channel member by default');
        self::assertFalse($access->canManageLinks($luis, strtoupper($channel->uuid)));

        // Tickets: the requester and agents read it, only agents handle it; a draft or a direct conversation is no ticket.
        self::assertSame([true, true, false, false], [$access->canViewTicket($ana, $ticket), $access->canViewTicket($agent, $ticket), $access->canViewTicket($luis, $ticket), $access->canViewTicket($ana, $direct->uuid)]);
        self::assertSame([false, true, false], [$access->canHandleTicket($ana, $ticket), $access->canHandleTicket($agent->getChatPublicId(), $ticket), $access->canHandleTicket($agent, $draft)]);
        self::assertFalse($access->canViewTicket($luis, $draft), 'an intake draft is not a ticket');

        // Batch: exactly the readable uuids asked, once each, in the order asked.
        $asked = [$ticket, $direct->uuid, strtoupper($channel->uuid), $channel->uuid, $direct->uuid, '00000000-0000-7000-8000-000000000000', ''];
        self::assertSame([$direct->uuid, $channel->uuid], $access->viewableConversationUuids($luis, $asked));
        self::assertSame([$ticket, $direct->uuid, $channel->uuid], $access->viewableConversationUuids($ana->getChatPublicId(), $asked));
        self::assertSame([], $access->viewableConversationUuids($eve, [$direct->uuid, $ticket]));
        self::assertSame([], $access->viewableConversationUuids('nobody', $asked));
        self::assertSame([], $access->viewableConversationUuids($luis, []));
    }

    public function testTheSupportInboxPortByIds(): void
    {
        [$ana, $luis, , , $agent] = $this->people();
        $other                    = $this->person('Agent Max');
        $inbox                    = $this->port(ChatSupportInboxInterface::class);
        $uuid                     = $this->ticket($ana);

        $rows = $inbox->tickets($agent);
        self::assertContainsOnlyInstancesOf(ChatSupportTicketItem::class, $rows);
        self::assertSame([$uuid], array_map(static fn (ChatSupportTicketItem $item): string => $item->ticket->uuid, $rows));
        self::assertSame([$ana->getChatPublicId(), 1, 'billing'], [$rows[0]->ticket->supportOwnerPublicId, $rows[0]->requesterTickets, $rows[0]->ticket->ticketTopic]);
        self::assertGreaterThan(0, $rows[0]->unread);
        self::assertNotNull($rows[0]->lastMessage);
        self::assertCount(1, $inbox->tickets($agent->getChatPublicId(), 'all', 'billing', 'ana', 0));
        self::assertSame([], $inbox->tickets($agent, 'all', 'billing', 'nobody-matches'));
        self::assertSame([], $inbox->tickets($agent, 'open', 'incident'));
        self::assertSame([1, ['billing' => 1]], [$inbox->countOpen($agent), $inbox->countByTopic($agent->getChatPublicId())]);
        self::assertSame($uuid, $inbox->ticket($agent, $uuid)?->uuid);
        // Anybody but an agent, and unknown or look-alike agents: nothing.
        foreach ([$ana, $luis, $ana->getChatPublicId(), strtoupper($agent->getChatPublicId()), 'nobody'] as $notAgent) {
            self::assertSame([[], 0, [], null], [$inbox->tickets($notAgent), $inbox->countOpen($notAgent), $inbox->countByTopic($notAgent), $inbox->ticket($notAgent, $uuid)]);
        }
        foreach ($this->lookAlikes($uuid) as $lookAlike) {
            self::assertNull($inbox->ticket($agent, $lookAlike));
        }

        // Assign: to an agent by public id, then unassign; an unknown, look-alike or non-agent assignee is one refusal.
        $assigned = $inbox->assign($agent, $uuid, $other->getChatPublicId());
        self::assertSame($other->getChatPublicId(), $assigned->assigneePublicId);
        self::assertSame([$uuid], array_map(static fn (ChatSupportTicketItem $item): string => $item->ticket->uuid, $inbox->tickets($other, 'mine')));
        self::assertNull($inbox->assign($agent->getChatPublicId(), $uuid, null)->assigneePublicId);
        foreach ([$luis->getChatPublicId(), strtoupper($other->getChatPublicId()), 'nobody', ''] as $assignee) {
            try {
                $inbox->assign($agent, $uuid, $assignee);
                self::fail('assigned to ' . $assignee);
            } catch (ChatNotFoundException $unexpected) {
                throw $unexpected;
            } catch (InvalidArgumentException $refused) {
                self::assertSame('assignee', $refused->getMessage());
            }
        }

        // Status as an agent.
        self::assertSame('waiting', $inbox->changeStatus($agent, $uuid, 'waiting')->supportStatus);
        self::assertSame('waiting', $inbox->changeStatus($agent, $uuid, 'waiting')->supportStatus, 'no change, no error');
        try {
            $inbox->changeStatus($agent, $uuid, 'Waiting');
            self::fail('a look-alike status was accepted');
        } catch (ChatNotFoundException $unexpected) {
            throw $unexpected;
        } catch (InvalidArgumentException $refused) {
            self::assertSame('status', $refused->getMessage());
        }

        // Not an agent, an unknown or look-alike uuid, a draft: the same "not found", before anything is written.
        $draft = $this->port(ChatSupportTickets::class)->open($luis)->getUuid();
        $calls = [];
        foreach ([...$this->lookAlikes($uuid), $draft] as $wrong) {
            $calls[] = static fn () => $inbox->assign($agent, $wrong, null);
            $calls[] = static fn () => $inbox->changeStatus($agent, $wrong, 'open');
        }
        $calls[] = static fn () => $inbox->assign($ana, $uuid, null);
        $calls[] = static fn () => $inbox->changeStatus($ana->getChatPublicId(), $uuid, 'open');
        $calls[] = static fn () => $inbox->changeStatus('nobody', $uuid, 'open');
        foreach ($calls as $i => $call) {
            try {
                $call();
                self::fail('call ' . $i . ' was accepted');
            } catch (ChatNotFoundException $exception) {
                self::assertSame('not_found', $exception->getMessage());
            }
        }
        self::assertSame('waiting', $inbox->ticket($agent, $uuid)?->supportStatus);
    }

    /**
     * Ana, Luis, Eve, a stranger (no channel) and a support agent.
     *
     * @return array{0: TestUser, 1: TestUser, 2: TestUser, 3: TestUser, 4: TestUser}
     */
    private function people(): array
    {
        return [$this->person('Ana'), $this->person('Luis'), $this->person('Eve'), $this->person('Stranger Sam'), $this->person('Agent Zoe')];
    }

    private function person(string $name): TestUser
    {
        return $this->user($name);
    }

    /**
     * A numbered ticket of the requester (topic `billing`), holding the topic's bot reply.
     */
    private function ticket(ChatUserInterface $requester): string
    {
        $tickets = $this->port(ChatSupportTickets::class);
        $draft   = $tickets->open($requester);
        $tickets->chooseTopic($requester, $draft, 'billing');
        $uuid = $draft->getUuid();
        assert($draft instanceof ChatConversation);

        return $uuid;
    }

    /**
     * @return list<string>
     */
    private function lookAlikes(string $uuid): array
    {
        return [strtoupper($uuid), $uuid . ' ', ' ' . $uuid, $uuid . "\u{200B}", '00000000-0000-7000-8000-000000000000', ''];
    }

    /**
     * @param list<ChatConversationListItem> $items
     *
     * @return list<string>
     */
    private function uuids(array $items): array
    {
        return array_map(static fn (ChatConversationListItem $item): string => $item->conversation->uuid, $items);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function port(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $service = $container->get($id);
        assert($service instanceof $id);

        return $service;
    }
}
