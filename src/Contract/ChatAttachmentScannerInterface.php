<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Enum\ChatScanOutcome;
use Nowo\ChatKitBundle\Model\ChatScanSubject;
use Nowo\ChatKitBundle\Model\ChatScanVerdict;

/**
 * 2.7 (spec 259, owner decision 2026-11-18, QB-259-2): the host's virus scanner, **mandatory** with
 * `attachments.enabled` (the container build fails without it). Beacon: ClamAV `clamd` INSTREAM on the internal
 * network, with connect and read timeouts.
 *
 * Called from a Messenger handler, outside any transaction or lock (REQ-RUNTIME-003 rule 9), at most once per lease of
 * the file. Answer within {@see ChatScanSubject::$timeoutSeconds} or throw: an exception counts as
 * {@see ChatScanOutcome::Retry}. After `scan.max_attempts` the file is `failed` and never
 * served. **There is no fail-open path.** Do not keep the stream or the bytes after returning, and keep no per-file state
 * in the service (FrankenPHP worker mode).
 */
interface ChatAttachmentScannerInterface
{
    /**
     * Scans one quarantined file.
     *
     * @param ChatScanSubject $subject The file (never its name)
     *
     * @return ChatScanVerdict The engine's answer
     */
    public function scan(ChatScanSubject $subject): ChatScanVerdict;
}
