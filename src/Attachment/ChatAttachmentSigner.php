<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use SensitiveParameter;

use function base64_encode;
use function hash_equals;
use function hash_hkdf;
use function hash_hmac;
use function implode;
use function is_string;
use function preg_match;
use function rtrim;
use function strtr;

/**
 * 2.7 (spec 259 §10, Q-259-5 A): the signature of a download URL. It binds the attachment, the variant, the reader's
 * public id and, on the client API, the token id and the context; it expires after `download.url_ttl_seconds`.
 *
 * `s = base64url(HMAC-SHA256(k, "v1|uuid|variant|viewer|context|token|e"))`, with `k` derived from `kernel.secret` by
 * HKDF (info `nowo_chat_kit.files`) when the service is built: a constant, never per-request state. Compared with
 * `hash_equals`.
 */
final readonly class ChatAttachmentSigner
{
    /** HKDF info of the key. */
    public const string INFO = 'nowo_chat_kit.files';

    private string $key;

    /**
     * @param string $secret `kernel.secret`
     */
    public function __construct(#[SensitiveParameter] string $secret)
    {
        $this->key = hash_hkdf('sha256', $secret, 32, self::INFO);
    }

    /**
     * The signature of one URL.
     */
    public function sign(string $attachmentUuid, string $variant, string $viewer, ?string $context, ?string $token, int $expires): string
    {
        $payload = implode('|', ['v1', $attachmentUuid, $variant, $viewer, $context ?? '', $token ?? '', (string) $expires]);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->key, true)), '+/', '-_'), '=');
    }

    /**
     * Whether a signature is valid and not expired at `$now` (Unix seconds).
     *
     * @param mixed $expires The `e` query value
     * @param mixed $signature The `s` query value
     */
    public function verify(string $attachmentUuid, string $variant, string $viewer, ?string $context, ?string $token, mixed $expires, mixed $signature, int $now): bool
    {
        if (!is_string($expires) || !is_string($signature) || preg_match('/^[1-9]\d{0,11}\z/', $expires) !== 1 || preg_match('/^[A-Za-z0-9_-]{43}\z/', $signature) !== 1) {
            return false;
        }
        if ((int) $expires < $now) {
            return false;
        }

        return hash_equals($this->sign($attachmentUuid, $variant, $viewer, $context, $token, (int) $expires), $signature);
    }
}
