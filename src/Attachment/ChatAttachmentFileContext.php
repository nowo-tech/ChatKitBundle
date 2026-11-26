<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use Nowo\ChatKitBundle\Contract\ChatAttachmentScopeResolverInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatStorageTarget;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Context\GenericFileContextInterface;
use Nowo\StorageKitBundle\Context\SquareImageContextInterface;

use function array_values;
use function in_array;

/**
 * 2.7 (spec 259 §4): the StorageKit file context `chat` (context id = the conversation uuid).
 *
 * - `readable()` always answers `[]`: StorageKit's own download route answers 404 for every chat file. It cannot see
 *   the quarantine, so only ChatKit's routes serve chat files (after the scan, the membership and the signature).
 * - `writableTarget()` re-checks that the person may write in the conversation and asks the host's
 *   ChatAttachmentScopeResolverInterface for the bucket scope and owner (default: the instance bucket, owner = the
 *   conversation uuid).
 * - `existing()` answers the conversations that still exist (StorageKit orphans the files of a deleted one).
 *
 * - Audit ZCO-L1: it also carries StorageKit's `SquareImageContextInterface` marker (an empty interface), borrowed
 *   only for its refusal: StorageKit's HTTP upload route (`POST <prefix>/upload/chat/{uuid}`, `ImageUploader::
 *   uploadImage()`) and `UploadEndpoint::config()` then refuse the `chat` context like an unknown one (404), so files
 *   reach the chat bucket only through ChatKit's own upload (`FileUploaderInterface::uploadFile()`, which does not look
 *   at the marker). Follow-up: a dedicated "server-only" marker in StorageKit 2.3.
 *
 * Stateless.
 *
 * @internal
 */
final readonly class ChatAttachmentFileContext implements GenericFileContextInterface, SquareImageContextInterface
{
    /**
     * @param ChatConversationRepository $conversations Conversations by uuid
     * @param ChatVoter $voter Write access
     * @param ChatAccess $access Reachability
     * @param ChatAttachmentTypes $types The allow-list
     * @param ChatAttachmentOptions $options The size limit
     * @param ChatAttachmentScopeResolverInterface|null $scopes The host's bucket scope resolver
     */
    public function __construct(
        private ChatConversationRepository $conversations,
        private ChatVoter $voter,
        private ChatAccess $access,
        private ChatAttachmentTypes $types,
        private ChatAttachmentOptions $options,
        private ?ChatAttachmentScopeResolverInterface $scopes = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function type(): string
    {
        return ChatAttachmentObjects::CONTEXT;
    }

    /**
     * Never through StorageKit's route (quarantine, signed URLs, live membership): ChatKit serves chat files.
     *
     * {@inheritDoc}
     */
    public function readable(?object $viewer, array $ids): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function existing(array $ids): array
    {
        $found = [];
        foreach ($ids === [] ? [] : $this->conversations->findBy(['uuid' => $ids]) as $conversation) {
            if (in_array($conversation->getUuid(), $ids, true)) {
                $found[$conversation->getUuid()] = $conversation->getUuid();
            }
        }

        return array_values($found);
    }

    /**
     * {@inheritDoc}
     */
    public function writableTarget(object $user, string $id): ?FileContextTarget
    {
        $conversation = $this->conversations->findOneByUuid($id);
        if (!$user instanceof ChatUserInterface || !$conversation instanceof ChatConversation || $conversation->getUuid() !== $id
            || !$this->access->isReachable($user) || !$this->voter->decide(ChatVoter::SEND, $conversation, $user)) {
            return null;
        }
        $target = $this->scopes?->targetFor(new ChatComposerScope($user->getChatPublicId(), $id, $conversation->getKind()->value, $conversation->getChannelKey()));

        return new FileContextTarget($target?->scope, ($target instanceof ChatStorageTarget ? $target->owner : null) ?? $id);
    }

    /**
     * {@inheritDoc}
     */
    public function acceptedTypes(): array
    {
        return $this->types->acceptedTypes();
    }

    /**
     * {@inheritDoc}
     */
    public function maxBytes(): int
    {
        return $this->options->maxBytes;
    }
}
