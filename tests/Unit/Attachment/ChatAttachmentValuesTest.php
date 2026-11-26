<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Attachment;

use ArrayObject;
use DateTimeImmutable;
use LogicException;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentOptions;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentRefused;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentSigner;
use Nowo\ChatKitBundle\Enum\ChatAttachmentKind;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Enum\ChatScanOutcome;
use Nowo\ChatKitBundle\Model\ChatAttachmentView;
use Nowo\ChatKitBundle\Model\ChatFileStream;
use Nowo\ChatKitBundle\Model\ChatScanVerdict;
use PHPUnit\Framework\TestCase;

use function ini_get;
use function serialize;
use function str_repeat;
use function strlen;

final class ChatAttachmentValuesTest extends TestCase
{
    public function testSignaturesBindEveryPartAndExpire(): void
    {
        $signer = new ChatAttachmentSigner('kernel-secret');
        $sig    = $signer->sign('u1', 'original', 'viewer', 'ctx', 'tok', 2000);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $sig);
        self::assertTrue($signer->verify('u1', 'original', 'viewer', 'ctx', 'tok', '2000', $sig, 1999));
        self::assertTrue($signer->verify('u1', 'original', 'viewer', 'ctx', 'tok', '2000', $sig, 2000));
        self::assertFalse($signer->verify('u1', 'original', 'viewer', 'ctx', 'tok', '2000', $sig, 2001), 'expired');
        foreach ([['u2', 'original', 'viewer', 'ctx', 'tok'], ['u1', 'thumb', 'viewer', 'ctx', 'tok'], ['u1', 'original', 'other', 'ctx', 'tok'], ['u1', 'original', 'viewer', null, 'tok'], ['u1', 'original', 'viewer', 'ctx', null]] as [$uuid, $variant, $viewer, $context, $token]) {
            self::assertFalse($signer->verify($uuid, $variant, $viewer, $context, $token, '2000', $sig, 1000));
        }
        self::assertFalse((new ChatAttachmentSigner('another-secret'))->verify('u1', 'original', 'viewer', 'ctx', 'tok', '2000', $sig, 1000));
        foreach ([[null, $sig], ['2000', null], ['02000', $sig], ['2000', 'short'], ['2000', $sig . '=']] as [$expires, $signature]) {
            self::assertFalse($signer->verify('u1', 'original', 'viewer', 'ctx', 'tok', $expires, $signature, 1000));
        }
        self::assertFalse($signer->verify('u1', 'original', 'viewer', 'ctx', 'tok', '2001', $sig, 1000), 'expiry is signed');
    }

    public function testOptions(): void
    {
        $options = new ChatAttachmentOptions(maxBytes: 1);
        self::assertSame(1, $options->effectiveMaxBytes());
        $php = min(array_filter([ChatAttachmentOptions::bytes(ini_get('upload_max_filesize')), ChatAttachmentOptions::bytes(ini_get('post_max_size'))]) ?: [ChatAttachmentOptions::CEILING]);
        self::assertSame(min(ChatAttachmentOptions::CEILING, $php), (new ChatAttachmentOptions(maxBytes: ChatAttachmentOptions::CEILING))->effectiveMaxBytes());
        self::assertSame([30, 30, 120, 7200, 7200], [$options->backoff(0), $options->backoff(1), $options->backoff(2), $options->backoff(5), $options->backoff(99)]);
        self::assertSame([512, 2_097_152, 3_221_225_472, 7, 0, 0, 0], [ChatAttachmentOptions::bytes('512'), ChatAttachmentOptions::bytes('2M'), ChatAttachmentOptions::bytes(' 3g '), ChatAttachmentOptions::bytes('7'), ChatAttachmentOptions::bytes('-1'), ChatAttachmentOptions::bytes(false), ChatAttachmentOptions::bytes('1T')]);
        self::assertSame(1024, ChatAttachmentOptions::bytes('1k'));
    }

    public function testRefusalsCarryTheirStatus(): void
    {
        foreach (ChatAttachmentRefused::STATUS as $code => $status) {
            $refused = new ChatAttachmentRefused($code);
            self::assertSame($code, $refused->error);
            self::assertSame($code, $refused->getMessage());
            self::assertSame($status, $refused->status);
        }
        self::assertSame(422, (new ChatAttachmentRefused('other'))->status);
    }

    public function testVerdictsAreClipped(): void
    {
        $verdict = new ChatScanVerdict(ChatScanOutcome::Infected, " clam\u{202E}av\n" . str_repeat('x', 100), "  \n ");
        self::assertSame(64, mb_strlen($verdict->engine));
        self::assertStringNotContainsString("\u{202E}", $verdict->engine);
        self::assertNull($verdict->signature);
        self::assertSame('Eicar', (new ChatScanVerdict(ChatScanOutcome::Infected, 'e', 'Eicar'))->signature);
        self::assertNull((new ChatScanVerdict(ChatScanOutcome::Clean, 'e'))->signature);
    }

    public function testTheFileStreamIsBoundedClosableAndNeverCopied(): void
    {
        $closed = 0;
        $data   = 'abcdef';
        $cursor = new ArrayObject(['offset' => 0]);
        $stream = new ChatFileStream(6, static function (int $n) use ($data, $cursor): string {
            $chunk            = substr($data, (int) $cursor['offset'], $n);
            $cursor['offset'] = (int) $cursor['offset'] + strlen($chunk);

            return $chunk;
        }, static fn (): bool => (int) $cursor['offset'] >= 6, static function () use (&$closed): void {
            ++$closed;
        });
        self::assertSame(6, $stream->size);
        self::assertSame('abcd', $stream->read(4));
        self::assertFalse($stream->eof());
        self::assertSame('ef', $stream->read());
        self::assertTrue($stream->eof());
        $stream->close();
        $stream->close();
        self::assertSame(1, $closed);
        self::assertTrue($stream->eof());
        try {
            $stream->read();
            self::fail('closed');
        } catch (LogicException) {
        }
        try {
            self::fail('clone ' . self::copyOf($stream)::class);
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            serialize($stream);
            self::fail('serialize');
        } catch (LogicException) {
        }
        $dropped = new ChatFileStream(0, static fn (int $n): string => '', static fn (): bool => true, static function () use (&$closed): void {
            ++$closed;
        });
        unset($dropped);
        self::assertSame(2, $closed, 'closed when dropped');
    }

    public function testViewsShareOneJsonShape(): void
    {
        $view = new ChatAttachmentView('u', 'c', null, 'a.pdf', 'pdf', 'application/pdf', ChatAttachmentKind::Pdf, 12, ChatAttachmentState::Clean, 0, new DateTimeImmutable());
        self::assertSame(['uuid' => 'u', 'name' => 'a.pdf', 'extension' => 'pdf', 'mediaType' => 'application/pdf', 'kind' => 'pdf', 'size' => 12, 'state' => 'clean'], $view->toArray());
    }

    private static function copyOf(object $object): object
    {
        return clone $object;
    }
}
