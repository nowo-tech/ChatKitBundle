<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\FormKitBundle\DependencyInjection\FormKitExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle prepends its FormKit profile (REQ-TWIG-005 rule 11 / REQ-UI-003 "Form theme").
 */
final class NowoChatKitExtensionFormKitTest extends TestCase
{
    public function testPrependsTheFormKitProfile(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());

        (new NowoChatKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertCount(1, $configs);
        $profile = $configs[0]['profiles'][NowoChatKitBundle::FORM_KIT_PROFILE];
        self::assertSame(NowoChatKitExtension::formKitProfile(), $profile);
        self::assertSame('chat_kit', $profile['alias']);
        self::assertSame('NowoChatKitBundle', $profile['translation_domain']);
        self::assertFalse($profile['auto_help']);
        self::assertFalse($profile['auto_placeholder']);
    }

    public function testHostProfileWins(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        $container->prependExtensionConfig('nowo_form_kit', ['translation_domain' => 'messages']);
        $container->prependExtensionConfig('nowo_form_kit', ['profiles' => ['chat_kit' => ['alias' => 'chat_kit', 'translation_domain' => 'app']]]);

        (new NowoChatKitExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertCount(2, $configs);
        self::assertSame('app', $configs[0]['profiles']['chat_kit']['translation_domain']);
    }

    public function testNoFormKitExtensionPrependsNothing(): void
    {
        $container = new ContainerBuilder();

        (new NowoChatKitExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_form_kit'));
    }
}
