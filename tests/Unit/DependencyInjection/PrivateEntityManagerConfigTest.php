<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\PrivateEntityManagerReferencesPass;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Deprecation\DeprecationRecorder;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.27: the private entity manager — the services it registers, the references pass (bundle services only) and the
 * middleware's connection. 2.0: the only mode; `doctrine.private_entity_manager: false` fails the build, `true` is a
 * deprecated no-op.
 */
final class PrivateEntityManagerConfigTest extends TestCase
{
    /** 2.0: without the option the private manager is registered (the only mode), with no notice. */
    public function testTheDefaultRegistersThePrivateManager(): void
    {
        [$container, $messages] = DeprecationRecorder::record('nowo-tech/chat-kit-bundle', fn (): ContainerBuilder => $this->load([]));

        self::assertSame([], $messages);
        self::assertTrue($container->getParameter(PrivateEntityManagerReferencesPass::PARAMETER));
        self::assertSame('default', $container->getParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER));
        self::assertTrue($this->registered($container, ChatKitEntityManager::class));
        self::assertTrue($this->registered($container, ChatKitManagerRegistry::class));
        $runner = $container->getDefinition(ChatKitTransactionRunner::SERVICE_ID);
        self::assertSame('%' . PrivateEntityManagerReferencesPass::SOURCE_PARAMETER . '%', $runner->getArgument(2));
        self::assertTrue($runner->getArgument(3));
        self::assertCount(6, $runner->getArguments(), 'no host-flush probe');
        $memos = $container->getDefinition('Nowo\\ChatKitBundle\\Worker\\ChatRequestBoundaryListener')->getArgument('$memos');
        self::assertInstanceOf(IteratorArgument::class, $memos);
        self::assertNotContains('Nowo\\ChatKitBundle\\Doctrine\\ChatHostFlushProbe', array_map(strval(...), $memos->getValues()));
        self::assertFalse($container->hasDefinition('Nowo\\ChatKitBundle\\Doctrine\\ChatHostFlushProbe'));
    }

    /** 2.0: an explicit false fails the build, naming the profile, in any profile (not only the default one). */
    public function testAnExplicitFalseFailsTheBuildNamingTheProfile(): void
    {
        foreach (['default' => [['profiles' => ['default' => ['user_class' => 'App\\User', 'doctrine' => ['private_entity_manager' => false]]]]], 'second' => [['profiles' => ['default' => ['user_class' => 'App\\User'], 'second' => ['user_class' => 'App\\User', 'doctrine' => ['private_entity_manager' => false]]]]]] as $profile => $configs) {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.secret', 'x');
            try {
                DeprecationRecorder::record('private_entity_manager', static fn () => (new NowoChatKitExtension())->load($configs, $container));
                self::fail('false must fail the build');
            } catch (InvalidConfigurationException $failure) {
                self::assertSame(NowoChatKitExtension::privateEntityManagerFalseMessage($profile), $failure->getMessage());
                self::assertStringContainsString('"nowo_chat_kit.profiles.' . $profile . '.doctrine.private_entity_manager: false" is no longer supported', $failure->getMessage());
                self::assertStringContainsString('UPGRADING.md, "To 2.0.0"', $failure->getMessage());
            }
        }
    }

    /** 2.0 (owner answer Q4): an explicit true is accepted as a deprecated no-op until 3.0. */
    public function testAnExplicitTrueIsADeprecatedNoOp(): void
    {
        [$container, $messages] = DeprecationRecorder::record('private_entity_manager', fn (): ContainerBuilder => $this->load(['private_entity_manager' => true]));

        self::assertSame(['Since nowo-tech/chat-kit-bundle 2.0: The "nowo_chat_kit.profiles.default.doctrine.private_entity_manager" option has no effect since 2.0 (the private entity manager is the only mode); remove it.'], $messages);
        self::assertTrue($container->getParameter(PrivateEntityManagerReferencesPass::PARAMETER));
        self::assertTrue($this->registered($container, ChatKitEntityManager::class));
    }

    public function testThePrivateManagerIsCreatedOnTheSourceAndTheBoundaryDropsItLast(): void
    {
        $container = $this->load(['entity_manager' => 'chat']);

        self::assertTrue($container->getParameter(PrivateEntityManagerReferencesPass::PARAMETER));
        self::assertSame('chat', $container->getParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER));
        self::assertSame(['chat'], [$container->getDefinition(ChatKitEntityManager::class)->getArgument(1)]);
        self::assertTrue($container->getDefinition(ChatKitEntityManager::class)->hasTag('kernel.reset'));
        self::assertTrue($container->hasDefinition(ChatKitManagerRegistry::class));
        self::assertTrue($container->hasDefinition(PrivateEntityManagerMiddleware::class));
        $memos = $container->getDefinition('Nowo\\ChatKitBundle\\Worker\\ChatRequestBoundaryListener')->getArgument('$memos');
        self::assertInstanceOf(IteratorArgument::class, $memos);
        $values = $memos->getValues();
        $last   = end($values);
        self::assertInstanceOf(Reference::class, $last);
        self::assertSame(ChatKitEntityManager::class, (string) $last);
    }

    public function testTheReferencesPassMovesOnlyBundleServices(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(PrivateEntityManagerReferencesPass::PARAMETER, true);
        $container->setParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER, 'default');
        $container->register('doctrine.orm.default_entity_manager', EntityManagerInterface::class);
        $container->setAlias(EntityManagerInterface::class, 'doctrine.orm.default_entity_manager');
        $container->register('doctrine', stdClass::class);
        $bundle = $container->register(ChatService::class, ChatService::class)
            ->setArguments([new Reference(EntityManagerInterface::class), new Definition(stdClass::class, [new Reference('doctrine')])])
            ->setProperty('em', new Reference('doctrine.orm.default_entity_manager'))
            ->addMethodCall('set', [new IteratorArgument([new Reference('doctrine')])]);
        $host = $container->register('App\\Service', stdClass::class)->setArguments([new Reference(EntityManagerInterface::class), new Reference('doctrine')]);
        $test = $container->register('Nowo\\ChatKitBundle\\Tests\\Support\\X', stdClass::class)->setArguments([new Reference(EntityManagerInterface::class)]);

        (new PrivateEntityManagerReferencesPass())->process($container);

        self::assertSame(ChatKitEntityManager::class, (string) $bundle->getArgument(0));
        $inline = $bundle->getArgument(1);
        self::assertInstanceOf(Definition::class, $inline);
        self::assertSame(ChatKitManagerRegistry::class, (string) $inline->getArgument(0));
        self::assertSame(ChatKitEntityManager::class, (string) $bundle->getProperties()['em']);
        $call = $bundle->getMethodCalls()[0][1][0];
        self::assertInstanceOf(IteratorArgument::class, $call);
        self::assertSame(ChatKitManagerRegistry::class, (string) $call->getValues()[0]);
        self::assertSame(EntityManagerInterface::class, (string) $host->getArgument(0), 'host services keep the application manager');
        self::assertSame('doctrine', (string) $host->getArgument(1));
        self::assertSame(EntityManagerInterface::class, (string) $test->getArgument(0), 'test doubles are host services');

        $off = new ContainerBuilder();
        $off->setParameter(PrivateEntityManagerReferencesPass::PARAMETER, false);
        $kept = $off->register(ChatService::class, ChatService::class)->setArguments([new Reference(EntityManagerInterface::class)]);
        (new PrivateEntityManagerReferencesPass())->process($off);
        self::assertSame(EntityManagerInterface::class, (string) $kept->getArgument(0));
    }

    public function testTheMiddlewareGoesOnTheSourceConnectionOnly(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER, 'chat');
        $container->register(PrivateEntityManagerMiddleware::class, PrivateEntityManagerMiddleware::class)->addTag('doctrine.middleware');
        $container->register('doctrine.orm.chat_entity_manager', EntityManagerInterface::class)->setArguments([new Reference('doctrine.dbal.chat_db_connection')]);
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'chat_db']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $fallback = new ContainerBuilder();
        $fallback->setParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER, 'default');
        $fallback->setParameter('doctrine.default_connection', 'main');
        $fallback->register(PrivateEntityManagerMiddleware::class, PrivateEntityManagerMiddleware::class);
        (new PrivateEntityManagerPass())->process($fallback);
        self::assertSame([['connection' => 'main']], $fallback->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $none = new ContainerBuilder();
        (new PrivateEntityManagerPass())->process($none);
        self::assertFalse($none->hasDefinition(PrivateEntityManagerMiddleware::class));
    }

    private function registered(ContainerBuilder $container, string $id): bool
    {
        return $container->hasDefinition($id) && !$container->getDefinition($id)->hasTag('container.excluded');
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    private function load(array $doctrine): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.secret', 'x');
        (new NowoChatKitExtension())->load([['profiles' => ['default' => ['user_class' => 'App\\User', 'doctrine' => $doctrine]]]], $container);

        return $container;
    }
}
