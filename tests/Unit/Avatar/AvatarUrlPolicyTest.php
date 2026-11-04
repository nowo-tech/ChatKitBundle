<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Avatar;

use Nowo\ChatKitBundle\Avatar\AvatarUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function is_string;
use function json_decode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 1.16 URL policy of profile pictures, over the table the client check (avatar.test.ts) runs too.
 */
final class AvatarUrlPolicyTest extends TestCase
{
    private const string TABLE = '/src/Resources/assets/tests/fixtures/avatar-urls.json';

    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        foreach (self::table('accepted') as $name => $url) {
            self::assertIsString($url);

            yield $name => [$url];
        }
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function rejected(): iterable
    {
        foreach (self::table('rejected') as $name => $url) {
            yield $name => [$url];
        }
    }

    #[DataProvider('accepted')]
    public function testAcceptedUrlsAreKept(string $url): void
    {
        self::assertSame($url, AvatarUrlPolicy::sanitize($url));
    }

    #[DataProvider('rejected')]
    public function testRejectedUrlsGiveNull(?string $url): void
    {
        self::assertNull(AvatarUrlPolicy::sanitize($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUtf8(): iterable
    {
        yield 'broken two-byte sequence' => ["/files/\xC3\x28.webp"];
        yield 'lone continuation byte' => ["https://cdn.example.com/a\x80.webp"];
        yield 'overlong encoding' => ["/files/\xC0\xAF.webp"];
        yield 'latin-1 byte' => ["/files/caf\xE9.webp"];
    }

    /** EE-L1: JSON cannot encode invalid UTF-8, so such a URL would turn the whole response into a 500. */
    #[DataProvider('invalidUtf8')]
    public function testInvalidUtf8GivesNull(string $url): void
    {
        self::assertNull(AvatarUrlPolicy::sanitize($url));
    }

    /**
     * @return array<string, string|null> name => URL
     */
    private static function table(string $section): array
    {
        /** @var array<string, list<array{name: string, url?: string|null, prefix?: string, repeat?: string, count?: int, suffix?: string}>> $table */
        $table = json_decode((string) file_get_contents(dirname(__DIR__, 3) . self::TABLE), true, 512, JSON_THROW_ON_ERROR);
        $urls  = [];
        foreach ($table[$section] as $entry) {
            $urls[$entry['name']] = is_string($entry['repeat'] ?? null)
                ? ($entry['prefix'] ?? '') . str_repeat($entry['repeat'], $entry['count'] ?? 0) . ($entry['suffix'] ?? '')
                : $entry['url'] ?? null;
        }

        return $urls;
    }
}
