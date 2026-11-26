<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Card;

use Nowo\ChatKitBundle\Contract\ChatCardProviderInterface;
use Nowo\ChatKitBundle\Enum\ChatCardState;
use Nowo\ChatKitBundle\Model\ChatCard;
use Nowo\ChatKitBundle\Model\ChatCardScope;
use RuntimeException;

/**
 * A card provider that answers 50 ids per call (plus one it was not asked and one that is no card) and fails on demand.
 */
final class FlakyCardProvider implements ChatCardProviderInterface
{
    /** @var list<list<string>> */
    public array $batches = [];

    public bool $fail = false;

    public function __construct(private readonly string $type)
    {
    }

    public function key(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        $this->check();

        return 'Vault item';
    }

    public function icon(): string
    {
        return 'key';
    }

    public function isSecret(): bool
    {
        $this->check();

        return true;
    }

    public function canShare(ChatCardScope $authorScope, string $id): bool
    {
        $this->check();

        return true;
    }

    public function render(ChatCardScope $readerScope, array $ids): array
    {
        $this->check();
        $this->batches[] = $ids;
        $cards           = ['zz' => new ChatCard(ChatCardState::Open, 'Not asked')];
        foreach ($ids as $id) {
            $cards[(int) $id === 0 ? $id : (int) $id] = new ChatCard(ChatCardState::Open, 'Card ' . $id);
        }
        $cards['1'] = 'not a card';

        // @phpstan-ignore return.type (a wrong answer, on purpose)
        return $cards;
    }

    private function check(): void
    {
        if ($this->fail) {
            throw new RuntimeException('provider down: v1');
        }
    }
}
