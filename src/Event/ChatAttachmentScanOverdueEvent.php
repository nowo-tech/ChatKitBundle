<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

/**
 * 2.7 (spec 259, QB-259-2): the alert hook of the scan pipeline, dispatched by `nowo:chat:attachments:sweep` when files
 * wait for their scan longer than `attachments.scan.alert_after_seconds` (default 5 minutes), or when files ended
 * `failed` since the previous run. The scan stays fail-closed meanwhile (nothing unscanned is ever served); route this
 * event to your alerting (no destination is configured by the bundle). Counts only.
 */
final readonly class ChatAttachmentScanOverdueEvent
{
    /**
     * @param int $waiting Files uploaded or being scanned for longer than the alert threshold
     * @param int $oldestSeconds Age of the oldest of them, in seconds (0 when none)
     * @param int $failed Files that ended `failed` within the last threshold window
     */
    public function __construct(
        public int $waiting,
        public int $oldestSeconds,
        public int $failed,
    ) {
    }
}
