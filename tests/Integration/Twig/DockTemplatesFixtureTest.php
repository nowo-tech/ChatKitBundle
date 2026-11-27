<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Twig;

use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;

/**
 * The browser tests run against the real <template> markup: this test keeps the committed fixture
 * (src/Resources/assets/tests/fixtures/templates.html) in step with `_templates.html.twig`.
 * Regenerate with UPDATE_FIXTURES=1.
 */
final class DockTemplatesFixtureTest extends DatabaseTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../src/Resources/assets/tests/fixtures/templates.html';

    /**
     * 1.35: every message menu item is offered, so the browser tests see each of them. 1.40: the search modal too.
     */
    protected function chatKitConfig(): array
    {
        // 1.40: search on (without the dock header link) so the window has the search button and the modal template.
        // 2.6: a placed host slot (`slot:report`) and the model item of the others.
        return ['profiles' => ['default' => ['message_menu' => ['save' => ['enabled' => true], 'message_info' => ['enabled' => true], 'forward' => ['enabled' => true], 'threads' => ['enabled' => true], 'order' => [...Configuration::MESSAGE_MENU_ITEMS, 'slot:report']], 'search' => ['enabled' => true, 'dock_entry' => false]]]];
    }

    /** 2.6 (spec 259): the test slot providers, so the message menu has its slot items. */
    protected function cardProviders(): bool
    {
        return true;
    }

    public function testFixtureMatchesTheTemplates(): void
    {
        $html = $this->renderDockTemplates() . "\n";

        if (getenv('UPDATE_FIXTURES') === '1') {
            file_put_contents(self::FIXTURE, $html);
        }

        self::assertFileExists(self::FIXTURE);
        self::assertSame($html, file_get_contents(self::FIXTURE), 'Run UPDATE_FIXTURES=1 vendor/bin/phpunit --filter DockTemplatesFixtureTest');
        foreach (['dock', 'row', 'list-state', 'picker-section', 'picker-row', 'picker-state', 'overflow-item', 'window', 'message', 'edit-form', 'delete-confirm', 'pending', 'buzz-line', 'day', 'unseen', 'seen', 'thread-state', 'thread-empty'] as $name) {
            self::assertStringContainsString('data-nowo-chat-template="' . $name . '"', $html);
        }
        // Since 2.10.0 the search modal is fetched when it opens (GET /chat/search/dialog), not printed in the dock.
        self::assertStringNotContainsString('data-nowo-chat-template="search-dialog"', $html);
    }
}
