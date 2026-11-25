<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\ClientApi;

use Nowo\ChatKitBundle\ClientApi\ChatMessageTokens;
use Nowo\ChatKitBundle\Link\ChatLinkUrls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 2.4 (Q-BD-16): message bodies as tokens, by the renderer's own rule set, with #references resolved for the reader
 * (R2: the neutral placeholder carries no type, id or title) and only http(s) / mailto links.
 */
final class ChatMessageTokensTest extends DatabaseTestCase
{
    private ChatMessageTokens $tokens;

    private TestUser $ana;

    private TestUser $luis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = $this->get(ChatMessageTokens::class);
        $this->ana    = $this->user('Ana');
        $this->luis   = $this->user('Luis');
    }

    public function testAnEmptyBodyHasNoBlock(): void
    {
        self::assertSame(['format' => 'tokens/1', 'blocks' => []], $this->tokens->of("  \n ", $this->ana));
    }

    public function testParagraphsBreaksAndEmphasis(): void
    {
        $blocks = $this->blocks("**bold _both_** and *it* ~~gone~~\nnext line\n\nsecond", $this->ana);

        self::assertSame([
            ['type' => 'paragraph', 'tokens' => [
                ['type' => 'text', 'text' => 'bold ', 'marks' => ['bold']],
                ['type' => 'text', 'text' => 'both', 'marks' => ['bold', 'italic']],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'text', 'text' => 'it', 'marks' => ['italic']],
                ['type' => 'text', 'text' => ' '],
                ['type' => 'text', 'text' => 'gone', 'marks' => ['strike']],
                ['type' => 'break'],
                ['type' => 'text', 'text' => 'next line'],
            ]],
            ['type' => 'paragraph', 'tokens' => [['type' => 'text', 'text' => 'second']]],
        ], $blocks);
        // The same mark twice is kept once; HTML stays text.
        self::assertSame([['type' => 'text', 'text' => '<b>x</b>', 'marks' => ['bold']]], $this->blocks('**<b>x</b>**', $this->ana)[0]['tokens']);
        self::assertSame([['type' => 'text', 'text' => 'x', 'marks' => ['italic']]], $this->blocks('_*x*_', $this->ana)[0]['tokens']);
    }

    public function testListsQuotesAndCode(): void
    {
        $blocks = $this->blocks("- one\n* two\n1. first\n2) second\n> quoted `#[Secret plan](task:2)`\n> again\n```php\necho 1; #[Secret plan](task:2)\n```\nafter", $this->ana);

        self::assertSame(['type' => 'list', 'ordered' => false, 'items' => [[['type' => 'text', 'text' => 'one']], [['type' => 'text', 'text' => 'two']]]], $blocks[0]);
        self::assertSame(['type' => 'list', 'ordered' => true, 'items' => [[['type' => 'text', 'text' => 'first']], [['type' => 'text', 'text' => 'second']]]], $blocks[1]);
        self::assertSame('quote', $blocks[2]['type']);
        self::assertSame('code', $blocks[2]['tokens'][1]['type']);
        // Code never shows a reference title.
        self::assertStringNotContainsString('Secret', json_encode($blocks, JSON_THROW_ON_ERROR));
        self::assertSame(['type' => 'break'], $blocks[2]['tokens'][2]);
        self::assertSame('code', $blocks[3]['type']);
        self::assertStringStartsWith('echo 1;', $blocks[3]['text']);
        self::assertSame(['type' => 'paragraph', 'tokens' => [['type' => 'text', 'text' => 'after']]], $blocks[4]);

        // A fence with text on the same line is inline code.
        $inline = $this->blocks("before ```x``` after\n- item ```y```", $this->ana);
        self::assertSame([['type' => 'text', 'text' => 'before '], ['type' => 'code', 'text' => 'x'], ['type' => 'text', 'text' => ' after']], $inline[0]['tokens']);
        self::assertSame([['type' => 'text', 'text' => 'item '], ['type' => 'code', 'text' => 'y']], $inline[1]['items'][0]);
        $marked = $this->blocks('**a ```b``` c**', $this->ana)[0]['tokens'];
        self::assertSame(['type' => 'code', 'text' => 'b'], $marked[1]);
        self::assertSame(['bold'], $marked[2]['marks']);
    }

    public function testMentionsAndReferencesForTheReader(): void
    {
        $body = '@[Luis](' . $this->luis->getChatPublicId() . ') #[Deploy the API](task:1) #[Secret plan](task:2) #[Odd](task:4) #[Gone](nope:1)';

        $forAna = $this->blocks($body, $this->ana)[0]['tokens'];
        self::assertSame(['type' => 'mention', 'userUuid' => $this->luis->getChatPublicId(), 'name' => 'Luis'], $forAna[0]);
        self::assertSame([
            'type'      => 'reference',
            'visible'   => true,
            'refType'   => 'task',
            'id'        => '1',
            'typeLabel' => 'Task',
            'icon'      => 'task',
            'title'     => 'Deploy the API',
            'url'       => '/tasks/1',
            'subtitle'  => 'Acme · Doing',
            'badge'     => 'High',
            'tone'      => 'success',
        ], $forAna[2]);
        self::assertSame('Secret plan', $forAna[4]['title']);
        // An unsafe URL is not linked; an unknown tone is neutral.
        self::assertNull($forAna[6]['url']);
        self::assertSame('neutral', $forAna[6]['tone']);
        // A type no provider has is not visible.
        self::assertSame(['type' => 'reference', 'visible' => false, 'text' => 'Content not visible'], $forAna[8]);

        $forLuis = $this->blocks($body, $this->luis)[0]['tokens'];
        self::assertSame(['type' => 'reference', 'visible' => false, 'text' => 'Content not visible'], $forLuis[4]);
        self::assertStringNotContainsString('Secret', json_encode($forLuis, JSON_THROW_ON_ERROR));

        // Without the reference service, nothing is visible.
        $bare = new ChatMessageTokens(new ChatLinkUrls());
        self::assertSame([['type' => 'reference', 'visible' => false, 'text' => '…']], $bare->of('#[Deploy the API](task:1)', $this->ana)['blocks'][0]['tokens']);
    }

    public function testLinks(): void
    {
        $tokens = $this->blocks('[Docs](https://example.test/docs), [mail](mailto:ana@example.test) www.example.test/x. ana@example.test mailto:luis@example.test https://example.test/a)', $this->ana)[0]['tokens'];

        self::assertSame(['type' => 'link', 'url' => 'https://example.test/docs', 'text' => 'Docs', 'card' => false], $tokens[0]);
        self::assertSame(['type' => 'link', 'url' => 'mailto:ana@example.test', 'text' => 'mail', 'card' => false], $tokens[2]);
        self::assertSame(['type' => 'link', 'url' => 'https://www.example.test/x', 'text' => 'www.example.test/x', 'card' => false], $tokens[4]);
        self::assertSame(['type' => 'text', 'text' => '. '], $tokens[5]);
        self::assertSame(['type' => 'link', 'url' => 'mailto:ana@example.test', 'text' => 'ana@example.test', 'card' => false], $tokens[6]);
        self::assertSame(['type' => 'link', 'url' => 'mailto:luis@example.test', 'text' => 'luis@example.test', 'card' => false], $tokens[8]);
        self::assertSame(['type' => 'link', 'url' => 'https://example.test/a', 'text' => 'https://example.test/a', 'card' => false], $tokens[10]);
        self::assertSame(['type' => 'text', 'text' => ')'], $tokens[11]);
        // No other scheme is a link.
        self::assertSame([['type' => 'text', 'text' => '[x](javascript:alert(1))']], $this->blocks('[x](javascript:alert(1))', $this->ana)[0]['tokens']);

        // A link label shows a mention as text, code as written, a reference as the reader may see it.
        $label = $this->blocks('[@[Luis](' . $this->luis->getChatPublicId() . ') `c` #[Secret plan](task:2)](https://example.test/l)', $this->luis)[0]['tokens'][0];
        self::assertSame('@Luis c Content not visible', $label['text']);
        $visible = $this->blocks('[#[Deploy the API](task:1)](https://example.test/l)', $this->ana)[0]['tokens'][0];
        self::assertSame('Deploy the API', $visible['text']);
    }

    public function testLinkCards(): void
    {
        $tokens = $this->blocks('!link[Daily](https://meet.google.com/abc-defg-hij) !link[](https://example.test/x) !link[Old](http://example.test/y) !link[#[Deploy the API](task:1)](https://example.test/z) !link[`code` @[Luis](' . $this->luis->getChatPublicId() . ')](https://example.test/c)', $this->ana)[0]['tokens'];

        self::assertSame(['type' => 'link', 'url' => 'https://meet.google.com/abc-defg-hij', 'text' => 'Daily', 'card' => true, 'provider' => ['key' => 'meet', 'label' => 'Google Meet']], $tokens[0]);
        // No title: the provider label, else the host.
        self::assertSame(['type' => 'link', 'url' => 'https://example.test/x', 'text' => 'example.test', 'card' => true], $tokens[2]);
        // A card whose link is not accepted (http) stays text; a reference in a card title makes no card (its `[…](url)`
        // part is a Markdown link, as the renderer reads it).
        self::assertSame(['type' => 'text', 'text' => ' !link[Old](http://example.test/y) !link'], $tokens[3]);
        self::assertSame(['type' => 'link', 'url' => 'https://example.test/z', 'text' => 'Deploy the API', 'card' => false], $tokens[4]);
        self::assertSame('code @Luis', $tokens[6]['text']);
        self::assertTrue($tokens[6]['card']);

        // ChatMessageTokens::previewUrls(): the http(s) links that are no cards, once, list items included.
        $body = $this->tokens->of("!link[A](https://example.test/card) https://example.test/one mailto:a@example.test\n- https://example.test/two https://example.test/one", $this->ana);
        self::assertSame(['https://example.test/one', 'https://example.test/two'], ChatMessageTokens::previewUrls($body));
    }

    public function testPlainText(): void
    {
        self::assertSame(['format' => 'tokens/1', 'blocks' => []], ChatMessageTokens::plain(''));
        self::assertSame(
            ['format' => 'tokens/1', 'blocks' => [['type' => 'paragraph', 'tokens' => [['type' => 'text', 'text' => 'one'], ['type' => 'break'], ['type' => 'break'], ['type' => 'text', 'text' => '**two**']]]]],
            ChatMessageTokens::plain("one\r\n\n**two**"),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blocks(string $body, TestUser $viewer): array
    {
        return $this->tokens->of($body, $viewer)['blocks'];
    }
}
