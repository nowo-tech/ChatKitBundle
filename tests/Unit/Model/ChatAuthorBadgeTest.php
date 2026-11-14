<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Model;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Enum\ChatAuthorBadgeVariant;
use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatAuthorRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 1.29: the value objects of the author badge port — plain text only, an allow-listed variant, exact references.
 */
final class ChatAuthorBadgeTest extends TestCase
{
    public function testABadgeIsPlainTextWithAnAllowListedVariant(): void
    {
        $badge = new ChatAuthorBadge('  Agent ', 'agent', ' API token "deploy" ');
        self::assertSame('Agent', $badge->label);
        self::assertSame(ChatAuthorBadgeVariant::Agent, $badge->variant);
        self::assertSame('API token "deploy"', $badge->title);
        self::assertSame(['label' => 'Agent', 'variant' => 'agent', 'title' => 'API token "deploy"'], $badge->toArray());

        $default = new ChatAuthorBadge('Guest');
        self::assertSame(ChatAuthorBadgeVariant::Neutral, $default->variant);
        self::assertNull($default->title);
        self::assertNull((new ChatAuthorBadge('Guest', ChatAuthorBadgeVariant::Info, '  '))->title, 'an empty title is none');
        self::assertSame(str_repeat('é', 40), (new ChatAuthorBadge(str_repeat('é', 40)))->label, 'characters, not bytes');
        // Markup is text: the templates escape it.
        self::assertSame('<script>alert(1)</script>', (new ChatAuthorBadge('<script>alert(1)</script>'))->label);
        self::assertSame(['neutral', 'info', 'success', 'warning', 'agent'], array_map(static fn (ChatAuthorBadgeVariant $variant): string => $variant->value, ChatAuthorBadgeVariant::cases()));
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function invalidBadges(): iterable
    {
        yield 'empty label' => ['', 'neutral', null];
        yield 'blank label' => ['   ', 'neutral', null];
        yield 'label too long' => [str_repeat('a', 41), 'neutral', null];
        yield 'unknown variant' => ['Agent', 'danger', null];
        yield 'CSS class as variant' => ['Agent', 'agent bg-red', null];
        yield 'control character' => ["Age\nnt", 'agent', null];
        yield 'bidi override' => ["Agent\u{202E}", 'agent', null];
        yield 'zero-width space' => ["Ag\u{200B}ent", 'agent', null];
        yield 'invalid UTF-8' => ["Agent\xC3\x28", 'agent', null];
        yield 'title too long' => ['Agent', 'agent', str_repeat('t', 121)];
        yield 'title with a line separator' => ['Agent', 'agent', "a\u{2028}b"];
    }

    #[DataProvider('invalidBadges')]
    public function testAnythingElseIsRefused(string $label, string $variant, ?string $title): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChatAuthorBadge($label, $variant, $title);
    }

    public function testAnAuthorIsItsExactReference(): void
    {
        $ref = new ChatAuthorRef('Bob', 'pub-bob');
        self::assertSame('Bob', $ref->reference);
        self::assertSame('pub-bob', $ref->publicId);
        self::assertSame('42', (new ChatAuthorRef('42', 'u-42'))->reference);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRefs(): iterable
    {
        yield 'empty reference' => ['', 'pub'];
        yield 'padded reference' => ['bob ', 'pub'];
        yield 'reference too long' => [str_repeat('a', 65), 'pub'];
        yield 'format character' => ["bob\u{200B}", 'pub'];
        yield 'empty public id' => ['bob', ''];
    }

    #[DataProvider('invalidRefs')]
    public function testAReferenceTheChatCannotStoreIsRefused(string $reference, string $publicId): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChatAuthorRef($reference, $publicId);
    }
}
