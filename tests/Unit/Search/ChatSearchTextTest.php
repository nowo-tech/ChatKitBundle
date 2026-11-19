<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Search;

use Nowo\ChatKitBundle\Search\ChatMessageSearchHit;
use Nowo\ChatKitBundle\Search\ChatMessageSearchPage;
use Nowo\ChatKitBundle\Search\ChatMessageSearchQuery;
use Nowo\ChatKitBundle\Search\ChatSearchQueryException;
use Nowo\ChatKitBundle\Search\ChatSearchText;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function str_repeat;

/**
 * 1.37: the pure text rules of message search and its value objects.
 */
final class ChatSearchTextTest extends TestCase
{
    public function testWords(): void
    {
        self::assertSame(['invoice', 'march'], ChatSearchText::words("  Invoice\tMARCH invoice "));
        self::assertSame([], ChatSearchText::words('   '));
        self::assertCount(ChatSearchText::MAX_WORDS, ChatSearchText::words('a b c d e f g h i j k'));
    }

    public function testFoldAndContainsAll(): void
    {
        self::assertSame('cafe', ChatSearchText::fold('CAFÉ'));
        self::assertTrue(ChatSearchText::containsAll(['cafe', 'mar'], 'Un café en MARZO'));
        self::assertTrue(ChatSearchText::containsAll(['café'], 'cafe'));
        self::assertFalse(ChatSearchText::containsAll(['cafe', 'abril'], 'Un café en marzo'));
    }

    public function testSnippet(): void
    {
        self::assertSame('short text', ChatSearchText::snippet('short text', ['text']));
        $long    = str_repeat('a', 100) . ' needle ' . str_repeat('b', 300);
        $snippet = ChatSearchText::snippet($long, ['needle']);
        self::assertStringStartsWith('…', $snippet);
        self::assertStringEndsWith('…', $snippet);
        self::assertStringContainsString('needle', $snippet);
        // No word found: from the start.
        self::assertStringStartsWith('aaa', ChatSearchText::snippet($long, ['missing']));
        // The earliest word wins.
        self::assertStringContainsString('alpha', ChatSearchText::snippet(str_repeat('z', 100) . ' alpha ' . str_repeat('z', 300) . ' beta', ['beta', 'alpha']));
    }

    public function testSegments(): void
    {
        self::assertSame([['text' => 'plain <b>', 'mark' => false]], ChatSearchText::segments('plain <b>', []));
        self::assertSame([
            ['text' => 'The ', 'mark' => false],
            ['text' => 'Invoice', 'mark' => true],
            ['text' => ' <script> for ', 'mark' => false],
            ['text' => 'march', 'mark' => true],
        ], ChatSearchText::segments('The Invoice <script> for march', ['invoice', 'march']));
        // Regex characters in a word are literal.
        self::assertSame([['text' => 'a.b', 'mark' => true], ['text' => ' axb', 'mark' => false]], ChatSearchText::segments('a.b axb', ['a.b']));
    }

    public function testQueryBounds(): void
    {
        $query = ChatMessageSearchQuery::create(" hello\x00world ", 2, 20, ' ', '', null, null, true, '');
        self::assertSame('hello world', $query->text);
        self::assertSame(['hello', 'world'], $query->words);
        self::assertNull($query->conversation);
        self::assertNull($query->author);
        self::assertNull($query->cursor);
        self::assertTrue($query->hasLink);
        $trimmed = ChatMessageSearchQuery::create('ab', 2, 20, ' x ', ' me ', null, null, false, ' c ');
        self::assertSame(['x', 'me', 'c'], [$trimmed->conversation, $trimmed->author, $trimmed->cursor]);

        try {
            ChatMessageSearchQuery::create(str_repeat('a', 21), 2, 20);
            self::fail('too long');
        } catch (ChatSearchQueryException $exception) {
            self::assertSame(ChatSearchQueryException::TOO_LONG, $exception->errorCode);
            self::assertSame('The search text is too long.', $exception->getMessage());
        }

        try {
            ChatMessageSearchQuery::create(' a ', 2, 20);
            self::fail('too short');
        } catch (ChatSearchQueryException $exception) {
            self::assertSame(ChatSearchQueryException::TOO_SHORT, $exception->errorCode);
            self::assertSame('The search text is too short.', $exception->getMessage());
        }
    }

    public function testEmptyPage(): void
    {
        $page = ChatMessageSearchPage::empty();
        self::assertSame([], $page->hits);
        self::assertNull($page->next);
        self::assertTrue((new ReflectionClass(ChatMessageSearchHit::class))->isReadOnly());
    }
}
