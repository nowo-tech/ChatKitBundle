<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Service;

use Nowo\ChatKitBundle\Link\ChatLinkUrls;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * 1.19: `!link[Title](https://…)` renders as a link card (escaped, noopener noreferrer, new tab); a card with a link that
 * is not accepted stays text; code keeps the token literal; plain text shows the title.
 */
final class ChatMarkdownRendererLinkCardTest extends TestCase
{
    public function testRendersALinkCard(): void
    {
        $html = (new ChatMarkdownRenderer())->render('Stand-up: !link[Daily **sync**](https://teams.microsoft.com/l/meetup-join/abc) see you');

        self::assertStringStartsWith('<p>Stand-up: <a class="nowo-chat-linkcard" href="https://teams.microsoft.com/l/meetup-join/abc" target="_blank" rel="noopener noreferrer nofollow ugc" data-title="Daily **sync**" data-provider="teams">', $html);
        self::assertStringContainsString('<span class="nowo-chat-linkcard__title">Daily **sync**</span>', $html, 'the title is text, never Markdown');
        self::assertStringEndsWith('</a> see you</p>', $html);
        self::assertStringNotContainsString('<strong>', $html);
    }

    public function testRefusedLinksAndCodeStayText(): void
    {
        $renderer = new ChatMarkdownRenderer();

        self::assertSame('<p>!link[x](http://example.com/)</p>', $renderer->render('!link[x](http://example.com/)'));
        self::assertSame('<p><code>!link[x](https://example.com/)</code></p>', $renderer->render('`!link[x](https://example.com/)`'));
        self::assertStringContainsString('data-provider="intranet"', (new ChatMarkdownRenderer(new ChatLinkUrls(['intranet' => ['label' => 'Intranet', 'hosts' => ['intranet.example']]], true)))->render('!link[](http://intranet.example/)'));
    }

    public function testPlainTextShowsTheTitle(): void
    {
        self::assertSame('Join Daily now', (new ChatMarkdownRenderer())->plainText('Join !link[Daily](https://teams.microsoft.com/l/x) now'));
    }
}
