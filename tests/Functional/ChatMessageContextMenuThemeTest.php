<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.33: `web_ui.message_context_menu` null (default) follows UiKit's `theme.context_menus`: with the flag on, each
 * message's kebab is the source of its context menu; the host's UiKit `scripts` partial loads the runtime, so the dock
 * does not ask for it.
 */
final class ChatMessageContextMenuThemeTest extends DatabaseTestCase
{
    protected function uiKitContextMenus(): bool
    {
        return true;
    }

    public function testTheKebabFollowsTheThemeFlag(): void
    {
        $html = $this->renderDockTemplates();
        self::assertMatchesRegularExpression('~<div class="nowo-ui-kebab" data-nowo-ui-kebab data-nowo-ui-sheet data-nowo-ui-context-menu-source data-nowo-ui-context-menu-label="[^"]+">~', $html);
        self::assertStringNotContainsString('data-nowo-ui-context-menu="off"', $html);

        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->loginUser($this->user('Ana'));
        $crawler = $client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('contextMenuRuntime', $config);
    }
}
