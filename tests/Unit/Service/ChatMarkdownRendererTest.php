<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Service;

use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatMarkdownRendererTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        yield 'empty' => ['   ', ''];
        yield 'paragraph with line break' => ["hola\nmundo", '<p>hola<br>mundo</p>'];
        yield 'two paragraphs' => ["uno\n\ndos", '<p>uno</p><p>dos</p>'];
        yield 'bold italic strike' => ['**b** *i* _u_ ~~s~~', '<p><strong>b</strong> <em>i</em> <em>u</em> <del>s</del></p>'];
        yield 'snake_case is not italic' => ['use foo_bar_baz', '<p>use foo_bar_baz</p>'];
        yield 'inline code keeps markers' => ['run `a **b** <c>`', '<p>run <code>a **b** &lt;c&gt;</code></p>'];
        yield 'fenced block' => ["```php\n<?php echo 1;\n```", '<pre><code>&lt;?php echo 1;</code></pre>'];
        yield 'text then block' => ["mira:\n```\nx\n```\nfin", '<p>mira:</p><pre><code>x</code></pre><p>fin</p>'];
        yield 'bullet list' => ["- uno\n* dos", '<ul><li>uno</li><li>dos</li></ul>'];
        yield 'numbered list then text' => ["1. a\n2) b\nfin", '<ol><li>a</li><li>b</li></ol><p>fin</p>'];
        yield 'list kind switch' => ["- a\n1. b", '<ul><li>a</li></ul><ol><li>b</li></ol>'];
        yield 'raw html escaped' => ['<script>alert(1)</script><img src=x onerror=y>', '<p>&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=y&gt;</p>'];
        yield 'autolink trims punctuation' => ['ver https://beacon.test/p/1?a=1&b=2.', '<p>ver <a href="https://beacon.test/p/1?a=1&amp;b=2" rel="noopener nofollow ugc" target="_blank">https://beacon.test/p/1?a=1&amp;b=2</a>.</p>'];
        yield 'link with underscores not italic' => ['https://x.test/a_b_c', '<p><a href="https://x.test/a_b_c" rel="noopener nofollow ugc" target="_blank">https://x.test/a_b_c</a></p>'];
        yield 'javascript scheme stays text' => ['javascript:alert(1)', '<p>javascript:alert(1)</p>'];
        yield 'injected placeholders are stripped' => ["a\u{E000}B0\u{E000}b", '<p>aB0b</p>'];
        yield 'bidi overrides are stripped' => ["evil\u{202E}txt.exe", '<p>eviltxt.exe</p>'];
        yield 'block marker inline with text' => ['see ```x``` now', '<p>see <pre><code>x</code></pre> now</p>'];
        yield 'quotes in link escaped' => ['https://x.test/"onmouseover', '<p><a href="https://x.test/" rel="noopener nofollow ugc" target="_blank">https://x.test/</a>&quot;onmouseover</p>'];
    }

    #[DataProvider('cases')]
    public function testRender(string $markdown, string $html): void
    {
        self::assertSame($html, new ChatMarkdownRenderer()->render($markdown));
    }

    public function testPlainText(): void
    {
        self::assertSame("a\tb\nc", ChatMarkdownRenderer::clean("a\u{0000}\tb\u{0007}\nc\u{007F}\u{202E}"), 'C0 / DEL / bidi removed, tab and newline kept');
        $renderer = new ChatMarkdownRenderer();

        self::assertSame('hola mundo code', $renderer->plainText("**hola**\n\n- _mundo_\n```\ncode\n```"));
        self::assertSame('abcd…', $renderer->plainText('abcdefgh', 5));
        self::assertSame('It supports: dms channels', $renderer->plainText("It supports:\n- dms\n  1. channels"), 'list markers never eat the line break');
    }
}
