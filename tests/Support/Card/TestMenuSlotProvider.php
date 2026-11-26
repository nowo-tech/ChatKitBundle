<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Card;

use Nowo\ChatKitBundle\Contract\ChatMessageMenuSlotProviderInterface;
use Nowo\ChatKitBundle\Enum\ChatMenuSlotTarget;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatMenuSlot;

/**
 * Three message menu slots: "Save to vault" (selection), "Card info" (vault cards) and "Report" (own messages).
 */
final class TestMenuSlotProvider implements ChatMessageMenuSlotProviderInterface
{
    public function slots(ChatComposerScope $scope): iterable
    {
        return [
            new ChatMenuSlot('save_vault', 'Save to vault', 'key', ChatMenuSlotTarget::Selection, '/host/save'),
            new ChatMenuSlot('card_info', 'Card info', 'key', ChatMenuSlotTarget::Card, '/host/card', ['vault_item']),
            new ChatMenuSlot('report', 'Report', 'flag', ChatMenuSlotTarget::Message, '/host/report', [], true),
        ];
    }
}
