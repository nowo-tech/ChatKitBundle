<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 2.7 (spec 259, QB-259-3 A): where a conversation's files are stored, as ChatAttachmentScopeResolverInterface answers
 * it: the StorageKit storage scope whose bucket holds them (null: the instance bucket) and the usage owner they count
 * against (null: the conversation itself). Both keys are checked by StorageKit byte for byte.
 */
final readonly class ChatStorageTarget
{
    /**
     * @param string|null $scope StorageKit scope key (Beacon: the tenant's bucket scope); null for the instance bucket
     * @param string|null $owner Usage owner key (≤ 64 characters); null for the conversation uuid
     */
    public function __construct(
        public ?string $scope = null,
        public ?string $owner = null,
    ) {
    }
}
