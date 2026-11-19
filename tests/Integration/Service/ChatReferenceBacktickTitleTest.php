<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 1.37 (audits VA3-7 and VA4-1, affects 1.9.0–1.36.1): a `#reference` title with a backtick opened a code span when rendered and
 * showed the stored title to every reader. New tokens never hold one; stored bodies render safely, including messages
 * whose stored HTML already shows the title.
 */
final class ChatReferenceBacktickTitleTest extends DatabaseTestCase
{
    private const string BODY = 'see #[Q`secretz](task:7) and `tail`';

    /** The render 1.9.0–1.36.1 stored for {@see self::BODY}. */
    private const string LEAKED_HTML = '<p>see #[Q<code>secretz](task:7) and </code>tail`</p>';

    public function testNewTokensNeverHoldABacktick(): void
    {
        self::assertSame('#[Fix foo bar](task:7)', ChatReferences::token(new ChatReference('task', '7', 'Fix `foo` bar', '/tasks/7')));
        self::assertSame('see #[Q secretz](task:7) and `tail`', ChatReferences::safeTitles(self::BODY));
        self::assertTrue(ChatReferences::hasUnsafeTitles(self::BODY));
        // Titles without a backtick and text that is not a token are left as written.
        foreach (['plain text', 'a `#[Q`x` b', 'see #[Q secretz](task:7) and `tail`'] as $body) {
            self::assertFalse(ChatReferences::hasUnsafeTitles($body), $body);
        }
        // Audit VA4-1: a token-shaped title is cleaned anywhere, code included.
        self::assertSame('```#[Q s](task:7)```', ChatReferences::safeTitles('```#[Q`s](task:7)```'));
    }

    public function testRenderAndPlainTextNeverShowTheTitle(): void
    {
        $markdown = $this->get(ChatMarkdownRenderer::class);
        $html     = $markdown->render(self::BODY);
        self::assertStringNotContainsString('secretz', $html);
        self::assertStringContainsString('<code>tail</code>', $html);
        self::assertStringNotContainsString('secretz', $markdown->plainText(self::BODY, 500));
        $ana = $this->user('Ana');
        self::assertStringNotContainsString('secretz', $this->get(ChatReferences::class)->plainText($markdown, self::BODY, $ana, 500));
    }

    /**
     * The audits' fuzz cases (VA3-7, VA4-1): a lone backtick earlier on the line, an escaped one, quotes, lists, CRLF,
     * double and triple backticks. Each body is stored as 1.9–1.36 stored it (the typed title kept its backticks).
     *
     * @return iterable<string, array{string}>
     */
    public static function bodies(): iterable
    {
        $secret = 'Q`secretz';
        yield 'plain' => ["see #[{$secret}](task:7)"];
        yield 'lone backtick before' => ["Let`s check #[{$secret}](task:7)"];
        yield 'apostrophe backticks' => ['it`s #[Fix `foo` Qsecretz](task:7)'];
        yield 'backtick after title' => ["#[{$secret}](task:7) and `tail`"];
        yield 'double backtick' => ['#[Q``secretz](task:7) `x`'];
        yield 'triple backtick in title' => ['#[Q```secretz](task:7) x ```'];
        yield 'fence opened before' => ["`a ```\n#[Qsecretz](task:7)\n```"];
        yield 'CRLF' => ["#[{$secret}](task:7)\r\n`x`"];
        yield 'CR in title' => ["#[Q\r`secretz](task:7) `x`"];
        yield 'fullwidth grave' => ["#[Q\u{FF40}secretz](task:7) \u{FF40}x\u{FF40}"];
        yield 'escaped backtick' => ["\\`x #[{$secret}](task:7)"];
        yield 'quote' => ["> a`b #[{$secret}](task:7)"];
        yield 'list' => ["- a`b #[{$secret}](task:7)"];
        yield 'two tokens' => ["#[{$secret}](task:7) `x` #[Other](task:8)"];
    }

    #[DataProvider('bodies')]
    public function testNoStoredTitleReachesTheHtml(string $typed): void
    {
        $markdown = $this->get(ChatMarkdownRenderer::class);
        // What 1.9–1.36 stored: the token as typed, its title with brackets and white space cleaned, backticks kept.
        $stored = (string) preg_replace_callback('/(```.*?```|`[^`\n]+`)|#\[([^\[\]\n]{1,120})\]\(([a-z][a-z0-9_-]{0,31}):([A-Za-z0-9_-]{1,64})\)/su', static fn (array $m): string => $m[1] !== ''
            ? $m[1] : '#[' . trim((string) preg_replace('/[\[\]\s]+/u', ' ', $m[2])) . '](' . $m[3] . ':' . $m[4] . ')', $typed);
        $html = $markdown->render($stored);
        // A token the writer put inside a fenced block is the writer's own code, shown as written inside <pre><code>
        // (never a resolved reference: no slot); no stored title ever appears outside code.
        $outsideCode = (string) preg_replace('~<pre><code>.*?</code></pre>~s', '', $html);
        self::assertStringNotContainsStringIgnoringCase('secretz', strip_tags(str_replace(ChatReferences::SLOT, '', $outsideCode)), $html);
        // Slots and tokens stay in step (parse() and the renderer read the same safe titles).
        self::assertCount(substr_count($html, ChatReferences::SLOT), ChatReferences::parse($stored), $html);
        // Plain text (previews, search text) never holds the title either.
        self::assertStringNotContainsStringIgnoringCase('secretz', $markdown->plainText($stored, 500));
        self::assertStringNotContainsStringIgnoringCase('secretz', $this->get(ChatReferences::class)->plainText($markdown, $stored, $this->user('Reader'), 500));
        // And the stored render of such a row is replaced when shown.
        if (ChatReferences::hasUnsafeTitles($stored)) {
            self::assertNotSame($stored, ChatReferences::safeTitles($stored));
        }
    }

    public function testNewWritesResolveTokensAfterALoneBacktick(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        // Audit VA4-1: validate() no longer reads `Let`s … Dep`loy` as code: the token is resolved and its title replaced.
        self::assertSame('Let`s check #[Deploy the API](task:1)', $this->get(ChatReferences::class)->validate($dm, $ana, 'Let`s check #[Dep`loy](task:1)'));
        self::assertSame('> a`b #[Deploy the API](task:1)', $this->get(ChatReferences::class)->validate($dm, $ana, '> a`b #[Dep`loy](task:1)'));
    }

    public function testAStoredLeakingRenderIsReplaced(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $chat    = $this->get(ChatService::class);
        $message = $chat->send($ana, $chat->openDirect($ana, $luis), 'placeholder');
        $other   = $chat->send($ana, $message->getConversation(), 'all **fine**');
        $this->em->createQueryBuilder()->update(ChatMessage::class, 'm')->set('m.body', ':body')->set('m.bodyHtml', ':html')->where('m.id = :id')
            ->setParameter('body', self::BODY)->setParameter('html', self::LEAKED_HTML)->setParameter('id', $message->getId())->getQuery()->execute();
        $this->em->clear();
        $stored = $this->em->find(ChatMessage::class, $message->getId());
        self::assertInstanceOf(ChatMessage::class, $stored);
        $reader = $this->em->find($luis::class, $luis->getId());
        self::assertNotNull($reader);

        $shown = (string) $this->get(ChatPresenter::class)->message($stored, $reader)['html'];
        self::assertStringNotContainsString('secretz', $shown);
        self::assertStringNotContainsString('secretz', $this->get(ChatMarkdownRenderer::class)->htmlOf($stored));
        self::assertStringNotContainsString('secretz', ChatMessageView::of($stored)->bodyHtml);
        self::assertStringNotContainsString('secretz', ChatMessageView::of($stored, $this->get(ChatMarkdownRenderer::class))->bodyHtml);
        // A message without such a token keeps its stored render.
        $fine = $this->em->find(ChatMessage::class, $other->getId());
        self::assertInstanceOf(ChatMessage::class, $fine);
        self::assertSame($fine->getBodyHtml(), $this->get(ChatMarkdownRenderer::class)->htmlOf($fine));
    }
}
