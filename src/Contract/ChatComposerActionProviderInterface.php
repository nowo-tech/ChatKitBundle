<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatComposerAction;
use Nowo\ChatKitBundle\Model\ChatComposerScope;

/**
 * 2.6 (spec 259): host actions of the composer (Beacon: "Share a vault item", "One-time secret"). Tag the service
 * `nowo_chat_kit.composer_action` (autoconfigured). Deny by default: return only what this person may use in this
 * conversation. Asked when the person opens the conversation (only when they may write in it).
 */
interface ChatComposerActionProviderInterface
{
    /** Service tag of composer action providers. */
    public const string TAG = 'nowo_chat_kit.composer_action';

    /**
     * @param ChatComposerScope $scope The writer and the conversation
     *
     * @return iterable<ChatComposerAction> The actions offered
     */
    public function actions(ChatComposerScope $scope): iterable;
}
