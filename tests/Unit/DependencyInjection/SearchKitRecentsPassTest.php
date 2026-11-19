<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\DependencyInjection\Compiler\SearchKitRecentsPass;
use Nowo\ChatKitBundle\SearchKit\ChatMessageSearchProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.37 (audit VA-3): the SearchKit provider stays only when SearchKit keeps no message text in the browser.
 */
final class SearchKitRecentsPassTest extends TestCase
{
    public function testTheProviderNeedsRevalidatedOrDisabledRecents(): void
    {
        // [revalidate parameter, settings parameter, kept?]
        $cases = [
            'no SearchKit'              => [null, null, false],
            'default (not revalidated)' => [false, ['recent' => ['enabled' => true, 'revalidate' => false]], false],
            'revalidated'               => [true, ['recent' => ['enabled' => true, 'revalidate' => true]], true],
            'recents off'               => [false, ['recent' => ['enabled' => false, 'revalidate' => false]], true],
            'no settings'               => [false, null, false],
            'odd settings'              => [false, ['recent' => 'x'], false],
        ];
        foreach ($cases as $name => [$revalidate, $settings, $kept]) {
            $container = new ContainerBuilder();
            $container->register(ChatMessageSearchProvider::class, ChatMessageSearchProvider::class);
            if ($revalidate !== null) {
                $container->setParameter(SearchKitRecentsPass::REVALIDATE_PARAMETER, $revalidate);
            }
            if ($settings !== null) {
                $container->setParameter(SearchKitRecentsPass::SETTINGS_PARAMETER, $settings);
            }
            (new SearchKitRecentsPass())->process($container);
            self::assertSame($kept, $container->hasDefinition(ChatMessageSearchProvider::class), $name);
        }
        // Nothing to do without the provider.
        $container = new ContainerBuilder();
        (new SearchKitRecentsPass())->process($container);
        self::assertFalse($container->hasDefinition(ChatMessageSearchProvider::class));
    }
}
