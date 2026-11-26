<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Attachment;

use Closure;
use Nowo\ChatKitBundle\Contract\ChatAttachmentScannerInterface;
use Nowo\ChatKitBundle\Enum\ChatScanOutcome;
use Nowo\ChatKitBundle\Model\ChatScanSubject;
use Nowo\ChatKitBundle\Model\ChatScanVerdict;
use RuntimeException;

use function str_contains;

/**
 * 2.7: the test scanner. Bytes holding `EICAR` are infected, `ENCRYPTED` unscannable, `BUSY` a retry, `CRASH` an
 * exception; everything else is clean. Records what it read (never a file name: the subject has none).
 */
final class TestScanner implements ChatAttachmentScannerInterface
{
    /** @var list<array{uuid: string, size: int, sha256: string, attempt: int}> */
    public array $scanned = [];

    /** @var (Closure(ChatScanSubject): void)|null Runs while the file is being scanned (race tests). */
    public ?Closure $during = null;

    public function scan(ChatScanSubject $subject): ChatScanVerdict
    {
        $bytes = '';
        while (!$subject->stream->eof()) {
            $bytes .= $subject->stream->read(8192);
        }
        if ($this->during instanceof Closure) {
            ($this->during)($subject);
        }
        $this->scanned[] = ['uuid' => $subject->attachmentUuid, 'size' => $subject->size, 'sha256' => $subject->sha256, 'attempt' => $subject->attempt];
        if (str_contains($bytes, 'CRASH')) {
            throw new RuntimeException('clamd unreachable');
        }

        return match (true) {
            str_contains($bytes, 'EICAR')     => new ChatScanVerdict(ChatScanOutcome::Infected, 'test-1.0', 'Eicar-Test-Signature'),
            str_contains($bytes, 'ENCRYPTED') => new ChatScanVerdict(ChatScanOutcome::Unscannable, 'test-1.0'),
            str_contains($bytes, 'BUSY')      => new ChatScanVerdict(ChatScanOutcome::Retry, 'test-1.0'),
            default                           => new ChatScanVerdict(ChatScanOutcome::Clean, 'test-1.0'),
        };
    }
}
