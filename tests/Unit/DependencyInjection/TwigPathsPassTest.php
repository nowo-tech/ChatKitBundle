<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;

final class TwigPathsPassTest extends TestCase
{
    public function testNoTwigNoPaths(): void
    {
        $container = new ContainerBuilder();
        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native_filesystem'));
    }

    public function testApplicationOverridesArePrepended(): void
    {
        $project = sys_get_temp_dir() . '/nowo-chat-twig-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($project . '/templates/bundles/NowoChatKitBundle');
        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $project);
            $container->register('twig.loader.native_filesystem');
            $container->setAlias('twig.loader.filesystem', 'twig.loader.native_filesystem');
            (new TwigPathsPass())->process($container);

            $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
            self::assertSame(['prependPath', [$project . '/templates/bundles/NowoChatKitBundle', 'NowoChatKitBundle']], $calls[0]);
            self::assertSame('addPath', $calls[1][0]);
            self::assertStringEndsWith('/Resources/views', $calls[1][1][0]);
            self::assertSame(['addPath', [$calls[1][1][0], '!NowoChatKitBundle']], $calls[2]);
        } finally {
            (new Filesystem())->remove($project);
        }
    }

    public function testLoaderResolutionFollowsTheCanonicalOrder(): void
    {
        // 1. The definition behind the `twig.loader.native` alias, through alias chains.
        $container = $this->container();
        $container->register('app.twig_loader');
        $container->register('twig.loader.native_filesystem');
        $container->setAlias('app.twig_loader_alias', 'app.twig_loader');
        $container->setAlias('twig.loader.native', 'app.twig_loader_alias');
        (new TwigPathsPass())->process($container);
        self::assertCount(2, $container->getDefinition('app.twig_loader')->getMethodCalls());
        self::assertSame([], $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());

        // An alias to nothing falls through to `twig.loader.native_filesystem`.
        $container = $this->container();
        $container->register('twig.loader.native_filesystem');
        $container->setAlias('twig.loader.native', 'missing.loader');
        (new TwigPathsPass())->process($container);
        self::assertCount(2, $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());

        // 2. A `twig.loader.native` definition wins over `twig.loader.native_filesystem`.
        $container = $this->container();
        $container->register('twig.loader.native');
        $container->register('twig.loader.native_filesystem');
        (new TwigPathsPass())->process($container);
        self::assertCount(2, $container->getDefinition('twig.loader.native')->getMethodCalls());
        self::assertSame([], $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());

        // Only the plain `twig.loader.filesystem` id (no native loader): nothing is registered.
        $container = $this->container();
        $container->register('twig.loader.filesystem');
        (new TwigPathsPass())->process($container);
        self::assertSame([], $container->getDefinition('twig.loader.filesystem')->getMethodCalls());
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/nowo-chat-twig-none');

        return $container;
    }
}
