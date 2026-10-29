<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Privacy;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Retention purge, personal data export and erasure.
 */
final class ChatPrivacyTest extends DatabaseTestCase
{
    public function testPurgeKeepsTheRetentionWindow(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $dm   = $chat->openDirect($ana, $this->user('Luis'));
        $chat->send($ana, $dm, 'recent');
        $this->em->persist(new ChatMessage($dm, $ana, 'old', '<p>old</p>', new DateTimeImmutable('-400 days')));
        $this->em->flush();

        $tester = new CommandTester($this->get(ChatPurgeCommand::class));
        self::assertSame(0, $tester->execute(['--max-batches' => '0']));
        self::assertStringContainsString('Deleted 1 chat message(s) older than 365 days.', $tester->getDisplay());
        self::assertCount(1, $this->em->getRepository(ChatMessage::class)->findAll());

        $short = new CommandTester(new ChatPurgeCommand($this->em->getRepository(ChatMessage::class), 1));
        $short->execute([]);
        self::assertStringContainsString('older than 30 days', $short->getDisplay(), 'never below the minimum');
    }

    public function testPurgeLogsStartEndAndFailure(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message;
            }
        };
        $clock = new MockClock('2026-10-16 12:00:00');
        (new CommandTester(new ChatPurgeCommand($this->em->getRepository(ChatMessage::class), 365, $clock, $logger)))->execute([]);
        self::assertSame(['info Chat purge started.', 'info Chat purge finished.'], $logger->lines);

        $broken = $this->createStub(ChatMessageRepository::class);
        $broken->method('purgeOlderThan')->willThrowException(new RuntimeException('db gone'));
        $logger->lines = [];
        try {
            (new CommandTester(new ChatPurgeCommand($broken, 365, $clock, $logger)))->execute([]);
            self::fail('the failure is rethrown');
        } catch (RuntimeException) {
            self::assertSame(['info Chat purge started.', 'error Chat purge failed.'], $logger->lines);
        }
    }

    public function testExportAndErase(): void
    {
        $chat = $this->get(ChatService::class);
        $data = $this->get(ChatPersonalData::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $chat->openDirect($ana, $luis);
        $mine = $chat->send($ana, $dm, 'my words');
        $chat->send($luis, $dm, 'his words');
        $chat->toggleReaction($ana, $mine, '👍');
        $support = $chat->openSupport($ana);
        $help    = $chat->send($ana, $support, 'help');
        $chat->toggleReaction($ana, $help, '🎉');
        // A second ticket (1.8: several per user) goes too.
        $tickets = $this->get(ChatSupportTickets::class);
        $tickets->chooseTopic($ana, $support, 'billing');
        $support->resolve($luis);
        $second = $tickets->open($ana);
        self::assertNotSame($support, $second);
        $tickets->chooseTopic($ana, $second, 'incident');
        $settings = $this->em->getRepository(ChatUserSettings::class)->forUser($ana);
        $settings->setSound('ding');
        $this->em->persist($settings);
        $this->em->flush();

        $export = $data->export($ana);
        self::assertSame([
            'sound'                 => 'ding',
            'sound_mention'         => 'chime',
            'sound_buzz'            => 'buzz',
            'sound_support'         => 'ding',
            'volume'                => 80,
            'sound_background_only' => false,
            'push'                  => false,
            'push_preview'          => true,
            'buzzes'                => true,
            'buzz_open_window'      => true,
            'open_on_message'       => false,
            'send_key'              => 'enter',
            'email_digest'          => true,
            'show_online'           => true,
            'status'                => 'available',
            'status_text'           => null,
            'status_emoji'          => null,
            'status_expires_at'     => null,
        ], $export['settings']);
        self::assertSame(['my words', 'help'], array_column($export['messages'], 'text'), 'only own messages');
        self::assertSame('direct', $export['messages'][0]['conversation_kind']);

        $data->erase($ana);
        $this->em->clear();
        $messages = $this->em->getRepository(ChatMessage::class)->findAll();
        self::assertCount(2, $messages, 'the DM keeps its shape; the support thread is gone');
        $tombstone = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $mine->getUuid()]);
        self::assertNotNull($tombstone);
        self::assertTrue($tombstone->isDeleted());
        self::assertNull($tombstone->getAuthor());
        self::assertSame('', $tombstone->getBody());
        self::assertCount(0, $this->em->getRepository(ChatReaction::class)->findAll());
        self::assertCount(1, $this->em->getRepository(ChatParticipant::class)->findAll(), 'only Luis');
        self::assertCount(0, $this->em->getRepository(ChatUserSettings::class)->findAll());
        self::assertCount(1, $this->em->getRepository(ChatConversation::class)->findAll());

        $data->erase($luis);
    }
}
