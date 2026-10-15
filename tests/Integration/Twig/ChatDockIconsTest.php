<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Twig;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Twig\ChatDockExtension;

/**
 * icon_set: ux_icon renders the application's UX Icons; icons the set does not have keep the built-in SVG.
 */
final class ChatDockIconsTest extends DatabaseTestCase
{
    public function testRendersTheIconsTheApplicationHas(): void
    {
        $icons = $this->get(ChatDockExtension::class)->icons();

        self::assertEqualsCanonicalizing(['close', 'send'], array_keys($icons));
        self::assertStringContainsString('<svg', (string) $icons['send']);
        self::assertStringContainsString('aria-hidden="true"', (string) $icons['send']);
        self::assertArrayNotHasKey('chat', $icons, 'tabler:message-circle is not in the test icon set');

        $html = $this->twig()->render('@NowoChatKitBundle/_templates.html.twig');
        self::assertStringContainsString('<template data-nowo-chat-icon="send"><svg', $html);
    }
}
