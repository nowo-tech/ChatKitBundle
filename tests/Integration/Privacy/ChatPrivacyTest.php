<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Privacy;

use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Retention purge, personal data export and erasure.
 */
final class ChatPrivacyTest extends DatabaseTestCase
{
    public function testPurgeKeepsTheRetentionWindow(): void
    {
        $chat = $this->get(ChatService::class);
        $ana = $this->user('Ana');
        $dm = $chat->openDirect($ana, $this->user('Luis'));
        $chat->send($ana, $dm, 'recent');
        $this->em->persist(new ChatMessage($dm, $ana, 'old', '<p>old</p>', new \DateTimeImmutable('-400 days')));
        $this->em->flush();

        $tester = new CommandTester($this->get(ChatPurgeCommand::class));
        self::assertSame(0, $tester->execute(['--max-batches' => '0']));
        self::assertStringContainsString('Deleted 1 chat message(s) older than 365 days.', $tester->getDisplay());
        self::assertCount(1, $this->em->getRepository(ChatMessage::class)->findAll());

        $short = new CommandTester(new ChatPurgeCommand($this->em->getRepository(ChatMessage::class), 1));
        $short->execute([]);
        self::assertStringContainsString('older than 30 days', $short->getDisplay(), 'never below the minimum');
    }

    public function testExportAndErase(): void
    {
        $chat = $this->get(ChatService::class);
        $data = $this->get(ChatPersonalData::class);
        $ana = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm = $chat->openDirect($ana, $luis);
        $mine = $chat->send($ana, $dm, 'my words');
        $chat->send($luis, $dm, 'his words');
        $chat->toggleReaction($ana, $mine, '👍');
        $support = $chat->openSupport($ana);
        $help = $chat->send($ana, $support, 'help');
        $chat->toggleReaction($ana, $help, '🎉');
        $settings = $this->em->getRepository(ChatUserSettings::class)->forUser($ana);
        $settings->setSound('ding');
        $this->em->persist($settings);
        $this->em->flush();

        $export = $data->export($ana);
        self::assertSame(['sound' => 'ding', 'push' => false, 'buzzes' => true, 'show_online' => true], $export['settings']);
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
