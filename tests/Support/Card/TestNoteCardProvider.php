<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Card;

use Nowo\ChatKitBundle\Contract\ChatCardProviderInterface;
use Nowo\ChatKitBundle\Enum\ChatCardState;
use Nowo\ChatKitBundle\Model\ChatCard;
use Nowo\ChatKitBundle\Model\ChatCardScope;

/**
 * `note` (not a secret): `n1` "Release notes" for everyone, shareable by anyone; numeric id `7` comes back as an int
 * key.
 */
final class TestNoteCardProvider implements ChatCardProviderInterface
{
    public function key(): string
    {
        return 'note';
    }

    public function label(): string
    {
        return 'Note';
    }

    public function icon(): string
    {
        return 'Bad Icon!';
    }

    public function isSecret(): bool
    {
        return false;
    }

    public function canShare(ChatCardScope $authorScope, string $id): bool
    {
        return true;
    }

    public function render(ChatCardScope $readerScope, array $ids): array
    {
        $cards = [];
        foreach ($ids as $id) {
            $cards[$id] = new ChatCard(ChatCardState::Open, $id === 'n1' ? 'Release notes' : 'Note ' . $id, 'Docs');
        }

        return $cards;
    }
}
