<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatComposerScope;

/**
 * 2.7 (spec 259 FR-010, owner decision 2026-11-26): may this person attach files in this conversation? Optional
 * (`attachments.policy`). Without it, whoever may write in a conversation may attach files there (the default). With
 * it, attaching is **denied unless this port allows it** — Beacon maps it to its permission catalogue ("attach files"
 * per role, deny by default).
 *
 * Asked on every upload and again on every send that carries files, after ChatKit checked that the person may write
 * there; a refusal is `403 attachments_not_allowed` on both. Resolve it live on each call (FrankenPHP worker mode: no
 * memo across requests). Any exception counts as a refusal.
 */
interface ChatAttachmentPolicyInterface
{
    /**
     * @param ChatComposerScope $scope The person and the conversation (`contextKey`: the client API context, or null on the web)
     *
     * @return bool True to allow attaching files here
     */
    public function mayAttach(ChatComposerScope $scope): bool;
}
