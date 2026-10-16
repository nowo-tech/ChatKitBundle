<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Realtime;

use DateTimeImmutable;
use LogicException;
use Nowo\ChatKitBundle\Channel\NullChannelProvider;
use Nowo\ChatKitBundle\LinkPreview\NullLinkPreviewProvider;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatLinkPreview;
use Nowo\ChatKitBundle\Push\NullChatPushSender;
use Nowo\ChatKitBundle\Realtime\ChatPublisher;
use Nowo\ChatKitBundle\Realtime\ChatTopics;
use Nowo\ChatKitBundle\Realtime\MercureChatRealtime;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;

final class RealtimeAdaptersTest extends TestCase
{
    public function testNullDefaults(): void
    {
        $realtime = new NullChatRealtime();
        $realtime->publish(['/t'], ['type' => 'x']);
        self::assertFalse($realtime->isEnabled());
        self::assertNull($realtime->browserHubUrl(new Request()));
        self::assertNull($realtime->subscriberToken(['/t'], new DateTimeImmutable()));

        $channels = new NullChannelProvider();
        $user     = new TestUser('Ana');
        $channel  = new ChatChannel('k', 'K');
        self::assertSame([], $channels->channelsFor($user));
        self::assertNull($channels->find('k'));
        self::assertNull($channels->contextChannel($user, new Request()));
        self::assertFalse($channels->canUse($user, $channel));
        self::assertSame([], $channels->members($channel));

        self::assertSame([], (new NullLinkPreviewProvider())->previews($user, ['/a']));
        self::assertSame(['title' => 'T', 'subtitle' => '', 'badge' => ''], (new ChatLinkPreview('T'))->toArray());

        $called = false;
        (new NullChatPushSender())->send([$user], static function () use (&$called): array {
            $called = true;

            return [];
        });
        self::assertFalse($called);
    }

    public function testMercureAdapterPublishesPrivateUpdatesAndSignsTokens(): void
    {
        $hub = new class implements HubInterface {
            /** @var list<Update> */
            public array $updates = [];

            public ?TokenFactoryInterface $factory = null;

            public function getUrl(): string
            {
                return 'http://mercure/.well-known/mercure';
            }

            public function getPublicUrl(): string
            {
                return 'https://app.test/.well-known/mercure';
            }

            public function getProvider(): TokenProviderInterface
            {
                throw new LogicException('unused');
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return $this->factory;
            }

            public function publish(Update $update): string
            {
                $this->updates[] = $update;

                return 'id';
            }
        };
        $realtime = new MercureChatRealtime($hub);
        self::assertTrue($realtime->isEnabled());
        self::assertSame('https://app.test/.well-known/mercure', $realtime->browserHubUrl(new Request()));
        self::assertNull($realtime->subscriberToken(['/t'], new DateTimeImmutable()), 'no factory configured');

        $realtime->publish(['/a', '/b'], ['type' => 'message']);
        self::assertSame(['/a', '/b'], $hub->updates[0]->getTopics());
        self::assertTrue($hub->updates[0]->isPrivate());
        self::assertSame('{"type":"message"}', $hub->updates[0]->getData());

        $hub->factory = new class implements TokenFactoryInterface {
            public function create(?array $subscribe = [], ?array $publish = [], array $additionalClaims = []): string
            {
                $exp = $additionalClaims['exp'] ?? null;

                return implode(',', (array) $subscribe) . '|' . ($exp instanceof DateTimeImmutable ? $exp->getTimestamp() : '');
            }
        };
        $expires = new DateTimeImmutable('@2000000000');
        self::assertSame('/a|2000000000', $realtime->subscriberToken(['/a'], $expires));
    }

    public function testPublisherFansOutToUniqueTopicsAndSwallowsHubErrors(): void
    {
        $realtime = new RecordingRealtime();
        $logger   = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };
        $publisher = new ChatPublisher($realtime, $logger);
        $ana       = new TestUser('Ana');

        $publisher->publish('typing', ['a' => 1], []);
        self::assertSame([], $realtime->published, 'no recipients, no publish');

        $publisher->publish('typing', ['a' => 1], [$ana, $ana]);
        self::assertSame([['topics' => [ChatTopics::forUser($ana->getChatPublicId())], 'payload' => ['type' => 'typing', 'a' => 1]]], $realtime->published);

        $realtime->failing = true;
        $publisher->publish('typing', [], [$ana]);
        self::assertSame(['Realtime publish failed for a chat event.'], $logger->warnings);

        (new ChatPublisher(new NullChatRealtime()))->publish('typing', [], [$ana]);
    }
}
