<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\ChatKitBundle\Channel\NullChannelProvider;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\ChatKitPortsPass;
use Nowo\ChatKitBundle\Port\ChatChannelSourceGate;
use Nowo\ChatKitBundle\Port\ChatParticipantResolverGate;
use Nowo\ChatKitBundle\Port\Ports;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Nowo\ChatKitBundle\Tests\Unit\Port\TwinOnlySource;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.27: the build wires the twins and refuses a port service that implements neither interface.
 */
final class ChatKitPortsPassTest extends TestCase
{
    public function testTheDefaultsAndDeprecatedImplementationsAreLeftAlone(): void
    {
        $container = $this->container();
        (new ChatKitPortsPass())->process($container);

        self::assertSame(NullChannelProvider::class, (string) $container->getAlias(ChatChannelProviderInterface::class));
        self::assertSame(NullChatRealtime::class, (string) $container->getAlias(ChatRealtimeInterface::class));
        self::assertSame(SecurityChatUserResolver::class, (string) $container->getAlias(CurrentChatUserResolverInterface::class));
    }

    public function testATwinOnlyServiceUnderTheDeprecatedIdIsGated(): void
    {
        $container = $this->container();
        $container->register('app.channels', TwinOnlySource::class);
        $container->setAlias(ChatChannelProviderInterface::class, 'app.channels')->setPublic(true);
        (new ChatKitPortsPass())->process($container);

        $definition = $container->getDefinition(ChatChannelProviderInterface::class);
        self::assertSame([Ports::class, 'channelProvider'], $definition->getFactory());
        self::assertEquals([new Reference('app.channels')], $definition->getArguments());
        self::assertTrue($definition->isPublic());
        $container->compile();
        self::assertInstanceOf(ChatChannelSourceGate::class, $container->get(ChatChannelProviderInterface::class));
    }

    public function testATwinAliasReplacesTheBundleDefault(): void
    {
        $container = $this->container();
        $container->register('app.resolver', TwinResolver::class);
        $container->setAlias(ChatParticipantResolverInterface::class, 'app.resolver');
        (new ChatKitPortsPass())->process($container);

        self::assertSame([Ports::class, 'currentUser'], $container->getDefinition(CurrentChatUserResolverInterface::class)->getFactory());
        $container->getDefinition(CurrentChatUserResolverInterface::class)->setPublic(true);
        $container->compile();
        self::assertInstanceOf(ChatParticipantResolverGate::class, $container->get(CurrentChatUserResolverInterface::class));
    }

    public function testATwinAliasToADeprecatedServiceReplacesTheDefaultAsIs(): void
    {
        $container = $this->container();
        $container->register('app.resolver', LegacyResolver::class);
        $container->setAlias(ChatParticipantResolverInterface::class, 'app.resolver');
        (new ChatKitPortsPass())->process($container);

        self::assertSame('app.resolver', (string) $container->getAlias(CurrentChatUserResolverInterface::class));
    }

    public function testAnExplicitDeprecatedAliasWinsOverATwinAlias(): void
    {
        $container = $this->container();
        $container->register('app.legacy', LegacyResolver::class);
        $container->register('app.resolver', TwinResolver::class);
        $container->setAlias(CurrentChatUserResolverInterface::class, 'app.legacy');
        $container->setAlias(ChatParticipantResolverInterface::class, 'app.resolver');
        (new ChatKitPortsPass())->process($container);

        self::assertSame('app.legacy', (string) $container->getAlias(CurrentChatUserResolverInterface::class));
    }

    public function testAServiceDefinedUnderThePortIdIsKeptAsInner(): void
    {
        $container = $this->container();
        $container->removeAlias(ChatChannelProviderInterface::class);
        $container->register(ChatChannelProviderInterface::class, TwinOnlySource::class);
        (new ChatKitPortsPass())->process($container);

        self::assertTrue($container->hasDefinition(ChatChannelProviderInterface::class . '.inner'));
        self::assertEquals([new Reference(ChatChannelProviderInterface::class . '.inner')], $container->getDefinition(ChatChannelProviderInterface::class)->getArguments());
    }

    public function testAFactoryServiceIsDecidedAtRuntime(): void
    {
        $container = $this->container();
        $container->register('app.channels', stdClass::class)->setFactory([self::class, 'make']);
        $container->setAlias(ChatChannelProviderInterface::class, 'app.channels')->setPublic(true);
        (new ChatKitPortsPass())->process($container);

        self::assertSame([Ports::class, 'channelProvider'], $container->getDefinition(ChatChannelProviderInterface::class)->getFactory());
        $container->compile();
        $this->expectException(LogicException::class);
        $container->get(ChatChannelProviderInterface::class);
    }

    public function testNeitherInterfaceFailsTheBuild(): void
    {
        $container = $this->container();
        $container->register('app.realtime', stdClass::class);
        $container->setAlias(ChatRealtimeInterface::class, 'app.realtime');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"app.realtime" (stdClass) of ' . ChatRealtimeInterface::class . ' must implement');
        (new ChatKitPortsPass())->process($container);
    }

    public function testAMissingPortIsSkipped(): void
    {
        $container = new ContainerBuilder();
        (new ChatKitPortsPass())->process($container);
        self::assertFalse($container->has(ChatChannelProviderInterface::class));
    }

    /**
     * A factory whose product implements neither port interface.
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
        $container->setAlias(ChatChannelProviderInterface::class, NullChannelProvider::class);
        $container->setAlias(ChatRealtimeInterface::class, NullChatRealtime::class);
        $container->setAlias(CurrentChatUserResolverInterface::class, SecurityChatUserResolver::class);

        return $container;
    }
}

/**
 * A twin-only resolver.
 */
final class TwinResolver implements ChatParticipantResolverInterface
{
    public function resolve(?\Nowo\ChatKitBundle\Model\ChatSecurityUser $user): ?\Nowo\ChatKitBundle\Model\ChatUserInterface
    {
        return null;
    }
}

/**
 * A resolver of the deprecated interface.
 */
final class LegacyResolver implements CurrentChatUserResolverInterface
{
    public function resolve(?\Symfony\Component\Security\Core\User\UserInterface $user): ?\Nowo\ChatKitBundle\Model\ChatUserInterface
    {
        return null;
    }
}
