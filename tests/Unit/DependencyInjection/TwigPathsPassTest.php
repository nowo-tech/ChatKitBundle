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
        $project = sys_get_temp_dir().'/nowo-chat-twig-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($project.'/templates/bundles/NowoChatKitBundle');
        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $project);
            $container->register('twig.loader.filesystem');
            (new TwigPathsPass())->process($container);

            $calls = $container->getDefinition('twig.loader.filesystem')->getMethodCalls();
            self::assertSame(['prependPath', [$project.'/templates/bundles/NowoChatKitBundle', 'NowoChatKitBundle']], $calls[0]);
            self::assertSame('addPath', $calls[1][0]);
            self::assertStringEndsWith('/Resources/views', $calls[1][1][0]);
            self::assertSame(['addPath', [$calls[1][1][0], '!NowoChatKitBundle']], $calls[2]);
        } finally {
            (new Filesystem())->remove($project);
        }
    }
}
