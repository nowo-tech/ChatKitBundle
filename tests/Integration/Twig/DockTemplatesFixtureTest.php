<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Twig;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;

/**
 * The browser tests run against the real <template> markup: this test keeps the committed fixture
 * (src/Resources/assets/tests/fixtures/templates.html) in step with `_templates.html.twig`.
 * Regenerate with UPDATE_FIXTURES=1.
 */
final class DockTemplatesFixtureTest extends DatabaseTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../src/Resources/assets/tests/fixtures/templates.html';

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
    }
}
