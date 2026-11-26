<?php

declare(strict_types=1);

namespace App\Chat;

use Nowo\ChatKitBundle\Contract\ChatComposerActionProviderInterface;
use Nowo\ChatKitBundle\Model\ChatComposerAction;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ChatKit 2.6 (spec 259): "Share a vault item" (`/vault`) in the composer; its dialog inserts a `vault_item` card.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DemoComposerActions implements ChatComposerActionProviderInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function actions(ChatComposerScope $scope): iterable
    {
        yield new ChatComposerAction('vault', $this->translator->trans('demo.cards.share'), 'key', '/' . $this->translator->getLocale() . '/demo/cards/vault', 'vault');
    }
}
