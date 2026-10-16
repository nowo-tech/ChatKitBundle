<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Digest;

use Nowo\ChatKitBundle\Digest\ChatDigestDispatcher;
use Nowo\ChatKitBundle\Digest\ChatDigestScheduler;
use Nowo\ChatKitBundle\Digest\NullChatDigestSender;
use Nowo\ChatKitBundle\Digest\SendChatDigestMessage;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingDigestSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Unread-messages email: one delayed check per recipient, then an email only for conversations still unread,
 * the user opted in, the app not open, at most one per interval.
 */
final class ChatDigestTest extends DatabaseTestCase
{
    public function testSchedulesOnceAndOnlyWhenEnabledWithMessenger(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->sent[] = new Envelope($message, $stamps);
            }
        };
        $ana       = $this->user('Ana');
        $luis      = $this->user('Luis');
        $scheduler = new ChatDigestScheduler(new ArrayAdapter(), $bus, true, 15);
        $scheduler->schedule([$ana, $luis]);
        $scheduler->schedule([$ana]);
        self::assertCount(2, $bus->sent, 'one pending check per user');
        self::assertEquals(new SendChatDigestMessage((int) $ana->getId()), $bus->sent[0]->getMessage());
        self::assertSame(900_000, $bus->sent[0]->last(DelayStamp::class)?->getDelay());

        (new ChatDigestScheduler(new ArrayAdapter(), $bus, false))->schedule([$ana]);
        (new ChatDigestScheduler(new ArrayAdapter(), null, true))->schedule([$ana]);
        self::assertCount(2, $bus->sent);
        (new NullChatDigestSender())->send($ana, []);
    }

    public function testEmailsStillUnreadConversations(): void
    {
        $chat  = $this->get(ChatService::class);
        $ana   = $this->user('Ana');
        $luis  = $this->user('Luis');
        $agent = $this->user('Agent Smith');
        $dm    = $chat->openDirect($ana, $luis);
        $chat->send($luis, $dm, "**Deploy** done\n- all green");
        $chat->buzz($luis, $dm);
        $channel = $chat->openChannel($ana, $this->get(ChatAccess::class)->channel(TestChannelProvider::KEY) ?? self::fail('channel'));
        $chat->openChannel($luis, $this->get(ChatAccess::class)->channel(TestChannelProvider::KEY) ?? self::fail('channel'));
        $chat->send($luis, $channel, 'Release at 5');
        $support = $chat->openSupport($ana);
        $chat->send($agent, $support, 'Fixed');
        $read = $chat->openDirect($ana, $agent);
        $chat->markRead($ana, $read, (int) $chat->send($agent, $read, 'seen already')->getId());

        $sender     = new RecordingDigestSender();
        $cache      = new ArrayAdapter();
        $dispatcher = $this->dispatcher($sender, $cache);
        $dispatcher(new SendChatDigestMessage((int) $ana->getId()));

        self::assertCount(1, $sender->sent);
        self::assertSame($ana, $sender->sent[0]['user']);
        $items = array_map(static fn ($item): array => [$item->title, $item->unread, $item->preview], $sender->sent[0]['items']);
        self::assertEqualsCanonicalizing([['Luis', 2, ''], ['#Acme', 1, 'Release at 5'], ['Support', 1, 'Fixed']], $items);
        self::assertStringStartsWith('/inbox?chat=', $sender->sent[0]['items'][0]->url);

        // Within the interval: no second email.
        $dispatcher(new SendChatDigestMessage((int) $ana->getId()));
        self::assertCount(1, $sender->sent);

        // Opted out, app open, nothing unread, unknown or unreachable user: no email.
        $other    = new RecordingDigestSender();
        $settings = $this->get(ChatUserSettingsRepository::class)->forUser($ana);
        $settings->setEmailDigest(false);
        $this->em->persist($settings);
        $this->em->flush();
        $this->dispatcher($other, new ArrayAdapter())(new SendChatDigestMessage((int) $ana->getId()));
        $settings->setEmailDigest(true);
        $this->em->flush();
        $this->get(ChatPresence::class)->heartbeat($ana, true);
        $this->dispatcher($other, new ArrayAdapter())(new SendChatDigestMessage((int) $ana->getId()));
        $this->get(ChatPresence::class)->forget($ana);
        $this->dispatcher($other, new ArrayAdapter())(new SendChatDigestMessage((int) $agent->getId()));
        $this->dispatcher($other, new ArrayAdapter())(new SendChatDigestMessage(999_999));
        $disabled = $this->user('Disabled Bob');
        $this->dispatcher($other, new ArrayAdapter())(new SendChatDigestMessage((int) $disabled->getId()));
        self::assertSame([], $other->sent);
        self::assertTrue($this->em->getRepository(ChatUserSettings::class)->forUser($ana)->isEmailDigest());
    }

    private function dispatcher(RecordingDigestSender $sender, ArrayAdapter $cache): ChatDigestDispatcher
    {
        return new ChatDigestDispatcher(
            $this->get(TestDirectory::class),
            $this->get(ChatConversationRepository::class),
            $this->get(ChatMessageRepository::class),
            $this->get(ChatUserSettingsRepository::class),
            $this->get(ChatPresence::class),
            $this->get(ChatAccess::class),
            $this->get(ChatPresenter::class),
            new ChatMarkdownRenderer(),
            $sender,
            $cache,
            '/inbox?chat={uuid}',
            60,
        );
    }
}
