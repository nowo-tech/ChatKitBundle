<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

/**
 * 1.29: the same byte-identity check on the deprecated 1.x page (`page_layout` set, page_legacy.html.twig).
 */
final class ChatAuthorBadgesDefaultLegacyPageTest extends ChatAuthorBadgesDefaultOutputTest
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['page_layout' => '@ChatKitTest/host_layout.html.twig']]];
    }

    protected function pageFixture(): string
    {
        return 'page_legacy.html';
    }
}
