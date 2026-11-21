<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\ChatKitBundle\Channel\NullChannelProvider;
use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\ChatKitPortsPass;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Port\ChatChannelPort;
use Nowo\ChatKitBundle\Port\ChatChannelSourceGate;
use Nowo\ChatKitBundle\Port\ChatCurrentUserPort;
use Nowo\ChatKitBundle\Port\ChatParticipantResolverGate;
use Nowo\ChatKitBundle\Port\ChatRealtimePort;
use Nowo\ChatKitBundle\Port\Ports;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Nowo\ChatKitBundle\Tests\Unit\Port\TwinOnlySource;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.27: the build puts an application's twin behind the bundle's internal port and refuses a twin service that does not
 * implement it. 2.0: the twins are the only host interfaces.
 */
final class ChatKitPortsPassTest extends TestCase
{
    public function testTheDefaultsAreLeftAlone(): void
    {
        $container = $this->container();
        (new ChatKitPortsPass())->process($container);

        self::assertSame(NullChannelProvider::class, (string) $container->getAlias(ChatChannelPort::class));
        self::assertSame(NullChatRealtime::class, (string) $container->getAlias(ChatRealtimePort::class));
        self::assertSame(SecurityChatUserResolver::class, (string) $container->getAlias(ChatCurrentUserPort::class));
    }

    public function testATwinAliasIsGated(): void
    {
        $container = $this->container();
        $container->register('app.channels', TwinOnlySource::class);
        $container->setAlias(ChatChannelSourceInterface::class, 'app.channels');
        $container->getAlias(ChatChannelPort::class)->setPublic(true);
        (new ChatKitPortsPass())->process($container);

        $definition = $container->getDefinition(ChatChannelPort::class);
        self::assertSame([Ports::class, 'channelProvider'], $definition->getFactory());
        self::assertEquals([new Reference(ChatChannelSourceInterface::class)], $definition->getArguments());
        self::assertTrue($definition->isPublic(), 'the internal id keeps its visibility');
        $container->compile();
        self::assertInstanceOf(ChatChannelSourceGate::class, $container->get(ChatChannelPort::class));
    }

    public function testATwinAliasReplacesTheBundleDefaultResolver(): void
    {
        $container = $this->container();
        $container->register('app.resolver', TwinResolver::class);
        $container->setAlias(ChatParticipantResolverInterface::class, 'app.resolver');
        (new ChatKitPortsPass())->process($container);

        self::assertSame([Ports::class, 'currentUser'], $container->getDefinition(ChatCurrentUserPort::class)->getFactory());
        self::assertFalse($container->getDefinition(ChatCurrentUserPort::class)->isPublic());
        $container->getDefinition(ChatCurrentUserPort::class)->setPublic(true);
        $container->compile();
        self::assertInstanceOf(ChatParticipantResolverGate::class, $container->get(ChatCurrentUserPort::class));
    }

    public function testAServiceDefinedUnderTheTwinIdIsGated(): void
    {
        $container = $this->container();
        $container->register(ChatChannelSourceInterface::class, TwinOnlySource::class);
        (new ChatKitPortsPass())->process($container);

        self::assertEquals([new Reference(ChatChannelSourceInterface::class)], $container->getDefinition(ChatChannelPort::class)->getArguments());
    }

    public function testAFactoryServiceIsDecidedAtRuntime(): void
    {
        $container = $this->container();
        $container->register('app.channels', stdClass::class)->setFactory([self::class, 'make']);
        $container->setAlias(ChatChannelSourceInterface::class, 'app.channels');
        (new ChatKitPortsPass())->process($container);

        self::assertSame([Ports::class, 'channelProvider'], $container->getDefinition(ChatChannelPort::class)->getFactory());
        $container->getDefinition(ChatChannelPort::class)->setPublic(true);
        $container->compile();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement "' . ChatChannelSourceInterface::class . '"');
        $container->get(ChatChannelPort::class);
    }

    public function testAnAliasToAServiceDefinedLaterIsDecidedAtRuntime(): void
    {
        $container = $this->container();
        $container->setAlias(ChatChannelSourceInterface::class, 'app.later');
        (new ChatKitPortsPass())->process($container);

        self::assertSame([Ports::class, 'channelProvider'], $container->getDefinition(ChatChannelPort::class)->getFactory());
    }

    public function testATwinServiceThatDoesNotImplementTheTwinFailsTheBuild(): void
    {
        $container = $this->container();
        $container->register('app.realtime', stdClass::class);
        $container->setAlias(ChatRealtimeTransportInterface::class, 'app.realtime');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"app.realtime" (stdClass) of ' . ChatRealtimeTransportInterface::class . ' must implement it');
        (new ChatKitPortsPass())->process($container);
    }

    public function testAMissingPortIsSkipped(): void
    {
        $container = new ContainerBuilder();
        (new ChatKitPortsPass())->process($container);
        self::assertFalse($container->has(ChatChannelPort::class));

        $container->register('app.channels', TwinOnlySource::class);
        $container->setAlias(ChatChannelSourceInterface::class, 'app.channels');
        (new ChatKitPortsPass())->process($container);
        self::assertFalse($container->getDefinition(ChatChannelPort::class)->isPublic(), 'a twin without the bundle default is still gated');
    }

    /**
     * A factory whose product implements no port interface.
     */
    public static function make(): stdClass
    {
        return new stdClass();
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(NullChannelProvider::class, NullChannelProvider::class);
        $container->register(NullChatRealtime::class, NullChatRealtime::class);
        $container->register(SecurityChatUserResolver::class, SecurityChatUserResolver::class);
        $container->setAlias(ChatChannelPort::class, NullChannelProvider::class);
        $container->setAlias(ChatRealtimePort::class, NullChatRealtime::class);
        $container->setAlias(ChatCurrentUserPort::class, SecurityChatUserResolver::class);

        return $container;
    }
}

/**
 * A twin-only resolver.
 */
final class TwinResolver implements ChatParticipantResolverInterface
{
    public function resolve(?ChatSecurityUser $user): ?ChatUserInterface
    {
        return null;
    }
}
