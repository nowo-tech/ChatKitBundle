<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Card;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Card\ChatCardUpdates;
use Nowo\ChatKitBundle\Contract\ChatCardUpdatesInterface;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatThreads;
use Nowo\ChatKitBundle\Tests\Support\Card\TestNoteCardProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

use function array_column;
use function json_encode;
use function strtoupper;

use const JSON_UNESCAPED_UNICODE;

/**
 * 2.8 (Beacon spec 260): ChatCardUpdatesInterface — `message_updated` for the messages of one conversation that hold
 * the card (thread replies included; deleted ones, code spans, look-alike ids and other conversations left out), no
 * card content on the hub, the bounds, and failures that never throw.
 */
final class ChatCardUpdatesTest extends DatabaseTestCase
{
    protected function cardProviders(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['message_menu' => ['threads' => ['enabled' => true]]]]];
    }

    public function testSignalsTheHoldersOfTheCardInTheConversationOnly(): void
    {
        $this->get(TestNoteCardProvider::class);
        $ana   = $this->user('Ana');
        $luis  = $this->user('Luis');
        $eve   = $this->user('Eve');
        $chat  = $this->get(ChatService::class);
        $dm    = $chat->openDirect($ana, $luis);
        $other = $chat->openDirect($ana, $eve);

        $card = $chat->send($ana, $dm, "Call started\n\n#[…](note:n1)");
        $chat->send($ana, $dm, 'plain');
        $chat->send($ana, $dm, 'quoted `#[…](note:n1)`');
        $chat->send($ana, $dm, '#[…](note:n12) and #[…](note:nx1)');
        $deleted = $chat->send($ana, $dm, '#[…](note:n1)');
        $chat->delete($ana, $deleted);
        [$reply] = $this->get(ChatThreads::class)->reply($luis, $card, 'joined #[…](note:n1)', 'reply-1');
        $chat->send($ana, $other, '#[…](note:n1)');
        $chat->toggleReaction($luis, $card, '👍');

        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        $updates             = $this->get(ChatCardUpdatesInterface::class);
        self::assertInstanceOf(ChatCardUpdates::class, $updates);

        self::assertSame(2, $updates->changed('note', 'n1', $dm->getUuid()));
        self::assertSame(['message_updated', 'message_updated'], $realtime->types());
        $events   = $this->get(RecordingRealtime::class)->published;
        $messages = array_column(array_column($events, 'payload'), 'message');
        self::assertSame([$reply->getUuid(), $card->getUuid()], array_column($messages, 'uuid'), 'newest first');
        self::assertStringNotContainsString('👍', (string) json_encode($messages[0]['reactions'] ?? null, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('👍', (string) json_encode($messages[1]['reactions'] ?? null, JSON_UNESCAPED_UNICODE), 'a full client keeps the reactions');
        foreach ($events as $event) {
            self::assertSame($dm->getUuid(), $event['payload']['conversationUuid']);
            self::assertTrue($event['payload']['message']['refsPending'], 'each viewer asks for their own cards');
            self::assertStringNotContainsString('Release notes', (string) json_encode($event), 'no card content on the hub');
        }

        // Idempotent: the same hint again, nothing else changes.
        self::assertSame(2, $updates->changed('note', 'n1', $dm->getUuid()));
        self::assertSame(1, $updates->changed('note', 'n12', $dm->getUuid()));
        self::assertSame(0, $updates->changed('note', 'n_1', $dm->getUuid()), '`_` is no wildcard');
        self::assertSame(0, $updates->changed('note', 'N1', $dm->getUuid()), 'ids are matched byte for byte');
        self::assertSame(0, $updates->changed('note', 'n1', strtoupper($dm->getUuid())), 'the uuid byte for byte');
        self::assertSame(0, $updates->changed('note', 'n1', '00000000-0000-4000-8000-000000000000'));
        self::assertSame(0, $updates->changed('task', '1', $dm->getUuid()), 'a reference type is no card type');
        self::assertSame(0, $updates->changed('unknown', 'n1', $dm->getUuid()));
        self::assertSame(1, $updates->changed('note', 'n1', $other->getUuid()));

        foreach ([['Note', 'n1'], ['note', ''], ['note', 'a b'], ['note', 'n1)']] as [$type, $id]) {
            try {
                $updates->changed($type, $id, $dm->getUuid());
                self::fail('a malformed card must be refused');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ChatCardUpdates::INVALID_CARD, $exception->getMessage());
            }
        }

        // A hub outage is logged by the publisher: the call still answers.
        $realtime->failing = true;
        self::assertSame(2, $updates->changed('note', 'n1', $dm->getUuid()));
    }

    public function testBoundsTheScanAndTheMessagesSignalled(): void
    {
        $this->get(TestNoteCardProvider::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        for ($i = 0; $i < ChatCardUpdatesInterface::MAX_MESSAGES + 2; ++$i) {
            $this->em->persist(new ChatMessage($dm, $ana, '#[…](note:n1)', '<p>card</p>'));
        }
        $this->em->persist($newest = new ChatMessage($dm, $ana, 'after', '<p>after</p>'));
        $this->em->flush();

        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        self::assertSame(ChatCardUpdatesInterface::MAX_MESSAGES, $this->get(ChatCardUpdatesInterface::class)->changed('note', 'n1', $dm->getUuid()));
        self::assertCount(ChatCardUpdatesInterface::MAX_MESSAGES, $realtime->published);

        $messages = $this->get(ChatMessageRepository::class);
        self::assertSame([], $messages->findCardHolders($dm, 'note', 'n1', 1, 5), 'the window holds the newest message only');
        self::assertCount(1, $messages->findCardHolders($dm, 'note', 'n1', 2, 5));
        self::assertCount(3, $messages->findCardHolders($dm, 'note', 'n1', 10_000, 3), 'a window wider than the conversation');
        self::assertNotNull($newest->getId());
    }

    public function testADatabaseFailureIsLoggedNeverThrown(): void
    {
        $conversations = $this->createMock(ChatConversationRepository::class);
        $conversations->method('findOneByUuid')->willThrowException(new RuntimeException('secret-id gone'));
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $updates = new ChatCardUpdates(
            $conversations,
            $this->get(ChatMessageRepository::class),
            $this->get(ChatReferences::class),
            $this->get(ChatNotifier::class),
            $this->get(ChatReactionRepository::class),
            $logger,
        );

        self::assertSame(0, $updates->changed('note', 'n1', '00000000-0000-4000-8000-000000000000'));
        self::assertSame([['Chat card update hint failed ({exception}).', ['exception' => RuntimeException::class]]], $logger->records);
    }
}
