<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatStorageTarget;

/**
 * 2.7 (spec 259, QB-259-3 A): which StorageKit bucket and usage owner hold a conversation's files. Optional
 * (`attachments.scope_resolver`); without it every file goes to the instance bucket and counts against its
 * conversation. Beacon maps a conversation's context to the tenant's bucket scope.
 *
 * Asked at every upload, after ChatKit checked that the uploader may write in the conversation. Who may **read** a file
 * is never decided here: ChatKit checks the reader's membership on every download.
 */
interface ChatAttachmentScopeResolverInterface
{
    /**
     * @param ChatComposerScope $scope The uploader and the conversation (`contextKey`: the client API context, or null)
     *
     * @return ChatStorageTarget The bucket scope and owner
     */
    public function targetFor(ChatComposerScope $scope): ChatStorageTarget;
}
