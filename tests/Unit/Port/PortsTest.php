<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Port;

use DateTimeImmutable;
use LogicException;
use Nowo\ChatKitBundle\Contract\ChatChannelMentionPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelWritePolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatRequestContext;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ErasedChatUser;
use Nowo\ChatKitBundle\Port\ChatChannelSourceGate;
use Nowo\ChatKitBundle\Port\ChatParticipantResolverGate;
use Nowo\ChatKitBundle\Port\ChatRealtimeTransportGate;
use Nowo\ChatKitBundle\Port\Ports;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.27: the selection rule of the three ports (deprecated, both, twin only, neither) and the gates passing the values
 * through.
 */
final class PortsTest extends TestCase
{
    public function testADeprecatedImplementationIsAskedAsIs(): void
    {
        $provider = $this->createStub(ChatChannelProviderInterface::class);
        $realtime = $this->createStub(ChatRealtimeInterface::class);
        $resolver = $this->createStub(CurrentChatUserResolverInterface::class);

        self::assertSame($provider, Ports::channelProvider($provider));
        self::assertSame($realtime, Ports::realtime($realtime));
        self::assertSame($resolver, Ports::currentUser($resolver));
        self::assertSame($provider, Ports::companion($provider));
    }

    public function testAServiceImplementingBothIsAskedThroughTheDeprecatedInterface(): void
    {
        $both = new class implements ChatRealtimeInterface, ChatRealtimeTransportInterface {
            public function isEnabled(): bool
            {
                return true;
            }

            public function publish(array $topics, array $payload): void
            {
            }

            public function browserHubUrl(Request|ChatRequestContext $request): ?string
            {
                return $request instanceof Request ? 'legacy' : 'twin';
            }

            public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
            {
                return null;
            }
        };

        self::assertSame($both, Ports::realtime($both));
        self::assertSame('legacy', Ports::realtime($both)->browserHubUrl(Request::create('/')));
    }

    public function testATwinOnlyChannelSourceIsGatedAndKeepsItsCompanions(): void
    {
        $source = new TwinOnlySource();
        $gate   = Ports::channelProvider($source);
        $user   = new ErasedChatUser(1, 'x', 'X');

        self::assertInstanceOf(ChatChannelSourceGate::class, $gate);
        self::assertSame($source, Ports::companion($gate));
        self::assertInstanceOf(ChatChannelWritePolicyInterface::class, Ports::companion($gate));
        self::assertSame([$source->channel], $gate->channelsFor($user));
        self::assertSame($source->channel, $gate->find('project:a'));
        self::assertNull($gate->find('project:b'));
        self::assertTrue($gate->canUse($user, $source->channel));
        self::assertCount(1, $gate->members($source->channel));
        self::assertSame($source->channel, $gate->contextChannel($user, Request::create('https://app.example.test/projects/a?tab=chat')));
        self::assertNull($gate->contextChannel($user, Request::create('https://app.example.test/elsewhere')));
        self::assertInstanceOf(ChatRequestContext::class, $source->lastPage);
        self::assertSame('/elsewhere', $source->lastPage->path);
    }

    public function testATwinOnlyRealtimeIsGated(): void
    {
        $transport = new class implements ChatRealtimeTransportInterface {
            /** @var list<array{list<string>, array<string, mixed>}> */
            public array $published = [];

            public function isEnabled(): bool
            {
                return true;
            }

            public function publish(array $topics, array $payload): void
            {
                $this->published[] = [$topics, $payload];
            }

            public function browserHubUrl(ChatRequestContext $request): ?string
            {
                return $request->origin() . '/.well-known/mercure';
            }

            public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
            {
                return implode(',', $topics) . '@' . $expiresAt->getTimestamp();
            }
        };
        $gate = Ports::realtime($transport);

        self::assertInstanceOf(ChatRealtimeTransportGate::class, $gate);
        self::assertTrue($gate->isEnabled());
        $gate->publish(['t'], ['type' => 'x']);
        self::assertSame([[['t'], ['type' => 'x']]], $transport->published);
        self::assertSame('http://localhost:8080/.well-known/mercure', $gate->browserHubUrl(Request::create('http://localhost:8080/chat/api/realtime')));
        self::assertSame('t@10', $gate->subscriberToken(['t'], new DateTimeImmutable('@10')));
    }

    public function testATwinOnlyResolverGetsTheSecurityUserAsAValue(): void
    {
        $participant = new ErasedChatUser(2, 'p', 'P');
        $resolver    = new class($participant) implements ChatParticipantResolverInterface {
            public ?ChatSecurityUser $seen = null;

            public function __construct(private readonly ChatUserInterface $participant)
            {
            }

            public function resolve(?ChatSecurityUser $user): ?ChatUserInterface
            {
                $this->seen = $user;

                return $user?->identifier === 'ana' ? $this->participant : null;
            }
        };
        $gate = Ports::currentUser($resolver);

        self::assertInstanceOf(ChatParticipantResolverGate::class, $gate);
        self::assertSame($participant, $gate->resolve(new InMemoryUser('ana', null, ['ROLE_USER'])));
        self::assertInstanceOf(ChatSecurityUser::class, $resolver->seen);
        self::assertSame(InMemoryUser::class, $resolver->seen->userClass);
        self::assertNull($resolver->seen->id);
        self::assertSame(['ROLE_USER'], $resolver->seen->roles);
        self::assertNull($gate->resolve(null));
        self::assertNull($resolver->seen);
        self::assertNull($gate->resolve(new InMemoryUser('luis', null)));
    }

    public function testNeitherFailsClosed(): void
    {
        foreach (['channelProvider', 'realtime', 'currentUser'] as $factory) {
            try {
                Ports::{$factory}(new stdClass());
                self::fail($factory . ' accepted a service implementing neither interface');
            } catch (LogicException $exception) {
                self::assertStringContainsString('must implement', $exception->getMessage());
            }
        }
    }

    public function testAGateUserImplementingNoUserInterfaceIsNull(): void
    {
        $resolver = new class implements ChatParticipantResolverInterface {
            public int $calls = 0;

            public function resolve(?ChatSecurityUser $user): ?ChatUserInterface
            {
                ++$this->calls;

                return null;
            }
        };
        self::assertNull(Ports::currentUser($resolver)->resolve($this->createStub(UserInterface::class)));
        self::assertSame(1, $resolver->calls);
    }
}
