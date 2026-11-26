<?php

declare(strict_types=1);

namespace App\Chat;

use Nowo\ChatKitBundle\Contract\ChatMessageMenuSlotProviderInterface;
use Nowo\ChatKitBundle\Enum\ChatMenuSlotTarget;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatMenuSlot;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ChatKit 2.6 (spec 259): "Save to vault" on a text selection; the selection reaches the dialog in the browser only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DemoMenuSlots implements ChatMessageMenuSlotProviderInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function slots(ChatComposerScope $scope): iterable
    {
        yield new ChatMenuSlot('save_vault', $this->translator->trans('demo.cards.save'), 'key', ChatMenuSlotTarget::Selection, '/' . $this->translator->getLocale() . '/demo/cards/save');
    }
}
