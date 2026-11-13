<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Link;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Command\ChatLinksBackfillCommand;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Event\ChatLinkRemovedEvent;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatConversationLinkRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_filter;
use function array_map;
use function count;

/**
 * 1.19: the link index follows the messages (send, edit, delete, purge, erasure), the backfill command fills it for
 * older messages; the erasure removes the user's pinned links, the purge the stale ones, and the limit holds under a
 * concurrent add.
 */
final class ChatLinksIndexTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return match ($this->name()) {
            'testBackfillWhenDisabled'             => ['profiles' => ['default' => ['links' => ['enabled' => false]]]],
            'testTheLimitHoldsUnderAConcurrentAdd' => ['profiles' => ['default' => ['links' => ['max_pinned' => 1]]]],
            default                                => [],
        };
    }

    public function testErasureRemovesThePinnedLinksOfTheUser(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $agent   = $this->user('Agent Smith');
        $chat    = $this->get(ChatService::class);
        $links   = $this->get(ChatLinks::class);
        $dm      = $chat->openDirect($ana, $luis);
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $mine    = $links->add($ana, $dm, 'https://teams.microsoft.com/l/x', 'Ana 1:1');
        $inChan  = $links->add($ana, $channel, 'https://zoom.us/j/1?pwd=secret', null);
        $his     = $links->add($luis, $dm, 'https://his.example/board', 'Board');
        $chat->send($ana, $dm, 'mine https://mine.example/');
        $chat->send($luis, $dm, 'his https://his.example/');
        $ticket = $chat->openSupport($ana);
        $chat->send($ana, $ticket, 'see https://ticket.example/');
        $links->add($agent, $ticket, 'https://zoom.us/j/2', null);
        $links->add($ana, $ticket, 'https://zoom.us/j/3', null);

        $export = $this->get(ChatPersonalData::class)->export($ana);
        self::assertSame(['https://teams.microsoft.com/l/x', 'https://zoom.us/j/1?pwd=secret', 'https://zoom.us/j/3'], array_column($export['links'], 'url'));

        /** @var list<ChatLinkRemovedEvent> $removed */
        $removed = [];
        $this->get(EventDispatcherInterface::class)->addListener(ChatLinkRemovedEvent::class, static function (ChatLinkRemovedEvent $event) use (&$removed): void {
            $removed[] = $event;
        });
        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        $this->get(ChatPersonalData::class)->erase($ana);

        self::assertSame(
            [[$dm->getUuid(), $mine->getUuid(), 'https://teams.microsoft.com/l/x', 'Ana 1:1'], [$channel->getUuid(), $inChan->getUuid(), 'https://zoom.us/j/1?pwd=secret', null]],
            array_map(static fn (ChatLinkRemovedEvent $event): array => [$event->conversation->getUuid(), $event->uuid, $event->url, $event->title], $removed),
            'one event per removed link of a conversation that survives (not the user\'s own ticket)',
        );
        self::assertSame('Ana', $removed[0]->by->getChatDisplayName());
        self::assertCount(2, array_filter($this->get(RecordingRealtime::class)->types(), static fn (string $type): bool => $type === 'links'));
        $this->em->clear();
        self::assertSame([$his->getUuid()], array_map(static fn (ChatConversationLink $link): string => $link->getUuid(), $this->em->getRepository(ChatConversationLink::class)->findAll()));
        self::assertSame(['https://his.example/'], array_map(static fn (ChatMessageLink $row): string => $row->getUrl(), $this->em->getRepository(ChatMessageLink::class)->findAll()));
    }

    /** 1.27: compares user objects across managers or clears the application's manager to refresh a host row (skipped by the private-manager replay). */
    #[Group('application-manager')]
    public function testTheLimitHoldsUnderAConcurrentAdd(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        // Another request inserts a link between this one's count and its insert.
        $connection = $this->em->getConnection();
        $listener   = new class($connection, (int) $dm->getId()) {
            public bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly int $conversationId)
            {
            }

            public function postPersist(): void
            {
                if (!$this->done) {
                    $this->done = true;
                    $this->connection->insert('nowo_chat_conversation_link', ['uuid' => '00000000-0000-7000-8000-000000000001', 'conversation_id' => $this->conversationId, 'url' => 'https://other.example/', 'host' => 'other.example', 'created_at' => '2026-11-05 10:00:00']);
                }
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postPersist], $listener);

        try {
            $this->get(ChatLinks::class)->add($ana, $dm, 'https://zoom.us/j/1', null);
            self::fail('the second link exceeds max_pinned: 1');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('too_many_links', $exception->getMessage());
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation_link'), 'rolled back, the concurrent insert of the same transaction too');
        self::assertTrue($this->em->isOpen());
        self::assertSame([], $this->em->getRepository(ChatConversationLink::class)->findAll());

        $this->em->getEventManager()->removeEventListener([Events::postPersist], $listener);
        self::assertSame('https://zoom.us/j/1', $this->get(ChatLinks::class)->add($ana, $dm, 'https://zoom.us/j/1', null)->getUrl());
    }

    public function testPurgeRemovesPinnedLinksPastTheRetention(): void
    {
        $ana  = $this->user('Ana');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $this->user('Luis'));
        $old  = new ChatConversationLink($dm, $ana, 'https://old.example/', 'old.example', null, new DateTimeImmutable('-400 days'));
        $kept = new ChatConversationLink($dm, $ana, 'https://edited.example/', 'edited.example', null, new DateTimeImmutable('-400 days'));
        $kept->change('https://edited.example/', 'edited.example', 'Edited', new DateTimeImmutable('-10 days'));
        $stale = new ChatConversationLink($dm, $ana, 'https://stale.example/', 'stale.example', null, new DateTimeImmutable('-500 days'));
        $stale->change('https://stale.example/', 'stale.example', null, new DateTimeImmutable('-380 days'));
        $new = new ChatConversationLink($dm, $ana, 'https://new.example/', 'new.example', null);
        foreach ([$old, $kept, $stale, $new] as $link) {
            $this->em->persist($link);
        }
        $this->em->flush();

        $tester = new CommandTester($this->get(ChatPurgeCommand::class));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('and 2 pinned link(s) older than 365 days', $tester->getDisplay());
        $this->em->clear();
        self::assertSame(['https://edited.example/', 'https://new.example/'], array_map(static fn (ChatConversationLink $link): string => $link->getUrl(), $this->em->getRepository(ChatConversationLink::class)->findBy([], ['id' => 'ASC'])));
        self::assertSame(0, $this->get(ChatConversationLinkRepository::class)->purgeOlderThan(new DateTimeImmutable('-365 days'), 10));
    }

    public function testPurgeAndKinds(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($ana, $dm, 'https://a.example/');
        $buzz = $chat->buzz($ana, $dm);
        self::assertSame(0, $this->get(ChatLinks::class)->index($buzz), 'lines hold no links');
        $this->get(ChatLinks::class)->forget(new ChatMessage($dm, $ana, 'x', 'x'));

        self::assertSame(2, $this->get(ChatMessageRepository::class)->purgeOlderThan(new DateTimeImmutable('+1 day'), 10));
        self::assertCount(0, $this->em->getRepository(ChatMessageLink::class)->findAll());
    }

    public function testBackfill(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($ana, $dm, 'one https://one.example/');
        $chat->send($luis, $dm, 'no link');
        $chat->send($luis, $dm, '!link[Daily](https://teams.microsoft.com/l/x) and https://two.example/');
        $this->em->persist(ChatMessage::autoReply($dm, $luis, 'away, see https://auto.example/', ''));
        $this->em->flush();
        $deleted = $chat->send($ana, $dm, 'gone https://gone.example/');
        $chat->delete($ana, $deleted);
        // Messages from before 1.19 have no rows.
        $this->em->createQueryBuilder()->delete(ChatMessageLink::class, 'ml')->getQuery()->execute();

        $tester = new CommandTester($this->get(ChatLinksBackfillCommand::class));
        self::assertSame(0, $tester->execute(['--batch' => '1']));
        self::assertStringContainsString('Indexed 3 link(s) of 2 message(s)', $tester->getDisplay());
        self::assertCount(3, $this->em->getRepository(ChatMessageLink::class)->findAll());

        // Safe to run again; --after-id resumes.
        $tester->execute([]);
        self::assertCount(3, $this->em->getRepository(ChatMessageLink::class)->findAll());
        $tester->execute(['--after-id' => '999999', '--batch' => '999999']);
        self::assertStringContainsString('Indexed 0 link(s) of 0 message(s)', $tester->getDisplay());
    }

    public function testBackfillWhenDisabled(): void
    {
        $tester = new CommandTester($this->get(ChatLinksBackfillCommand::class));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Links are disabled', $tester->getDisplay());
    }
}
