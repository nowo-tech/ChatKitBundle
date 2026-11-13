<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Model;

use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatChannelKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * House rule LQ: a channel key is compared byte for byte, so look-alike forms (padding, control and format characters)
 * are refused instead of being normalised.
 */
final class ChatChannelKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validKeys(): iterable
    {
        yield 'project uuid' => ['project:0190a7c2-5b1e-7c3d-9f00-1a2b3c4d5e6f'];
        yield 'mixed case' => ['project:Acme'];
        yield 'accent' => ['project:Ácme'];
        yield 'inner space' => ['team:sales east'];
        yield 'emoji' => ['room:🚀'];
        yield '120 bytes' => [str_repeat('a', 120)];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield '121 bytes' => [str_repeat('a', 121)];
        yield 'trailing space' => ['project:acme '];
        yield 'leading space' => [' project:acme'];
        yield 'trailing tab' => ["project:acme\t"];
        yield 'trailing newline' => ["project:acme\n"];
        yield 'trailing no-break space' => ["project:acme\u{00A0}"];
        yield 'leading ideographic space' => ["\u{3000}project:acme"];
        yield 'nul' => ["project:\0acme"];
        yield 'escape' => ["project:\x1Bacme"];
        yield 'zero-width space' => ["project:ac\u{200B}me"];
        yield 'zero-width joiner' => ["project:ac\u{200D}me"];
        yield 'bidi override' => ["project:\u{202E}emca"];
        yield 'byte order mark' => ["\u{FEFF}project:acme"];
        yield 'line separator' => ["project:ac\u{2028}me"];
        yield 'paragraph separator' => ["project:ac\u{2029}me"];
        yield 'private use' => ["project:\u{E000}"];
        yield 'invalid utf-8' => ["project:\xC3"];
    }

    #[DataProvider('validKeys')]
    public function testAcceptsExactKeys(string $key): void
    {
        self::assertTrue(ChatChannelKey::isValid($key));
        ChatChannelKey::assertValid($key);
        self::assertSame($key, ChatConversation::channel($key)->getChannelKey());
    }

    #[DataProvider('invalidKeys')]
    public function testRefusesLookAlikeKeys(string $key): void
    {
        self::assertFalse(ChatChannelKey::isValid($key));
        $this->expectException(LogicException::class);
        ChatChannelKey::assertValid($key);
    }

    #[DataProvider('invalidKeys')]
    public function testAChannelConversationNeedsAValidKey(string $key): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no whitespace at either end and no control or format characters');
        ChatConversation::channel($key);
    }
}
