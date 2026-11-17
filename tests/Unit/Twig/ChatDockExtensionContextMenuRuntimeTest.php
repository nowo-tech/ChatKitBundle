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
use ReflectionMethod;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * 1.33 `web_ui.message_context_menu: true`: the address of UiKit's js/nowo-ui-context-menu.js, from UiKit's asset
 * package; nothing with null (UiKit's theme flag and the host's scripts partial decide) or false, without symfony/asset
 * or without UiKit's package.
 */
final class ChatDockExtensionContextMenuRuntimeTest extends TestCase
{
    public function testTheRuntimeComesFromUiKitsPackage(): void
    {
        $packages = new Packages(null, ['nowo_ui_kit' => new Package(new EmptyVersionStrategy())]);

        self::assertSame(['contextMenuRuntime' => 'js/nowo-ui-context-menu.js'], $this->runtime(true, $packages));
        self::assertSame([], $this->runtime(null, $packages), 'theme flag: the host loads it');
        self::assertSame([], $this->runtime(false, $packages), 'forced off');
        self::assertSame([], $this->runtime(true, null), 'no symfony/asset');
        self::assertSame([], $this->runtime(true, new Packages()), 'no UiKit package');
    }

    /**
     * @return array<string, string>
     */
    private function runtime(?bool $enabled, ?Packages $packages): array
    {
        $extension = new ChatDockExtension(
            $this->createStub(Security::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(CsrfTokenManagerInterface::class),
            $this->createStub(TranslatorInterface::class),
            new RequestStack(),
            new ChatAccess($this->createStub(ChatAccessPolicyInterface::class), $this->createStub(ChatChannelProviderInterface::class), $this->createStub(ChatDirectoryInterface::class)),
            $this->createStub(ChatChannelProviderInterface::class),
            $this->createStub(ChatUserSettingsRepository::class),
            new NullLinkPreviewProvider(),
            $this->createStub(FormFactoryInterface::class),
            packages: $packages,
            messageContextMenu: $enabled,
        );
        /** @var array<string, string> $config */
        $config = (new ReflectionMethod($extension, 'contextMenuConfig'))->invoke($extension);

        return $config;
    }
}
