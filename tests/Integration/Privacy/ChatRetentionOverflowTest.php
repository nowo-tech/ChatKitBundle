<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Privacy;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Tests\Support\Chat\ScriptedRetentionProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

use const PHP_INT_MAX;

/**
 * Audit QP-H-1 (inverted): a huge retention value, from the provider or the configuration, keeps recent messages.
 */
final class ChatRetentionOverflowTest extends DatabaseTestCase
{
    public function testHugeValuesKeepFreshMessages(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = ChatConversation::direct($ana, $luis);
        $this->em->persist($dm);
        $this->em->persist(new ChatMessage($dm, $luis, 'fresh', '<p>fresh</p>', new DateTimeImmutable('-1 hour')));
        $this->em->persist(new ChatMessage($dm, $luis, 'mid', '<p>mid</p>', new DateTimeImmutable('-10 days')));
        $this->em->flush();
        $repo = $this->em->getRepository(ChatMessage::class);
        self::assertInstanceOf(ChatMessageRepository::class, $repo);

        $clock = new MockClock(new DateTimeImmutable());
        (new CommandTester(new ChatPurgeCommand($repo, 365, $clock, retention: new ScriptedRetentionProvider([PHP_INT_MAX, 10 ** 13]))))->execute([]);
        (new CommandTester(new ChatPurgeCommand($repo, PHP_INT_MAX, $clock)))->execute([]);
        (new CommandTester(new ChatPurgeCommand($repo, 365, $clock, retention: new ScriptedRetentionProvider([10 ** 13]))))->execute([]);
        $this->em->clear();

        self::assertCount(2, $this->em->getRepository(ChatMessage::class)->findAll());
    }
}
