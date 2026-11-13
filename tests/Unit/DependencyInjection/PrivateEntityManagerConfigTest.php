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
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.27: `doctrine.private_entity_manager` — the services it registers, the deprecation of an explicit false, the
 * references pass (bundle services only) and the middleware's connection.
 */
final class PrivateEntityManagerConfigTest extends TestCase
{
    public function testTheDefaultRegistersNothing(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->getParameter(PrivateEntityManagerReferencesPass::PARAMETER));
        self::assertSame('default', $container->getParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER));
        self::assertFalse($this->registered($container, ChatKitEntityManager::class));
        self::assertFalse($this->registered($container, ChatKitManagerRegistry::class));
    }

    #[IgnoreDeprecations]
    public function testAnExplicitFalseIsDeprecated(): void
    {
        $this->expectUserDeprecationMessageMatches('/private_entity_manager: false" is deprecated/');
        $container = $this->load(['private_entity_manager' => false]);
        self::assertFalse($this->registered($container, ChatKitEntityManager::class));
    }

    public function testTrueRegistersThePrivateManagerAndTheBoundaryDropsItLast(): void
    {
        $container = $this->load(['private_entity_manager' => true, 'entity_manager' => 'chat']);

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
