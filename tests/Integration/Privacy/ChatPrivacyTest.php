<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Privacy;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

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
            'auto_reply'            => ['enabled' => false, 'text' => '', 'when' => 'status', 'from' => null, 'until' => null, 'mentions' => false],
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

    public function testEraseRemovesTheUserFromMentions(): void
    {
        $chat = $this->get(ChatService::class);
        $data = $this->get(ChatPersonalData::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $eve  = $this->user('Eve');
        $id   = $ana->getChatPublicId();
        $dm   = $chat->openDirect($ana, $luis);
        // Luis mentions Ana (Ana is erased) ...
        $about = $chat->send($luis, $dm, 'ping @[Ana](' . $id . ') please');
        // DP-L1: a token inside inline code is literal text in the HTML.
        $code = $chat->send($luis, $dm, 'see @[Ana](' . $id . ') and `@[Ana](' . $id . ')`');
        self::assertStringContainsString('<code>@[Ana](' . $id . ')</code>', $code->getBodyHtml());
        self::assertStringContainsString('data-mention="' . $id . '"', $about->getBodyHtml());
        // ... and Ana mentions Luis in a message that becomes a tombstone.
        $chat->send($ana, $dm, 'hello @[Luis](' . $luis->getChatPublicId() . ')');
        // A mention of someone else stays as it is.
        $other = $chat->openDirect($luis, $eve);
        $kept  = $chat->send($luis, $other, 'hi @[Eve](' . $eve->getChatPublicId() . ')');
        self::assertCount(4, $this->em->getRepository(ChatMessageMention::class)->findAll());
        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];

        $data->erase($ana);
        $this->em->clear();

        $scrubbed = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $about->getUuid()]);
        self::assertNotNull($scrubbed);
        self::assertSame('ping @… please', $scrubbed->getBody());
        self::assertStringNotContainsString($id, $scrubbed->getBodyHtml());
        self::assertStringNotContainsString('Ana', $scrubbed->getBodyHtml());
        self::assertSame('<p>ping @… please</p>', $scrubbed->getBodyHtml());
        self::assertNull($scrubbed->getEditedAt(), 'not shown as an edit');
        $inCode = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $code->getUuid()]);
        self::assertNotNull($inCode);
        self::assertSame('see @… and `@…`', $inCode->getBody());
        self::assertStringContainsString('<code>@…</code>', $inCode->getBodyHtml());
        self::assertStringNotContainsString($id, $inCode->getBodyHtml());
        self::assertStringNotContainsString('Ana', $inCode->getBodyHtml());

        // Open clients get the rewritten messages (DP-I2).
        $updated = array_values(array_filter($realtime->published, static fn (array $event): bool => $event['payload']['type'] === 'message_updated'));
        self::assertSame([$about->getUuid(), $code->getUuid()], array_map(static fn (array $event): mixed => $event['payload']['message']['uuid'] ?? null, $updated));
        self::assertStringNotContainsString($id, json_encode(array_column($updated, 'payload'), JSON_THROW_ON_ERROR), 'no name or id in the payloads');

        $mentions = $this->em->getRepository(ChatMessageMention::class)->findAll();
        self::assertCount(1, $mentions, 'only the mention of Eve is left');
        self::assertSame($eve->getId(), $mentions[0]->getUser()->getId());
        $untouched = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $kept->getUuid()]);
        self::assertNotNull($untouched);
        self::assertSame($kept->getBodyHtml(), $untouched->getBodyHtml());
    }

    public function testEraseClearsPointersAndBotNames(): void
    {
        $chat    = $this->get(ChatService::class);
        $tickets = $this->get(ChatSupportTickets::class);
        $data    = $this->get(ChatPersonalData::class);
        $agent   = $this->user('Agent Ana');
        $bob     = $this->user('Bob');
        $ticket  = $tickets->open($bob);
        $tickets->chooseTopic($bob, $ticket, 'billing');
        self::assertTrue($tickets->assign($agent, $ticket, $agent));
        self::assertTrue($tickets->changeStatus($agent, $ticket, SupportStatus::Resolved, true));
        $dm     = $chat->openDirect($agent, $bob);
        $pinned = $chat->send($bob, $dm, 'pin me');
        $chat->pin($agent, $pinned, true);
        $removed = $chat->send($bob, $dm, 'remove me');
        $removed->delete($agent);
        $this->em->flush();
        self::assertTrue($removed->isModerated());

        $data->erase($agent);
        $this->em->clear();

        // DP-L2: no pointer to the erased account is left; the ticket goes back to the queue.
        $ticket = $this->em->getRepository(ChatConversation::class)->findOneBy(['uuid' => $ticket->getUuid()]);
        self::assertNotNull($ticket);
        self::assertNull($ticket->getAssignee());
        self::assertNull($ticket->getResolvedBy());
        self::assertSame(SupportStatus::Resolved, $ticket->getSupportStatus());
        $pinned = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $pinned->getUuid()]);
        self::assertNotNull($pinned);
        self::assertNull($pinned->getPinnedBy());
        self::assertNotNull($pinned->getPinnedAt(), 'the message stays pinned');
        $removed = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $removed->getUuid()]);
        self::assertNotNull($removed);
        self::assertNull($removed->getDeletedBy());
        self::assertTrue($removed->isDeleted());

        // DP-L3: the bot lines in Bob's ticket no longer name the agent.
        $bot  = $this->em->getRepository(ChatMessage::class)->findBy(['conversation' => $ticket, 'kind' => ChatMessage::KIND_BOT]);
        $meta = json_encode(array_map(static fn (ChatMessage $message): ?array => $message->getMeta(), $bot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Agent Ana', $meta);
        self::assertStringContainsString('"name":"…"', $meta, 'assigned');
        self::assertStringContainsString('"by":"…"', $meta, 'status_resolved');

        // Without the optional notifier and for a nameless account, the erasure still runs.
        $bare = new ChatPersonalData($this->em, $this->get(ChatUserSettingsRepository::class), $this->get(ChatConversationRepository::class));
        $bare->erase($this->user(''));
        self::assertCount(0, $this->em->getRepository(ChatMessageMention::class)->findAll());
    }
}
