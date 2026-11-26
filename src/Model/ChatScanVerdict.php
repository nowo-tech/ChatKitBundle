<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use Nowo\ChatKitBundle\Enum\ChatScanOutcome;

use function mb_substr;
use function preg_replace;
use function trim;

/**
 * 2.7 (spec 259): the scanner's answer for one file (ChatAttachmentScannerInterface). The engine and the signature are
 * stored on the attachment row for moderators and the audit; they are never shown to the uploader or to readers.
 */
final readonly class ChatScanVerdict
{
    /** Longest stored engine name. */
    public const int ENGINE_MAX = 64;

    /** Longest stored signature name. */
    public const int SIGNATURE_MAX = 120;

    public string $engine;

    public ?string $signature;

    /**
     * @param ChatScanOutcome $outcome What the engine decided
     * @param string $engine The engine and its version (`clamav-1.4`); control characters dropped, at most 64 characters
     * @param string|null $signature What matched (`Win.Test.EICAR_HDB-1`); at most 120 characters, null when none
     */
    public function __construct(
        public ChatScanOutcome $outcome,
        string $engine,
        ?string $signature = null,
    ) {
        $this->engine    = $this->clip($engine, self::ENGINE_MAX);
        $clipped         = $signature === null ? '' : $this->clip($signature, self::SIGNATURE_MAX);
        $this->signature = $clipped === '' ? null : $clipped;
    }

    private function clip(string $text, int $max): string
    {
        return trim(mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text)), 0, $max));
    }
}
