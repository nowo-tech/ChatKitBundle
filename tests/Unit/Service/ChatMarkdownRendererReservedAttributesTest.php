<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Service;

use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.33 (UiKit 2.54 SECURITY): a message body never carries UiKit's context-menu attributes (`data-nowo-ui-context-menu`,
 * `-source`, `-scope`, `-label`, `-open` …) nor `data-nowo-ui-card`, or its content could open another element's kebab
 * items on right-click. The render escapes them (they cannot come from the text); stripAttributes() removes them from
 * stored or application HTML; other attributes and the text between tags stay as they are.
 */
final class ChatMarkdownRendererReservedAttributesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function htmlProvider(): iterable
    {
        yield 'explicit host with an id' => ['<p data-nowo-ui-context-menu="card-42-menu">hi</p>', '<p>hi</p>'];
        yield 'explicit host, empty' => ['<p data-nowo-ui-context-menu>hi</p>', '<p>hi</p>'];
        yield 'source' => ['<span class="x" data-nowo-ui-context-menu-source>a</span>', '<span class="x">a</span>'];
        yield 'scope, single quotes' => ["<div data-nowo-ui-context-menu-scope='1' title=\"t\">a</div>", '<div title="t">a</div>'];
        yield 'label, unquoted' => ['<a href="/x" data-nowo-ui-context-menu-label=Actions>a</a>', '<a href="/x">a</a>'];
        yield 'open, upper case' => ['<p DATA-NOWO-UI-CONTEXT-MENU-OPEN="">a</p>', '<p>a</p>'];
        yield 'card' => ['<blockquote data-nowo-ui-card><p>q</p></blockquote>', '<blockquote><p>q</p></blockquote>'];
        yield 'several on one tag' => ['<p data-nowo-ui-card data-mention="u1" data-nowo-ui-context-menu="m">a</p>', '<p data-mention="u1">a</p>'];
        yield 'self-closing' => ['<br data-nowo-ui-context-menu="x"/>', '<br/>'];
        yield 'a look-alike stays' => ['<p data-nowo-ui-cards="1" data-nowo-ui-menu>a</p>', '<p data-nowo-ui-cards="1" data-nowo-ui-menu>a</p>'];
        yield 'text between tags stays' => ['<p>data-nowo-ui-context-menu="x"</p>', '<p>data-nowo-ui-context-menu="x"</p>'];
        // Audit SQ-L1: the separators the HTML tokenizer accepts.
        yield 'slash separator' => ['<p/data-nowo-ui-context-menu="admin-menu">a</p>', '<p/>a</p>'];
        yield 'right after a quoted value' => ['<p class="a"data-nowo-ui-card>b</p>', '<p class="a">b</p>'];
        yield 'after a backtick value' => ['<p title=`x` data-nowo-ui-card>c</p>', '<p title=`x`>c</p>'];
        yield 'upper case, two in a row' => ['<p DATA-NOWO-UI-CONTEXT-MENU-SOURCE data-nowo-ui-context-menu-scope>d</p>', '<p>d</p>'];
        yield 'a ">" inside a quoted value' => ['<p data-x="a>b" data-nowo-ui-card>e</p>', '<p data-x="a>b">e</p>'];
        yield 'spaces around "="' => ['<p data-nowo-ui-context-menu = "m" class=c>f</p>', '<p class=c>f</p>'];
        yield 'newline and tab separators' => ["<p\ndata-nowo-ui-card\tclass=\"c\">g</p>", "<p\tclass=\"c\">g</p>"];
        yield 'a tag left open' => ['<p>h</p><p data-nowo-ui-card', '<p>h</p><p'];
        yield 'nothing to strip' => ['<p><strong>b</strong></p>', '<p><strong>b</strong></p>'];
    }

    #[DataProvider('htmlProvider')]
    public function testStripAttributes(string $html, string $expected): void
    {
        self::assertSame($expected, ChatMarkdownRenderer::stripAttributes($html));
    }

    public function testTheRenderNeverEmitsThem(): void
    {
        $renderer = new ChatMarkdownRenderer();
        foreach ([
            '<p data-nowo-ui-context-menu="kebab-of-admin">click me</p>',
            '<div data-nowo-ui-card data-nowo-ui-context-menu-scope>x</div>',
            '[data-nowo-ui-context-menu](https://example.com/?data-nowo-ui-card)',
            '> <span data-nowo-ui-context-menu-source>q</span>',
        ] as $markdown) {
            $html = $renderer->render($markdown);
            self::assertDoesNotMatchRegularExpression('~<[^>]*\sdata-nowo-ui-(?:context-menu|card)~i', $html, $markdown);
        }
    }
}
