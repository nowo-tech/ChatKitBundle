<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration;

use LogicException;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function assert;

/**
 * 2.0 (REQ-ARCH-001 rule 3, audit lesson W1-1 / XR-WF-M1): through the real NowoChatKitBundle::build(), an application
 * service still on a port interface removed in 2.0 fails the container build with a message naming the service, the
 * removed interface and its replacement. Without the check Symfony would drop it silently (an autodiscovered class that
 * cannot be loaded, or a decorator of a missing id) and the bundle would fall back to its default: no host channels, no
 * host realtime transport, no host participant mapping. Fixtures: tests/Fixtures/RemovedPorts (one autodiscovered
 * directory per case).
 */
final class RemovedPortsKernelTest extends TestCase
{
    private const string CHANNELS = 'Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface';

    private const string REALTIME = 'Nowo\ChatKitBundle\Contract\ChatRealtimeInterface';

    private const string CURRENT_USER = 'Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface';

    private const string FIXTURES = 'Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\\';

    /**
     * @return iterable<string, array{string, string, string}> fixture directory, the service the message names, the removed interface
     */
    public static function removedPorts(): iterable
    {
        yield '#[AsAlias] of the channel provider id' => ['AliasOfChannels', self::FIXTURES . 'AliasOfChannels\HostChannels', self::CHANNELS];
        yield '#[AsAlias] of the realtime id' => ['AliasOfRealtime', self::FIXTURES . 'AliasOfRealtime\HostRealtime', self::REALTIME];
        yield '#[AsAlias] of the current-user id' => ['AliasOfParticipants', self::FIXTURES . 'AliasOfParticipants\HostParticipants', self::CURRENT_USER];
        yield '#[AsDecorator] of the channel provider' => ['DecoratorOfChannels', self::FIXTURES . 'DecoratorOfChannels\AuditedChannels', self::CHANNELS];
        yield '#[AsDecorator] of the realtime transport' => ['DecoratorOfRealtime', self::FIXTURES . 'DecoratorOfRealtime\AuditedRealtime', self::REALTIME];
        yield '#[AsDecorator] of the current-user resolver' => ['DecoratorOfParticipants', self::FIXTURES . 'DecoratorOfParticipants\AuditedParticipants', self::CURRENT_USER];
        yield 'a loadable #[AsDecorator] of a removed id, onInvalid: IGNORE' => ['IgnoredDecoratorOfRemoved', self::FIXTURES . 'IgnoredDecoratorOfRemoved\RestrictedParticipants', self::CURRENT_USER];
        yield 'a class implementing only the channel provider' => ['ImplementsChannels', self::FIXTURES . 'ImplementsChannels\OldChannels', self::CHANNELS];
        yield 'a class implementing only the current-user resolver' => ['ImplementsParticipants', self::FIXTURES . 'ImplementsParticipants\OldParticipants', self::CURRENT_USER];
        yield 'the twin id aliased to a class implementing only the removed interface' => ['AliasedToTheTwin', self::FIXTURES . 'AliasedToTheTwin\OldChannels', self::CHANNELS];
    }

    #[DataProvider('removedPorts')]
    public function testAServiceOnARemovedPortFailsTheBuild(string $fixture, string $service, string $removed): void
    {
        $kernel = new TestKernel(removedPortsFixture: $fixture);
        try {
            $kernel->boot();
            self::fail('the build must fail');
        } catch (LogicException $failure) {
            self::assertSame(RemovedPortsPass::message($service, $removed), $failure->getMessage());
            self::assertStringContainsString('deprecated in 1.27 and removed in 2.0', $failure->getMessage());
            self::assertStringContainsString(RemovedPortsPass::REMOVED[$removed], $failure->getMessage(), 'the replacement is named');
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAnUnrelatedMissingInterfaceKeepsItsLazyErrorAndTheBuildSucceeds(): void
    {
        $kernel = new TestKernel(removedPortsFixture: 'UnrelatedMissing');
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            assert($container instanceof ContainerInterface);
            self::assertFalse($container->has(self::FIXTURES . 'UnrelatedMissing\PolicyAdapter'), 'Symfony dropped the unused class as before');
        } finally {
            $kernel->shutdown();
        }
    }
}
