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
 * 1.33: `web_ui.message_context_menu: true` turns the message context menu on whatever UiKit's theme says, and gives
 * the dock the address of UiKit's runtime (UiKit's asset package), the same on every request of a long-running kernel.
 */
final class ChatMessageContextMenuForcedTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['web_ui' => ['message_context_menu' => true]]]];
    }

    public function testForcedOn(): void
    {
        $html = $this->renderDockTemplates();
        self::assertStringContainsString('data-nowo-ui-context-menu-source', $html);
        self::assertStringNotContainsString('data-nowo-ui-context-menu="off"', $html);

        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        foreach (['Ana', 'Luis'] as $name) {
            $client->loginUser($this->user($name));
            $crawler = $client->request(Request::METHOD_GET, '/page');
            /** @var array{contextMenuRuntime: string} $config */
            $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
            self::assertStringEndsWith('js/nowo-ui-context-menu.js', $config['contextMenuRuntime']);
        }
    }
}
