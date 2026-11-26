<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Controller;

use Nowo\ChatKitBundle\Attachment\ChatAttachmentOptions;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentRefused;
use Nowo\ChatKitBundle\Attachment\ChatAttachments;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentTypes;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Model\ChatAttachmentView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Port\ChatCurrentUserPort;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatIdempotency;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

use function array_map;
use function assert;
use function in_array;
use function intdiv;
use function max;

use const DATE_ATOM;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;

/**
 * 2.7 (spec 259 block B): the dock's attachment routes (internal UI JSON, session + CSRF, like ChatApiController). They
 * answer 404 while `attachments.enabled` is false.
 *
 * - `POST /api/conversations/{uuid}/attachments` (multipart `file`, optional `Idempotency-Key`): a draft, `201`.
 * - `GET /api/conversations/{uuid}/attachments/drafts`: the caller's drafts there (states for the composer).
 * - `GET /api/attachments/{uuid}`: one file as the caller may see it, with a fresh signed download URL when clean.
 * - `DELETE /api/attachments/{uuid}`: removes a draft, or a file of a message the caller may delete.
 *
 * Uploads are rate limited per person (`attachments.limits.uploads_per_minute` and `upload_bytes_per_hour`: StorageKit
 * does not limit its generic ports). Refusals are `{ok: false, error}` with the codes of ChatAttachmentRefused.
 */
#[Route('/api', name: 'nowo_chat_api_')]
final class ChatAttachmentController extends AbstractController
{
    /**
     * @param ChatAccess $access Reachability of the signed-in person
     * @param RateLimiterFactoryInterface $uploadLimiter Uploads per person and minute
     * @param RateLimiterFactoryInterface $bytesLimiter Uploaded KiB per person and hour
     * @param RateLimiterFactoryInterface $readLimiter The person's read budget (shared with the dock)
     * @param ChatAttachments|null $attachments The attachments; null while they are off (every route answers 404)
     * @param ChatAttachmentOptions|null $options The limits
     * @param ChatAttachmentTypes|null $types The allowed extensions (the picker's `accept`)
     * @param ChatCurrentUserPort|null $currentUser Who the signed-in user is in the chat
     * @param RateLimiterFactoryInterface|null $actionLimiter Audit ZCO-L2: the person's action budget (shared with the dock's other writes)
     */
    public function __construct(
        private readonly ChatAccess $access,
        #[Autowire(service: 'limiter.nowo_chat_attachment_upload')]
        private readonly RateLimiterFactoryInterface $uploadLimiter,
        #[Autowire(service: 'limiter.nowo_chat_attachment_bytes')]
        private readonly RateLimiterFactoryInterface $bytesLimiter,
        #[Autowire(service: 'limiter.nowo_chat_read')]
        private readonly RateLimiterFactoryInterface $readLimiter,
        private readonly ?ChatAttachments $attachments = null,
        private readonly ?ChatAttachmentOptions $options = null,
        private readonly ?ChatAttachmentTypes $types = null,
        private readonly ?ChatCurrentUserPort $currentUser = null,
        #[Autowire(service: 'limiter.nowo_chat_action')]
        private readonly ?RateLimiterFactoryInterface $actionLimiter = null,
    ) {
    }

    /**
     * Uploads one file as a draft of the caller.
     *
     * @return JsonResponse `{attachment}` (201; 200 for a replayed `Idempotency-Key`)
     */
    #[Route('/conversations/{uuid}/attachments', name: 'attachment_upload', requirements: ['uuid' => ChatApiController::UUID], methods: ['POST'])]
    public function upload(string $uuid, Request $request): JsonResponse
    {
        $attachments = $this->on();
        $user        = $this->writer($request);
        $this->deny($request->headers->has('Content-Encoding'), ChatAttachmentRefused::CONTENT_ENCODING, Response::HTTP_BAD_REQUEST);
        $file = $request->files->get('file');
        if ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ChatApiException(ChatAttachmentRefused::FILE_TOO_LARGE, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $this->deny(!$file instanceof UploadedFile || !$file->isValid(), ChatAttachmentRefused::INVALID_FILE, Response::HTTP_BAD_REQUEST);
        assert($file instanceof UploadedFile);
        $key = $request->headers->get(ChatIdempotency::HEADER);
        $this->deny($key !== null && (!ChatIdempotency::isValidKey($key) || ChatIdempotency::isClientKey($key)), 'idempotency_key_invalid', Response::HTTP_BAD_REQUEST);
        $this->deny(!$this->uploadLimiter->create('nowo_chat_upload_' . $user->getChatPublicId())->consume()->isAccepted(), 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        $kib = max(1, intdiv((int) $file->getSize() + 1023, 1024));
        $this->deny(!$this->bytesLimiter->create('nowo_chat_upload_bytes_' . $user->getChatPublicId())->consume($kib)->isAccepted(), 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        try {
            $view = $attachments->upload($user, $uuid, $file->getPathname(), $file->getClientOriginalName(), $key);
        } catch (ChatAttachmentRefused $refused) {
            throw new ChatApiException($refused->error, $refused->status, $refused);
        } catch (ChatNotFoundException $notFound) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND, $notFound);
        }

        return $this->json(['attachment' => $view->toArray()], Response::HTTP_CREATED);
    }

    /**
     * The caller's drafts of the conversation (any state but removed), with what the composer needs to know.
     *
     * @return JsonResponse `{attachments, limits}`
     */
    #[Route('/conversations/{uuid}/attachments/drafts', name: 'attachment_drafts', requirements: ['uuid' => ChatApiController::UUID], methods: ['GET'])]
    public function drafts(string $uuid): JsonResponse
    {
        $attachments = $this->on();
        $user        = $this->reader();
        try {
            $drafts = $attachments->drafts($user, $uuid);
        } catch (ChatNotFoundException $notFound) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND, $notFound);
        }

        return $this->json(['attachments' => array_map(static fn (ChatAttachmentView $view): array => $view->toArray(), $drafts), 'limits' => $this->limits()]);
    }

    /**
     * One file as the caller may see it; a clean one carries a download URL signed for the caller (short-lived).
     *
     * @return JsonResponse `{attachment, url, expiresAt}`
     */
    #[Route('/attachments/{uuid}', name: 'attachment', requirements: ['uuid' => ChatApiController::UUID], methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $attachments = $this->on();
        $user        = $this->reader();
        try {
            $view = $attachments->find($user, $uuid);
            $url  = $view->state->value === 'clean' ? $attachments->downloadUrl($user, $uuid) : null;
        } catch (ChatNotFoundException $notFound) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND, $notFound);
        }

        return $this->json(['attachment' => $view->toArray(), 'url' => $url?->url, 'expiresAt' => $url?->expiresAt->format(DATE_ATOM)]);
    }

    /**
     * Removes a draft, or a file of a message the caller may delete. Idempotent.
     *
     * @return JsonResponse `{ok: true}`
     */
    #[Route('/attachments/{uuid}', name: 'attachment_remove', requirements: ['uuid' => ChatApiController::UUID], methods: ['DELETE'])]
    public function remove(string $uuid, Request $request): JsonResponse
    {
        $attachments = $this->on();
        $user        = $this->writer($request);
        $this->deny($this->actionLimiter?->create('nowo_chat_action_' . $user->getChatPublicId())->consume()->isAccepted() === false, 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        try {
            // @igor-ignore - a write of the attachment rows (database), not a change of the service's state.
            $attachments->remove($user, $uuid);
        } catch (ChatNotFoundException $notFound) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND, $notFound);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Never cached by the browser, a proxy or a service worker.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $context
     */
    protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * What the composer needs: the effective size limit, files per message, the allowed extensions.
     *
     * @return array{maxBytes: int, maxPerMessage: int, extensions: list<string>}
     */
    private function limits(): array
    {
        return [
            'maxBytes'      => $this->options?->effectiveMaxBytes() ?? 0,
            'maxPerMessage' => ($this->options instanceof ChatAttachmentOptions ? $this->options->maxPerMessage : 0),
            'extensions'    => $this->types?->extensions() ?? [],
        ];
    }

    private function on(): ChatAttachments
    {
        return $this->attachments ?? throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
    }

    private function chatUser(): ChatUserInterface
    {
        $user = $this->getUser();
        $user = $this->currentUser instanceof ChatCurrentUserPort ? $this->currentUser->resolve($user) : $user;
        $this->deny(!$user instanceof ChatUserInterface || !$this->access->isReachable($user), 'unauthorized', Response::HTTP_UNAUTHORIZED);
        assert($user instanceof ChatUserInterface);

        return $user;
    }

    private function reader(): ChatUserInterface
    {
        $user = $this->chatUser();
        $this->deny(!$this->readLimiter->create('nowo_chat_read_' . $user->getChatPublicId())->consume()->isAccepted(), 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);

        return $user;
    }

    private function writer(Request $request): ChatUserInterface
    {
        $user = $this->chatUser();
        $this->deny(!$this->isCsrfTokenValid(ChatApiController::CSRF_ID, (string) $request->headers->get('X-CSRF-TOKEN', '')), 'invalid_csrf', Response::HTTP_FORBIDDEN);

        return $user;
    }

    private function deny(bool $condition, string $error, int $status): void
    {
        if ($condition) {
            throw new ChatApiException($error, $status);
        }
    }
}
