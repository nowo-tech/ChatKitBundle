<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Attachment;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentRefused;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentTypes;
use Nowo\ChatKitBundle\Enum\ChatAttachmentKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function pack;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;

final class ChatAttachmentTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array{string, string}|null}>
     */
    public static function names(): iterable
    {
        yield 'plain' => ['Report 2026.PDF', ['Report 2026.PDF', 'pdf']];
        yield 'path traversal' => ['../../etc/passwd', ['_.._etc_passwd', '']];
        yield 'windows path' => ['C:\\x\\y.txt', ['C_x_y.txt', 'txt']];
        yield 'leading dots' => ['..hidden.md', ['hidden.md', 'md']];
        yield 'RLO and controls' => ["inv\u{202E}fdp.exe\x00.txt", ['invfdp.exe.txt', 'txt']];
        yield 'long extension' => ['a.' . str_repeat('x', 17), ['a.' . str_repeat('x', 17), '']];
        yield 'bad extension' => ['a.tx t', ['a.tx t', '']];
        yield 'nothing left' => ["\u{202E}..  ", null];
        yield 'invalid utf-8' => ["\xff\xfe.txt", null];
    }

    /**
     * @param array{string, string}|null $expected
     */
    #[DataProvider('names')]
    public function testNamesAreSanitized(string $name, ?array $expected): void
    {
        self::assertSame($expected, ChatAttachmentTypes::sanitizeName($name));
    }

    public function testLongNamesKeepTheirExtensionWithin255Bytes(): void
    {
        [$name, $extension] = ChatAttachmentTypes::sanitizeName(str_repeat('ñ', 300) . '.pdf') ?? self::fail('null');
        self::assertSame('pdf', $extension);
        self::assertLessThanOrEqual(255, strlen($name));
        self::assertStringEndsWith('.pdf', $name);
        [$plain] = ChatAttachmentTypes::sanitizeName(str_repeat('a', 300)) ?? self::fail('null');
        self::assertSame(255, strlen($plain));
    }

    public function testTheSniffedBytesAndTheExtensionMustAgree(): void
    {
        $types = new ChatAttachmentTypes();
        self::assertSame(['text/markdown', ChatAttachmentKind::Markdown], $types->classify($this->file("# Title\n"), 'md'));
        self::assertSame(['text/plain', ChatAttachmentKind::Code], $types->classify($this->file("<!doctype html>\n<html><body><p onclick=\"x\">hi</p></body></html>\n"), 'html'), 'source is text, never a page');
        self::assertSame(['application/pdf', ChatAttachmentKind::Pdf], $types->classify($this->file("%PDF-1.7\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"), 'pdf'));
        self::assertSame(['image/png', ChatAttachmentKind::Image], $types->classify($this->file((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true)), 'png'));
        self::assertSame(['application/msword', ChatAttachmentKind::Office], $types->classify($this->file("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 64)), 'doc'));
        self::assertSame(ChatAttachmentKind::Office, $types->classify($this->file($this->zip('[Content_Types].xml')), 'docx')[1]);
        self::assertSame(ChatAttachmentKind::Office, $types->classify($this->file($this->zip('mimetype', 'application/vnd.oasis.opendocument.text')), 'odt')[1]);

        foreach ([['<svg xmlns="http://www.w3.org/2000/svg" onload="x"/>', 'html'], ['MZ' . str_repeat("\0", 64), 'txt'], ['plain', 'png'], ['plain', 'pdf'], [$this->zip('word/document.xml'), 'docx'], [$this->zip('mimetype', 'application/zip'), 'odt'], ['PK', 'docx'], ['plain', 'doc'], ["text\0with nul", 'md']] as [$bytes, $extension]) {
            try {
                $types->classify($this->file($bytes), $extension);
                self::fail($extension);
            } catch (ChatAttachmentRefused $refused) {
                self::assertSame(ChatAttachmentRefused::TYPE_MISMATCH, $refused->error, $extension);
                self::assertSame(415, $refused->status);
            }
        }
        foreach (['exe', 'svg', 'zip', ''] as $extension) {
            try {
                $types->classify($this->file('x'), $extension);
                self::fail($extension);
            } catch (ChatAttachmentRefused $refused) {
                self::assertSame(ChatAttachmentRefused::TYPE_NOT_ALLOWED, $refused->error);
            }
        }
    }

    public function testAnUnreadableFileIsInvalid(): void
    {
        $this->expectExceptionObject(new ChatAttachmentRefused(ChatAttachmentRefused::INVALID_FILE));
        (new ChatAttachmentTypes())->classify(sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(4)), 'txt');
    }

    public function testGroupsAndExtraTypes(): void
    {
        $types = new ChatAttachmentTypes(['pdf', 'archive'], ['heic' => 'image/heic']);
        self::assertSame(['pdf', 'zip', '7z', 'gz', 'tar', 'heic'], $types->extensions());
        self::assertSame(['pdf', 'archive', 'other'], $types->kinds());
        self::assertContains('application/zip', $types->acceptedTypes());
        self::assertContains('image/heic', $types->acceptedTypes());
        self::assertContains('text/plain', (new ChatAttachmentTypes())->acceptedTypes());
        self::assertNotContains('text/html', (new ChatAttachmentTypes())->acceptedTypes());
        self::assertNotContains('image/svg+xml', (new ChatAttachmentTypes())->acceptedTypes());
        try {
            $types->classify($this->file('plain'), 'heic');
            self::fail('heic');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::TYPE_MISMATCH, $refused->error);
        }
    }

    public function testAnUnknownGroupIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ChatAttachmentTypes(['image', 'executables']);
    }

    /**
     * @return iterable<string, array{array<array-key, string>}>
     */
    public static function badExtras(): iterable
    {
        yield 'upper case extension' => [['HEIC' => 'image/heic']];
        yield 'parameters' => [['txt' => 'text/plain; charset=utf-8']];
        yield 'not a type' => [['x' => 'nonsense']];
        yield 'integer key' => [[0 => 'text/plain']];
    }

    /**
     * @param array<array-key, string> $extra
     */
    #[DataProvider('badExtras')]
    public function testInvalidExtraTypesAreRefused(array $extra): void
    {
        $this->expectException(InvalidArgumentException::class);
        /* @phpstan-ignore argument.type */
        new ChatAttachmentTypes(['pdf'], $extra);
    }

    private function zip(string $entry, string $content = ''): string
    {
        return "PK\x03\x04" . pack('v5V3', 20, 0, 0, 0, 0, 0, strlen($content), strlen($content)) . pack('v2', strlen($entry), 0) . $entry . $content;
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'types-');
        file_put_contents($path, $bytes);

        return $path;
    }
}
