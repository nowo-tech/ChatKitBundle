<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * 1.26: `web_ui.dock.dialog_runtime: true` gives the dock the address of UiKit's dialog runtime (UiKit's asset
 * package), the same on every request of a long-running kernel.
 */
final class ChatSidebarDialogRuntimeTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['web_ui' => ['dock' => ['sidebar_sections' => true, 'dialog_runtime' => true]]]]];
    }

    public function testTheDockGetsTheRuntimeAddress(): void
    {
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        foreach (['Ana', 'Luis'] as $name) {
            $client->loginUser($this->user($name));
            $crawler = $client->request(Request::METHOD_GET, '/page');
            /** @var array{sidebar: array<string, mixed>} $config */
            $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsString($config['sidebar']['dialogRuntime']);
            self::assertStringEndsWith('js/nowo-ui-dialog.js', $config['sidebar']['dialogRuntime']);
        }
    }
}
