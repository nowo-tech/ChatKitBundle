<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Privacy;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Event\ChatMentionsErasedEvent;
use Nowo\ChatKitBundle\Privacy\ChatMentionErasure;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function range;

/**
 * 1.18 DATA-CHAT-MENTION-ERASE: `ChatPersonalData::eraseMentions()` (batches paged by id, one transaction, only the
 * messages it loaded released, skip-and-report on a regex failure, mention-like labels refused, event with skipped ids).
 */
final class ChatMentionErasureTest extends DatabaseTestCase
{
    /** @var list<ChatMentionsErasedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = [];
        $this->get(EventDispatcherInterface::class)->addListener(ChatMentionsErasedEvent::class, function (ChatMentionsErasedEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testEraseMentionsRewritesInBatchesAndKeepsTheCallersEntities(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $eve  = $this->user('Eve');
        $id   = $ana->getChatPublicId();
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        // More than one batch: ids are paged, every candidate is reached.
        $count = ChatPersonalData::ERASE_BATCH + 3;
        foreach (range(1, $count) as $n) {
            $this->em->persist(new ChatMessage($dm, $luis, $n . ' @[Ana](' . $id . ')', '<p>old</p>', new DateTimeImmutable()));
        }
        // A mention of someone else and a deleted message stay as they are.
        $kept = new ChatMessage($dm, $luis, 'hi @[Eve](' . $eve->getChatPublicId() . ')', '<p>keep</p>', new DateTimeImmutable());
        $this->em->persist($kept);
        $gone = new ChatMessage($dm, $luis, '', '', new DateTimeImmutable());
        $gone->delete($luis);
        $this->em->persist($gone);
        $this->em->flush();
        // A message the caller holds, with a mention row: it stays managed after the erasure.
        $held = new ChatMessage($dm, $luis, 'held @[Ana](' . $id . ')', '<p>held</p>', new DateTimeImmutable());
        $this->em->persist($held);
        $this->em->persist(new ChatMessageMention($held, $ana));
        $this->em->persist(new ChatMessageMention($gone, $ana));
        $this->em->flush();
        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        $publishedAtEvent    = null;
        $this->get(EventDispatcherInterface::class)->addListener(ChatMentionsErasedEvent::class, static function () use ($realtime, &$publishedAtEvent): void {
            $publishedAtEvent ??= count($realtime->published);
        });

        $erasure = $this->get(ChatPersonalData::class)->eraseMentions($ana);
        // Audit FT-L2: the event comes before the broadcast, which runs in chunks (more than one here).
        self::assertSame(0, $publishedAtEvent);

        self::assertInstanceOf(ChatMentionErasure::class, $erasure);
        self::assertCount($count + 1, $erasure);
        self::assertSame(0, $erasure->skipped());
        self::assertSame($id, $erasure->publicId);
        self::assertTrue($this->em->contains($held), 'the caller\'s own instance is never released');
        self::assertSame('held @…', $held->getBody());
        self::assertNull($held->getEditedAt());
        self::assertTrue($this->em->contains($kept), 'not a candidate: untouched');
        $this->em->clear();
        $bodies = array_column($this->em->getConnection()->fetchAllAssociative('SELECT body FROM nowo_chat_message'), 'body');
        self::assertSame([], array_values(array_filter($bodies, static fn (string $body): bool => str_contains($body, $id))));
        self::assertSame('<p>1 @…</p>', $this->em->getRepository(ChatMessage::class)->findOneBy([], ['id' => 'ASC'])?->getBodyHtml());
        self::assertSame([], $this->em->getRepository(ChatMessageMention::class)->findBy(['user' => $ana->getId()]));
        self::assertCount(1, $this->events);
        self::assertSame($erasure->messageIds, $this->events[0]->messageIds);
        self::assertSame([], $this->events[0]->skippedMessageIds);
        self::assertSame($id, $this->events[0]->publicId);
        $updated = array_filter($realtime->published, static fn (array $event): bool => $event['payload']['type'] === 'message_updated');
        self::assertCount($count + 1, $updated);

        // A second run finds nothing: no event.
        $again = $this->get(ChatPersonalData::class)->eraseMentions($this->reload($ana));
        self::assertCount(0, $again);
        self::assertSame($id, $again->publicId);
        self::assertCount(1, $this->events);
    }

    public function testUnscannableMessagesAreSkippedAndComposedMentionsFlattened(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $id   = $ana->getChatPublicId();
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        // Invalid UTF-8: the scan fails, nothing is written.
        $broken = new ChatMessage($dm, $luis, "bad \xff @[Ana](" . $id . ')', '<p>bad</p>', new DateTimeImmutable());
        // Text built around the token: the rewrite would mention Luis (audit FT-L1: flattened, not skipped).
        $composed = new ChatMessage($dm, $luis, '@[x @[Ana](' . $id . ')](' . $luis->getChatPublicId() . ')', '<p>c</p>', new DateTimeImmutable());
        $fine     = new ChatMessage($dm, $luis, 'ok @[Ana](' . $id . ')', '<p>ok</p>', new DateTimeImmutable());
        foreach ([$broken, $composed, $fine] as $message) {
            $this->em->persist($message);
            $this->em->persist(new ChatMessageMention($message, $ana));
        }
        $this->em->flush();
        $this->em->clear();

        $erasure = $this->get(ChatPersonalData::class)->eraseMentions($this->reload($ana), '«deleted user»');

        self::assertSame([(int) $composed->getId(), (int) $fine->getId()], $erasure->messageIds);
        self::assertSame([(int) $broken->getId()], $erasure->skippedMessageIds);
        self::assertSame(1, $erasure->skipped());
        self::assertSame($erasure->skippedMessageIds, $this->events[0]->skippedMessageIds);
        $this->em->clear();
        self::assertSame("bad \xff @[Ana](" . $id . ')', $this->em->find(ChatMessage::class, $broken->getId())?->getBody());
        self::assertSame('@x «deleted user»', $this->em->find(ChatMessage::class, $composed->getId())?->getBody());
        self::assertSame('ok «deleted user»', $this->em->find(ChatMessage::class, $fine->getId())?->getBody());
        // The skipped message keeps its mention row, so a second run finds it again.
        $rows = $this->em->getRepository(ChatMessageMention::class)->findBy(['user' => $ana->getId()], ['id' => 'ASC']);
        self::assertSame([(int) $broken->getId()], array_map(static fn (ChatMessageMention $row): int => (int) $row->getMessage()->getId(), $rows));
    }

    public function testATokenWithoutMentionRowIsFoundByText(): void
    {
        $ana  = $this->user('Ana_B');
        $luis = $this->user('Luis');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        $text = new ChatMessage($dm, $luis, 'x @[Ana](' . $ana->getChatPublicId() . ')', '<p>x</p>', new DateTimeImmutable());
        $this->em->persist($text);
        $this->em->flush();

        self::assertCount(1, $this->get(ChatPersonalData::class)->eraseMentions($ana));
        $this->em->clear();
        self::assertSame('x @…', $this->em->find(ChatMessage::class, $text->getId())?->getBody());

        // A public id that can never appear in a token: only the mention rows are candidates.
        $odd = new TestUser('Odd');
        (new ReflectionProperty(TestUser::class, 'publicId'))->setValue($odd, 'odd.id');
        $this->em->persist($odd);
        $dm      = $this->em->find(ChatConversation::class, $dm->getId());
        $luis    = $this->reload($luis);
        $message = new ChatMessage($dm ?? throw new RuntimeException(), $luis, 'hi', '<p>hi</p>', new DateTimeImmutable());
        $this->em->persist($message);
        $this->em->persist(new ChatMessageMention($message, $odd));
        $this->em->flush();
        $erasure = $this->get(ChatPersonalData::class)->eraseMentions($odd);
        self::assertCount(0, $erasure);
        self::assertSame(0, $erasure->skipped());
        self::assertSame('odd.id', $erasure->publicId);
        self::assertSame([], $this->em->getRepository(ChatMessageMention::class)->findAll(), 'the mention row goes');
        self::assertCount(1, $this->events, 'nothing rewritten or skipped: no event');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function craftedBodies(): iterable
    {
        // Audit FT-L1 PoC: the outer construct turns into a mention of "other" once the token is replaced.
        yield 'nested (PoC)' => ['@[x @[Ana](ID)](other)', '@x @…'];
        yield 'nested twice' => ['@[q @[x @[Ana](ID)](other)](z)', '@q @x @…'];
        yield 'the erased id again' => ['@[@[Ana](ID)](ID)', '@@…'];
        yield 'inside an id' => ['@[Eve](@[Ana](ID))', '@[Eve](@…)'];
        yield 'others kept' => ['@[Bob](bob) @[x @[Ana](ID)](bob)', '@[Bob](bob) @x @…'];
    }

    #[DataProvider('craftedBodies')]
    public function testACraftedBodyNeverKeepsTheErasedId(string $body, string $expected): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $id      = $ana->getChatPublicId();
        $dm      = $this->get(ChatService::class)->openDirect($ana, $luis);
        $message = new ChatMessage($dm, $luis, str_replace('ID', $id, $body), '<p>c</p>', new DateTimeImmutable());
        $this->em->persist($message);
        $this->em->persist(new ChatMessageMention($message, $ana));
        $this->em->flush();

        $erasure = $this->get(ChatPersonalData::class)->eraseMentions($ana);

        self::assertSame([(int) $message->getId()], $erasure->messageIds);
        self::assertSame(0, $erasure->skipped());
        $this->em->clear();
        $row = $this->em->find(ChatMessage::class, $message->getId());
        self::assertNotNull($row);
        self::assertSame($expected, $row->getBody());
        self::assertStringNotContainsString($id, $row->getBodyHtml());
        self::assertSame([], $this->em->getRepository(ChatMessageMention::class)->findAll());
    }

    public function testAFailedBroadcastIsLoggedAfterTheCommitAndTheEventStillGoes(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        foreach (range(1, ChatPersonalData::ERASE_BATCH + 1) as $n) {
            $this->em->persist(new ChatMessage($dm, $luis, $n . ' @[Ana](' . $ana->getChatPublicId() . ')', '<p>x</p>', new DateTimeImmutable()));
        }
        $this->em->flush();
        $reactions = $this->createMock(ChatReactionRepository::class);
        $reactions->expects(self::exactly(2))->method('summaryFor')->willThrowException(new RuntimeException('db gone'));
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . strtr((string) $message, ['{count}' => (string) $context['count']]);
            }
        };
        $data = new ChatPersonalData(
            $this->em,
            $this->get(ChatUserSettingsRepository::class),
            $this->get(ChatConversationRepository::class),
            notifier: $this->get(ChatNotifier::class),
            reactions: $reactions,
            events: $this->get(EventDispatcherInterface::class),
            logger: $logger,
        );

        $erasure = $data->eraseMentions($ana);

        self::assertCount(ChatPersonalData::ERASE_BATCH + 1, $erasure);
        self::assertCount(1, $this->events, 'the event is not lost');
        self::assertSame([
            'error Chat mention erasure: message_updated could not be published for 200 message(s).',
            'error Chat mention erasure: message_updated could not be published for 1 message(s).',
        ], $logger->lines);
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(ChatMessageMention::class)->findAll());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mentionLikeLabels(): iterable
    {
        yield 'empty' => [''];
        yield 'a token' => ['@[x](y)'];
        yield 'opening bracket' => ['[deleted]'];
        yield 'closing bracket' => ['x]'];
        yield 'parenthesis' => ['«x)»'];
        yield 'handle first' => ['deleted-user'];
        yield 'dash first (could complete an id)' => ['-x'];
        yield 'at and handle' => ['«@deleted»'];
        yield 'at last' => ['«x@'];
        yield 'invalid UTF-8' => ["\xff"];
    }

    #[DataProvider('mentionLikeLabels')]
    public function testAMentionLikeLabelIsRefused(string $label): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->get(ChatPersonalData::class)->eraseMentions($this->user('Ana'), $label);
    }

    public function testAFailureRollsTheErasureBack(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('beginTransaction');
        $em->method('createQueryBuilder')->willThrowException(new RuntimeException('db gone'));
        // The rollback fails too: the cause is never hidden.
        $em->expects(self::once())->method('rollback')->willThrowException(new RuntimeException('no transaction'));
        $em->expects(self::never())->method('commit');
        $data = new ChatPersonalData($em, $this->get(ChatUserSettingsRepository::class), $this->get(ChatConversationRepository::class));

        try {
            $data->eraseMentions($this->user('Ana'));
            self::fail('the failure is rethrown');
        } catch (RuntimeException $exception) {
            self::assertSame('db gone', $exception->getMessage());
        }
        self::assertSame([], $this->events);
    }

    public function testAFailedEraseRollsEverythingBack(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        $this->get(ChatService::class)->send($luis, $dm, 'hi @[Ana](' . $ana->getChatPublicId() . ')');
        $conversations = $this->createStub(ChatConversationRepository::class);
        $conversations->method('findBy')->willThrowException(new RuntimeException('db gone'));
        $data = new ChatPersonalData($this->em, $this->get(ChatUserSettingsRepository::class), $conversations);

        try {
            $data->erase($ana);
            self::fail('the failure is rethrown');
        } catch (RuntimeException $exception) {
            self::assertSame('db gone', $exception->getMessage());
        }
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(ChatMessageMention::class)->findAll(), 'rolled back');
        self::assertStringContainsString($ana->getChatPublicId(), (string) $this->em->getConnection()->fetchOne('SELECT body FROM nowo_chat_message'));
        self::assertSame([], $this->events);
        self::assertCount(1, $this->em->getRepository(ChatMessage::class)->findAll());
    }

    private function reload(TestUser $user): TestUser
    {
        $found = $this->em->find(TestUser::class, $user->getId());
        self::assertNotNull($found);

        return $found;
    }
}
