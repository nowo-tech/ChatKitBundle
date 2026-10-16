<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Realtime;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Push\ChatPushDispatcher;
use Nowo\ChatKitBundle\Push\DeliverChatPushMessage;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Realtime\ChatPublisher;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Realtime\ChatTopics;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChatNotifierTest extends DatabaseTestCase
{
    public function testTypingGoesToEveryoneButTheTypist(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);

        $this->get(ChatNotifier::class)->typing($dm, $ana);
        $this->get(ChatNotifier::class)->typing($dm, $ana);

        $realtime = $this->get(RecordingRealtime::class);
        self::assertSame([ChatTopics::forUser($luis->getChatPublicId())], $realtime->published[0]['topics']);
        self::assertSame(['type' => 'typing', 'conversationUuid' => $dm->getUuid(), 'userUuid' => $ana->getChatPublicId(), 'userName' => 'Ana'], $realtime->published[0]['payload']);
        self::assertSame($realtime->published[0]['topics'], $realtime->published[1]['topics'], 'recipients served from the cache');
    }

    public function testPushGoesThroughMessengerWhenAvailable(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $chat    = $this->get(ChatService::class);
        $dm      = $chat->openDirect($ana, $luis);
        $support = $chat->openSupport($ana);
        $bus     = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;

                return new Envelope($message);
            }
        };
        $notifier = new ChatNotifier(
            $this->get(ChatRecipients::class),
            $this->get(ChatPublisher::class),
            $this->get(ChatPresenter::class),
            $this->get(ChatPushDispatcher::class),
            true,
            $bus,
        );

        $message = new ChatMessage($dm, $ana, 'hi', '<p>hi</p>');
        $this->em->persist($message);
        $this->em->flush();
        $notifier->messageCreated($message);
        self::assertEquals([new DeliverChatPushMessage((int) $message->getId(), [(int) $luis->getId()])], $bus->dispatched);

        $alone = new ChatMessage($support, $ana, 'anyone?', '<p>anyone?</p>');
        $this->em->persist($alone);
        $this->em->flush();
        $notifier->messageCreated($alone);
        self::assertCount(1, $bus->dispatched, 'no agents yet: nobody else to notify');

        $message->delete($ana);
        $notifier->messageCreated($message);
        self::assertCount(1, $bus->dispatched, 'deleted before delivery');

        self::assertSame([], $this->get(ChatReactionRepository::class)->summaryFor([]));
    }
}
