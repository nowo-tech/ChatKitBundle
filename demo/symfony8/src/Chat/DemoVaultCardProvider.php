<?php

declare(strict_types=1);

namespace App\Chat;

use App\Repository\UserRepository;
use Nowo\ChatKitBundle\Contract\ChatCardProviderInterface;
use Nowo\ChatKitBundle\Enum\ChatCardActionKind;
use Nowo\ChatKitBundle\Enum\ChatCardState;
use Nowo\ChatKitBundle\Model\ChatCard;
use Nowo\ChatKitBundle\Model\ChatCardAction;
use Nowo\ChatKitBundle\Model\ChatCardFact;
use Nowo\ChatKitBundle\Model\ChatCardScope;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ChatKit 2.6 (spec 259): a demo `vault_item` secret card. Two fixed items stand in for a vault: Alice and Bob may share
 * "Staging DB", only Alice "Prod API key". Bob sees "Prod API key" locked (Request access); Carol sees no card at all
 * (the neutral chip). No value is ever involved: the card is a reference.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DemoVaultCardProvider implements ChatCardProviderInterface
{
    /** id => [title, vault, who may share, who sees it locked] */
    public const array ITEMS = [
        'staging-db'   => ['Staging DB', 'Platform', ['alice', 'bob'], []],
        'prod-api-key' => ['Prod API key', 'Platform', ['alice'], ['bob']],
    ];

    public function __construct(
        private UserRepository $users,
        private TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return 'vault_item';
    }

    public function label(): string
    {
        return $this->translator->trans('demo.cards.vault_item');
    }

    public function icon(): string
    {
        return 'key';
    }

    public function isSecret(): bool
    {
        return true;
    }

    public function canShare(ChatCardScope $authorScope, string $id): bool
    {
        return \in_array($this->username($authorScope), self::ITEMS[$id][2] ?? [], true);
    }

    public function render(ChatCardScope $readerScope, array $ids): array
    {
        $username = $this->username($readerScope);
        if ($username === null || $username === 'carol') {
            return [];
        }
        $cards = [];
        foreach ($ids as $id) {
            if (!isset(self::ITEMS[$id])) {
                continue;
            }
            [$title, $vault, , $locked] = self::ITEMS[$id];
            $facts                      = [new ChatCardFact($this->translator->trans('demo.cards.vault'), $vault)];
            $cards[$id]                 = \in_array($username, $locked, true)
                ? new ChatCard(ChatCardState::Locked, $title, $vault, $this->translator->trans('demo.cards.locked'), 'warning', $facts, [
                    new ChatCardAction('request_access', $this->translator->trans('demo.cards.request_access'), ChatCardActionKind::RequestAccess, '/' . $this->translator->getLocale() . '/demo/cards/request?item=' . $id, true),
                ])
                : new ChatCard(ChatCardState::Open, $title, $vault, $this->translator->trans('demo.cards.shared'), 'success', $facts, [
                    new ChatCardAction('open', $this->translator->trans('demo.cards.open'), ChatCardActionKind::Open, '/' . $this->translator->getLocale() . '/demo/cards/request?item=' . $id, true),
                ]);
        }

        return $cards;
    }

    private function username(ChatCardScope $scope): ?string
    {
        return $this->users->findOneBy(['publicId' => $scope->viewerPublicId])?->getUserIdentifier();
    }
}
