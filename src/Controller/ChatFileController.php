<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Controller;

use Nowo\ChatKitBundle\Attachment\ChatAttachmentDownloads;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentOptions;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Port\ChatCurrentUserPort;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_string;

/**
 * 2.7 (spec 259 §10, Q-259-5 A): `GET <chat prefix>/files/{uuid}/{variant}?e=…&s=…`, the web download of an attachment.
 * The URL is signed for one reader and, with `download.bind_session: true` (default), works only in that reader's
 * session: a leaked URL is useless without it. Membership, the message and the clean state are checked again on every
 * request. Every refusal is the same empty `404` (also while attachments are off).
 */
final class ChatFileController extends AbstractController
{
    /**
     * @param ChatDirectoryInterface $directory (bind_session: false) the signed reader by public id
     * @param ChatAttachmentDownloads|null $downloads The checks and the delivery; null while attachments are off
     * @param ChatAttachmentOptions|null $options `download.bind_session`
     * @param ChatCurrentUserPort|null $currentUser Who the signed-in user is in the chat
     */
    public function __construct(
        private readonly ChatDirectoryInterface $directory,
        private readonly ?ChatAttachmentDownloads $downloads = null,
        private readonly ?ChatAttachmentOptions $options = null,
        private readonly ?ChatCurrentUserPort $currentUser = null,
    ) {
    }

    /**
     * Serves one signed download (302 to a 60-second bucket URL, or the stream; 206 for a range).
     */
    #[Route('/files/{uuid}/{variant}', name: 'nowo_chat_file', requirements: ['uuid' => ChatApiController::UUID, 'variant' => '[a-z]{1,16}'], methods: ['GET', 'HEAD'])]
    public function download(string $uuid, string $variant, Request $request): Response
    {
        if (!$this->downloads instanceof ChatAttachmentDownloads || !$this->options instanceof ChatAttachmentOptions) {
            return ChatAttachmentDownloads::notFound();
        }

        return $this->downloads->respond($request, $uuid, $variant, $this->viewer($request));
    }

    /** The reader the URL must be signed for. */
    private function viewer(Request $request): ?ChatUserInterface
    {
        if ($this->options?->bindSession !== false) {
            $user = $this->getUser();
            $user = $this->currentUser instanceof ChatCurrentUserPort ? $this->currentUser->resolve($user) : $user;

            return $user instanceof ChatUserInterface ? $user : null;
        }
        $publicId = $request->query->get('v');
        $user     = is_string($publicId) && $publicId !== '' ? $this->directory->findByPublicId($publicId) : null;

        return $user instanceof ChatUserInterface && $user->getChatPublicId() === $publicId ? $user : null;
    }
}
