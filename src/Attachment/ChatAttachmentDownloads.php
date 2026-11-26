<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use Nowo\ChatKitBundle\Enum\ChatAttachmentKind;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Event\ChatAttachmentDownloadedEvent;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\StorageKitBundle\Download\FileDeliveryResponder;
use Nowo\StorageKitBundle\Download\FileNotDeliverable;
use Nowo\StorageKitBundle\Download\FileRangeDeliveryInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function in_array;
use function is_string;

/**
 * 2.7 (spec 259 §10): serves one signed download, for the dock route and the client API route. Each request checks,
 * in order: the variant (2.7 serves `original` only), the signature and its expiry, the reader's live access (reachable,
 * member, may read the message, not deleted; a draft only to its uploader), the clean state and the reader's download
 * budget. Every refusal is the same empty `404` with `Cache-Control: no-store` (unknown, foreign, forbidden, expired and
 * quarantined look alike); a spent budget is `429`.
 *
 * The bytes come from StorageKit's FileRangeDeliveryInterface in the chat context: a `302` to a bucket URL pre-signed
 * for `download.storage_ttl_seconds` (60 s), or a stream with `Range` (`206`). Always `nosniff`, a sandbox CSP and
 * `attachment`, except audio and video, which StorageKit may serve inline for the browser's player. Stateless.
 *
 * @internal
 */
final readonly class ChatAttachmentDownloads
{
    /**
     * @param ChatAttachments $attachments Who may see a file
     * @param ChatAttachmentSigner $signer The URL signatures
     * @param ChatAttachmentOptions $options TTLs, session binding, download audit
     * @param FileRangeDeliveryInterface $delivery StorageKit's delivery port
     * @param FileDeliveryResponder $responder StorageKit's HttpFoundation adapter
     * @param RateLimiterFactoryInterface|null $limiter Downloads per reader and minute
     * @param EventDispatcherInterface|null $events ChatAttachmentDownloadedEvent (with `audit_downloads`)
     * @param ClockInterface $clock The expiry check
     */
    public function __construct(
        private ChatAttachments $attachments,
        private ChatAttachmentSigner $signer,
        private ChatAttachmentOptions $options,
        private FileRangeDeliveryInterface $delivery,
        private FileDeliveryResponder $responder,
        private ?RateLimiterFactoryInterface $limiter = null,
        private ?EventDispatcherInterface $events = null,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * The response of one download request.
     *
     * @param ChatUserInterface|null $viewer The reader the URL must be signed for (the session's user, the token's user, or with `bind_session: false` the `v` reader); null refuses
     * @param string|null $tokenId The client API token id the URL is bound to (null on the web)
     * @param string|null $contextKey The client API context the URL is bound to
     */
    public function respond(Request $request, string $uuid, string $variant, ?ChatUserInterface $viewer, ?string $tokenId = null, ?string $contextKey = null): Response
    {
        if ($variant !== ChatAttachments::ORIGINAL || !$viewer instanceof ChatUserInterface
            || !$this->signer->verify($uuid, $variant, $viewer->getChatPublicId(), $contextKey, $tokenId, $request->query->get('e'), $request->query->get('s'), $this->clock->now()->getTimestamp())) {
            return self::notFound();
        }
        try {
            [$row] = $this->attachments->visible($viewer, $uuid);
        } catch (ChatNotFoundException) {
            return self::notFound();
        }
        if ($row['state'] !== ChatAttachmentState::Clean->value) {
            return self::notFound();
        }
        if ($this->limiter instanceof RateLimiterFactoryInterface && !$this->limiter->create('nowo_chat_download_' . $viewer->getChatPublicId())->consume()->isAccepted()) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS, ['Cache-Control' => 'no-store', 'Retry-After' => '60']);
        }
        $range = $request->headers->has('If-Range') ? null : $request->headers->get('Range');
        $media = in_array($row['kind'], [ChatAttachmentKind::Audio->value, ChatAttachmentKind::Video->value], true);
        try {
            $file = $this->delivery->deliverInContext($row['storage_uuid'], ChatAttachmentObjects::CONTEXT, $row['conversation_uuid'], $variant, is_string($range) ? $range : null, $media, $this->options->storageTtlSeconds);
        } catch (FileNotDeliverable) {
            return self::notFound();
        }
        if ($this->options->auditDownloads) {
            $this->events?->dispatch(new ChatAttachmentDownloadedEvent($row['uuid'], $row['conversation_uuid'], $viewer->getChatPublicId(), $tokenId !== null));
        }

        return $this->responder->toResponse($file);
    }

    /** The one refusal: an empty 404 nobody caches. */
    public static function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
