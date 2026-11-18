<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Support;

use Nowo\ChatKitBundle\Support\ChatEmojiCatalog;
use PHPUnit\Framework\TestCase;

use function array_merge;
use function count;
use function file_get_contents;
use function preg_match;
use function preg_match_all;

/**
 * 1.35: the server's picker catalog is the dock picker's set (emoji.ts), so "More…" never offers an emoji the server
 * would refuse, nor accepts one the picker does not offer.
 */
final class ChatEmojiCatalogTest extends TestCase
{
    public function testTheCatalogIsThePickerSet(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Resources/assets/src/emoji.ts');
        self::assertSame(1, preg_match('/const CATEGORIES[^=]*=\s*\[(.*?)\n\];/s', $source, $block));
        preg_match_all("/\\[\\s*'emoji_\\w+',\\s*\\[(.*?)\\]\\s*\\]/s", $block[1], $categories);
        $picker = [];
        foreach ($categories[1] as $list) {
            preg_match_all("/'([^']+)'/u", $list, $emojis);
            $picker = array_merge($picker, $emojis[1]);
        }
        self::assertSame($picker, ChatEmojiCatalog::ALL);
    }

    public function testAccepted(): void
    {
        self::assertTrue(ChatEmojiCatalog::contains('🚀'));
        self::assertFalse(ChatEmojiCatalog::contains("\u{200B}"));
        self::assertSame(['🦄'], ChatEmojiCatalog::accepted(['🦄'], false));
        $accepted = ChatEmojiCatalog::accepted(['🦄', '👍'], true);
        self::assertSame(['🦄', '👍'], [$accepted[0], $accepted[1]]);
        self::assertCount(1 + count(ChatEmojiCatalog::ALL), $accepted, 'no duplicate');
    }
}
