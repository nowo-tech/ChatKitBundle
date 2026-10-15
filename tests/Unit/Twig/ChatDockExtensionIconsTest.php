<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Twig;

use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\LinkPreview\NullLinkPreviewProvider;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Twig\ChatDockExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Icons\IconRendererInterface;

final class ChatDockExtensionIconsTest extends TestCase
{
    public function testBuiltInSetOrNoRendererMeansNoApplicationIcons(): void
    {
        $renderer = $this->createMock(IconRendererInterface::class);
        $renderer->expects(self::never())->method('renderIcon');

        self::assertSame([], $this->extension('svg_inline', $renderer)->icons());
        self::assertSame([], $this->extension('ux_icon', null)->icons());
    }

    private function extension(string $iconSet, ?IconRendererInterface $renderer): ChatDockExtension
    {
        return new ChatDockExtension(
            $this->createStub(Security::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(CsrfTokenManagerInterface::class),
            $this->createStub(TranslatorInterface::class),
            new RequestStack(),
            new ChatAccess($this->createStub(ChatAccessPolicyInterface::class), $this->createStub(ChatChannelProviderInterface::class), $this->createStub(ChatDirectoryInterface::class)),
            $this->createStub(ChatChannelProviderInterface::class),
            $this->createStub(ChatUserSettingsRepository::class),
            new NullLinkPreviewProvider(),
            iconSet: $iconSet,
            icons: ['send' => 'tabler:send'],
            iconRenderer: $renderer,
        );
    }
}
