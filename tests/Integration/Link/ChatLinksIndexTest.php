<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Link;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Command\ChatLinksBackfillCommand;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;

/**
 * 1.19: the link index follows the messages (send, edit, delete, purge, erasure), the backfill command fills it for
 * older messages, and pinned links survive their author's erasure without the author.
 */
final class ChatLinksIndexTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return $this->name() === 'testBackfillWhenDisabled' ? ['profiles' => ['default' => ['links' => ['enabled' => false]]]] : [];
    }

    public function testErasureKeepsPinnedLinksWithoutTheirAuthor(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $agent  = $this->user('Agent Smith');
        $chat   = $this->get(ChatService::class);
        $links  = $this->get(ChatLinks::class);
        $dm     = $chat->openDirect($ana, $luis);
        $pinned = $links->add($ana, $dm, 'https://teams.microsoft.com/l/x', 'Daily');
        $chat->send($ana, $dm, 'mine https://mine.example/');
        $chat->send($luis, $dm, 'his https://his.example/');
        $ticket = $chat->openSupport($ana);
        $chat->send($ana, $ticket, 'see https://ticket.example/');
        $links->add($agent, $ticket, 'https://zoom.us/j/1', null);

        $export = $this->get(ChatPersonalData::class)->export($ana);
        self::assertSame([[$dm->getUuid(), 'https://teams.microsoft.com/l/x', 'Daily']], array_map(static fn (array $row): array => [$row['conversation_uuid'], $row['url'], $row['title']], $export['links']));

        $this->get(ChatPersonalData::class)->erase($ana);
        $this->em->clear();
        $kept = $this->em->getRepository(ChatConversationLink::class)->findAll();
        self::assertCount(1, $kept, 'the ticket went with its links; the shared link stays');
        self::assertSame([$pinned->getUuid(), null], [$kept[0]->getUuid(), $kept[0]->getCreatedBy()]);
        self::assertSame(['https://his.example/'], array_map(static fn (ChatMessageLink $row): string => $row->getUrl(), $this->em->getRepository(ChatMessageLink::class)->findAll()));
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
        $tester->execute(['--after-id' => '999999']);
        self::assertStringContainsString('Indexed 0 link(s) of 0 message(s)', $tester->getDisplay());
    }

    public function testBackfillWhenDisabled(): void
    {
        $tester = new CommandTester($this->get(ChatLinksBackfillCommand::class));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Links are disabled', $tester->getDisplay());
    }
}
