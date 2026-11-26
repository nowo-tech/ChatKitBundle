<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatMenuSlot;

/**
 * 2.6 (spec 259): host items of the message menu (Beacon: "Save to vault"). Tag the service
 * `nowo_chat_kit.message_menu_slot` (autoconfigured). Asked once when the person opens the conversation (not per
 * message): the dock offers each slot on the messages it fits (target, card types, own messages). The host dialog gets
 * `?conversation=&message=(&card=type:id)` and checks the person again; a selection reaches it in the browser only.
 */
interface ChatMessageMenuSlotProviderInterface
{
    /** Service tag of message menu slot providers. */
    public const string TAG = 'nowo_chat_kit.message_menu_slot';

    /**
     * @param ChatComposerScope $scope The reader and the conversation (`canSend`: whether they may write in it)
     *
     * @return iterable<ChatMenuSlot> The slots offered
     */
    public function slots(ChatComposerScope $scope): iterable;
}
