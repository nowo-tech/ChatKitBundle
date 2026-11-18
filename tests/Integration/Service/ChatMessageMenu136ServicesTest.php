<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use ArrayObject;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatForwardPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatMessageActionsInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatIdempotencyKey;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;
use Nowo\ChatKitBundle\Event\ChatMessageForwardingEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatForwardRequest;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatIdempotencyKeyRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatDraft;
use Nowo\ChatKitBundle\Service\ChatForwarder;
use Nowo\ChatKitBundle\Service\ChatForwardRefused;
use Nowo\ChatKitBundle\Service\ChatIdempotency;
use Nowo\ChatKitBundle\Service\ChatIdempotencyConflict;
use Nowo\ChatKitBundle\Service\ChatMenuOptions;
use Nowo\ChatKitBundle\Service\ChatMessageActions;
use Nowo\ChatKitBundle\Service\ChatMessageMenu;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatThreads;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function count;

/**
 * 1.36: the services of forward, threads and edit history beyond what the API reaches — the vetoes, duplicates and
 * existing direct conversations of a forward, a key another request took first, a refused unit, a stale edit, a thread
 * read without replies, and the façade built without the services.
 */
final class ChatMessageMenu136ServicesTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['message_menu' => [
            'forward'      => ['enabled' => true, 'max_targets' => 3],
            'threads'      => ['enabled' => true],
            'edit_history' => ['enabled' => true],
        ]]]];
    }

    public function testForwardVetoesDuplicatesAndExistingDirectConversations(): void
    {
        [$ana, $luis, $room] = $this->people();
        $chat                = $this->get(ChatService::class);
        $dm                  = $chat->openDirect($ana, $luis);
        $source              = $chat->send($ana, $room, 'news');
        $forwarder           = $this->get(ChatForwarder::class);

        // The same conversation twice, and Luis by contact (the direct conversation exists): one copy each.
        [$copies, $replayed] = $forwarder->forward($ana, $source, [['conversationUuid' => $dm->getUuid()], ['conversationUuid' => $dm->getUuid()], ['contactUuid' => $luis->getChatPublicId()]], '  ', 'f-1');
        self::assertFalse($replayed);
        self::assertCount(1, $copies);
        self::assertSame($dm->getUuid(), $copies[0]->getConversation()->getUuid());
        try {
            $forwarder->forward($ana, $source, [['conversationUuid' => $dm->getUuid()], ['conversationUuid' => $dm->getUuid()], ['conversationUuid' => $dm->getUuid()], ['conversationUuid' => $dm->getUuid()]], null, 'f-2');
            self::fail('too many targets');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('invalid_targets', $exception->getMessage());
        }
        try {
            $forwarder->forward($ana, $source, ['not a target'], null, 'f-3');
            self::fail('a malformed target');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('invalid_targets', $exception->getMessage());
        }

        // A listener refuses (with its reason): nothing is written.
        $dispatcher = $this->get(EventDispatcherInterface::class);
        $veto       = static fn (ChatMessageForwardingEvent $event) => $event->deny('blocked.here');
        $dispatcher->addListener(ChatMessageForwardingEvent::class, $veto);
        $count = $this->messageCount();
        try {
            $forwarder->forward($ana, $source, [['conversationUuid' => $dm->getUuid()]], null, 'f-4');
            self::fail('the listener refused');
        } catch (ChatForwardRefused $refused) {
            self::assertSame([0, 'blocked.here'], [$refused->target, $refused->reason]);
        }
        $dispatcher->removeListener(ChatMessageForwardingEvent::class, $veto);
        self::assertSame($count, $this->messageCount());

        // The application's port refuses a new direct conversation (it sees the recipient, no conversation yet).
        $bea = $this->user('Bea');
        /** @var ArrayObject<int, ChatForwardRequest> $seen */
        $seen   = new ArrayObject();
        $policy = new class($seen) implements ChatForwardPolicyInterface {
            /** @param ArrayObject<int, ChatForwardRequest> $seen */
            public function __construct(private readonly ArrayObject $seen)
            {
            }

            public function mayForward(ChatForwardRequest $request): bool
            {
                $this->seen->append($request);

                return $request->targetConversationUuid !== null;
            }
        };
        $strict = new ChatForwarder($this->get(ChatService::class), $this->get(ChatMenuOptions::class), $this->get(ChatAccess::class), $this->get(ChatVoter::class), $this->get(ChatConversationRepository::class), $this->get(ChatDirectoryInterface::class), $this->get(ChatIdempotency::class), $policy);
        try {
            $strict->forward($ana, $source, [['conversationUuid' => $dm->getUuid()], ['contactUuid' => $bea->getChatPublicId()]], null, 'f-5');
            self::fail('the port refused');
        } catch (ChatForwardRefused $refused) {
            self::assertSame([1, ''], [$refused->target, $refused->reason]);
        }
        self::assertSame([$luis->getChatPublicId(), $bea->getChatPublicId()], array_column($seen->getArrayCopy(), 'recipientPublicId'));
        self::assertSame(['channel', 'channel'], array_column($seen->getArrayCopy(), 'sourceConversationKind'));
        self::assertSame($count, $this->messageCount());
    }

    public function testAKeyTakenByAnotherRequestAndARefusedUnit(): void
    {
        [$ana, , $room] = $this->people();
        $idempotency    = $this->get(ChatIdempotency::class);
        $keys           = $this->get(ChatIdempotencyKeyRepository::class);
        // Another request committed the key while this one ran: the unit is refused, the earlier answer comes back.
        [$messages, $replayed] = $idempotency->run($ana, 'k-taken', 'h', static function (Closure $before, Closure $after) use ($ana, $keys): array {
            self::assertTrue($keys->claim($ana, 'k-taken', 'h', new DateTimeImmutable()));

            throw new ChatIdempotencyConflict();
        });
        self::assertSame([[], true], [$messages, $replayed]);
        self::assertCount(1, $this->em->getRepository(ChatIdempotencyKey::class)->findAll());
        // A claim that refuses the unit (another request took the key): nothing is written.
        $count = $this->messageCount();
        try {
            $this->get(ChatService::class)->post($ana, [new ChatDraft($room, 'never')], static fn (): bool => false);
            self::fail('the unit was refused');
        } catch (ChatIdempotencyConflict) {
        }
        self::assertSame($count, $this->messageCount());
        // A key of another request is refused.
        try {
            $idempotency->run($ana, 'k-taken', 'other', static fn (): array => []);
            self::fail('another request');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('idempotency_mismatch', $exception->getMessage());
        }
        // The keys go after a day.
        self::assertSame(1, $keys->purgeOlderThan(new DateTimeImmutable('+1 day')));
    }

    public function testThreadsEditsAndTheFacadeWithoutServices(): void
    {
        [$ana, $luis, $room] = $this->people();
        $carl                = $this->user('Carl');
        $chat                = $this->get(ChatService::class);
        $chat->openChannel($carl, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $root    = $chat->send($ana, $room, 'root');
        $threads = $this->get(ChatThreads::class);
        // Nothing to read yet.
        self::assertFalse($threads->markRead($luis, $root, 5));
        // Carl does not follow the thread: the realtime `thread` event reaches the followers only.
        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        [$reply]             = $threads->reply($luis, $root, 'reply', 'r-1');
        $thread              = array_values(array_filter($this->get(RecordingRealtime::class)->published, static fn (array $event): bool => $event['payload']['type'] === 'thread'));
        self::assertCount(2, $thread[0]['topics']);
        self::assertTrue($threads->markRead($ana, $root, (int) $reply->getId()));
        self::assertFalse($threads->markRead($ana, $root, (int) $reply->getId()), 'never back, never twice');
        self::assertSame([], $threads->summaries($ana, [$reply]));
        // A long thread pages; a reply whose author was erased names no replier.
        for ($i = 0; $i < ChatThreads::PAGE; ++$i) {
            $threads->reply($ana, $root, 'more ' . $i, 'm-' . $i);
        }
        $page = $threads->page($ana, $this->fresh($root), null, null);
        self::assertTrue($page['hasMore']);
        self::assertCount(ChatThreads::PAGE, $page['replies']);
        $this->get(ChatPersonalData::class)->erase($luis);
        $this->em->clear();
        $ana     = $this->em->find(TestUser::class, $ana->getId()) ?? self::fail('ana');
        $luis    = $this->em->find(TestUser::class, $luis->getId()) ?? self::fail('luis');
        $room    = $this->fresh($room);
        $root    = $this->fresh($root);
        $summary = $threads->summaries($ana, [$root]);
        self::assertSame(['Ana'], array_map(static fn (ChatUserInterface $person): string => $person->getChatDisplayName(), $summary[$root->getUuid()]['repliers']));
        // A deleted root takes no reply (the voter refuses it first on the API).
        $chat->delete($ana, $root);
        try {
            $threads->reply($luis, $this->fresh($root), 'late', 'r-2');
            self::fail('a deleted root');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('not_threadable', $exception->getMessage());
        }

        // A stale copy of a message edited meanwhile: the same text is no edit (read again under the lock).
        $said  = $chat->send($ana, $this->fresh($room), 'one');
        $stale = $this->fresh($said);
        $this->em->clear();
        $chat->edit($this->fresh($said), 'two');
        $this->em->clear();
        $chat->edit($stale, 'two');
        self::assertCount(1, $this->em->getRepository(ChatMessageRevision::class)->findAll());

        // The façade built without the 1.36 services answers "not found".
        $menu = new ChatMessageMenu($this->get(ChatMessageActions::class), $this->get(ChatConversationRepository::class), $this->get(ChatMessageRepository::class), $this->get(ChatVoter::class));
        $said = $this->fresh($said);
        foreach ([
            static fn () => $menu->forward($ana, $room->getUuid(), $said->getUuid(), [], null, 'k'),
            static fn () => $menu->replyInThread($ana, $room->getUuid(), $said->getUuid(), 'x', 'k'),
        ] as $call) {
            try {
                $call();
                self::fail('no service');
            } catch (ChatNotFoundException) {
            }
        }
        self::assertSame([], $menu->revisions($ana, $room->getUuid(), $said->getUuid()));
        try {
            $this->get(ChatMessageActionsInterface::class)->replyInThread($ana, $room->getUuid(), $said->getUuid(), 'x', 'bad key');
            self::fail('a bad key');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('idempotency_key_invalid', $exception->getMessage());
        }
        self::assertGreaterThan(0, count($this->get(ChatMessageRepository::class)->findReplies($this->fresh($root), 5, null, 0)));
    }

    /**
     * @return array{TestUser, TestUser, ChatConversation}
     */
    private function people(): array
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $room = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->openChannel($luis, new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        return [$ana, $luis, $room];
    }

    private function messageCount(): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(m.id)')->from(ChatMessage::class, 'm')->getQuery()->getSingleScalarResult();
    }

    /**
     * @template T of ChatConversation|ChatMessage
     *
     * @param T $entity
     *
     * @return T
     */
    private function fresh(ChatConversation|ChatMessage $entity): ChatConversation|ChatMessage
    {
        $managed = $this->em->find($entity::class, $entity->getId());
        self::assertNotNull($managed);

        return $managed;
    }
}
