<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use function count;
use function ini_get;
use function is_string;
use function min;
use function preg_match;
use function strtolower;
use function trim;

/**
 * 2.7 (spec 259): the `attachments` options of the default profile, as the extension built them. Immutable, built once
 * per container (no per-request state).
 */
final readonly class ChatAttachmentOptions
{
    /** Default size limit: 25 MiB (D-20261126-02, QB-259-6). */
    public const int DEFAULT_MAX_BYTES = 26_214_400;

    /** Hard ceiling of `max_bytes`: 100 MiB (StorageKit's `upload.files.max_bytes` default). */
    public const int CEILING = 104_857_600;

    /** Scan retry delays after a Retry or a scanner failure, by attempt (seconds): 30 s, 2 min, 10 min, 30 min, 2 h. */
    public const array BACKOFF = [30, 120, 600, 1800, 7200];

    /**
     * @param int $maxBytes Largest file (`max_bytes`)
     * @param int $maxPerMessage Files per message (`max_per_message`)
     * @param int $maxPendingPerUser Drafts (not sent, not removed) per person (`max_pending_per_user`)
     * @param int $pendingTtlHours Unsent drafts are removed after this (`pending_ttl_hours`)
     * @param int $scanTimeoutSeconds What the scanner is asked to answer within (`scan.timeout_seconds`)
     * @param int $leaseSeconds How long a scan worker owns a file (`scan.lease_seconds`)
     * @param int $maxAttempts Scan attempts before `failed` (`scan.max_attempts`)
     * @param bool $allowUnscannable `scan.unscannable: allow` (else block, Q-259-12)
     * @param int $alertAfterSeconds Waiting time that raises ChatAttachmentScanOverdueEvent (`scan.alert_after_seconds`)
     * @param int $urlTtlSeconds Lifetime of a signed download URL (`download.url_ttl_seconds`)
     * @param bool $bindSession Web URLs also need the signed-in viewer's session (`download.bind_session`, Q-259-5)
     * @param int $storageTtlSeconds Lifetime of StorageKit's pre-signed bucket URL behind a download (`download.storage_ttl_seconds`)
     * @param bool $auditDownloads Dispatch ChatAttachmentDownloadedEvent (`audit_downloads`)
     */
    public function __construct(
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $maxPerMessage = 10,
        public int $maxPendingPerUser = 20,
        public int $pendingTtlHours = 24,
        public int $scanTimeoutSeconds = 60,
        public int $leaseSeconds = 300,
        public int $maxAttempts = 5,
        public bool $allowUnscannable = false,
        public int $alertAfterSeconds = 300,
        public int $urlTtlSeconds = 300,
        public bool $bindSession = true,
        public int $storageTtlSeconds = 60,
        public bool $auditDownloads = false,
    ) {
    }

    /**
     * The largest file accepted now: the smallest of `max_bytes`, PHP's `upload_max_filesize` and `post_max_size`
     * (read on every call: nothing is cached).
     */
    public function effectiveMaxBytes(): int
    {
        $limit = $this->maxBytes;
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::bytes(ini_get($key));
            if ($bytes > 0) {
                $limit = min($limit, $bytes);
            }
        }

        return $limit;
    }

    /**
     * Seconds before the next scan attempt after attempt `$attempt` failed.
     */
    public function backoff(int $attempt): int
    {
        return self::BACKOFF[min(count(self::BACKOFF), max(1, $attempt)) - 1];
    }

    /**
     * A php.ini size (`25M`, `1G`, `512K`, bytes); 0 for none or unlimited.
     */
    public static function bytes(string|false $value): int
    {
        if (!is_string($value) || preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/i', $value, $m) !== 1) {
            return 0;
        }

        return (int) $m[1] * match (strtolower(trim($m[2]))) {
            'k'     => 1024,
            'm'     => 1_048_576,
            'g'     => 1_073_741_824,
            default => 1,
        };
    }
}
