<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;

/**
 * 1.33: `web_ui.message_context_menu: false` keeps the browser's menu on messages even when UiKit's theme turns context
 * menus on for every kebab: the kebab is not a source and the message is marked `data-nowo-ui-context-menu="off"` (so a
 * scope around the dock cannot take over). The kebab itself stays.
 */
final class ChatMessageContextMenuOffTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['web_ui' => ['message_context_menu' => false]]]];
    }

    protected function uiKitContextMenus(): bool
    {
        return true;
    }

    public function testForcedOff(): void
    {
        $html = $this->renderDockTemplates();
        self::assertStringNotContainsString('data-nowo-ui-context-menu-source', $html);
        self::assertStringContainsString('<article class="nowo-chat-msg" tabindex="0" data-nowo-ui-context-menu-scope data-nowo-ui-context-menu="off">', $html);
        self::assertStringContainsString('data-part="menu-copy-link"', $html);
    }
}
