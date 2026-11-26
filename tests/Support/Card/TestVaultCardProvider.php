<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Card;

use Nowo\ChatKitBundle\Contract\ChatCardProviderInterface;
use Nowo\ChatKitBundle\Enum\ChatCardActionKind;
use Nowo\ChatKitBundle\Enum\ChatCardState;
use Nowo\ChatKitBundle\Model\ChatCard;
use Nowo\ChatKitBundle\Model\ChatCardAction;
use Nowo\ChatKitBundle\Model\ChatCardFact;
use Nowo\ChatKitBundle\Model\ChatCardScope;

use function implode;
use function in_array;
use function str_starts_with;

/**
 * `vault_item` (a secret card): `v1` "Prod root DB" open to every member who is not a "Locked …" or "Hidden …" person
 * (locked: Request access; hidden: left out); `v2` shareable by Ana only; `nope` never shareable. Every call is recorded
 * (test double only: the tests read it, a kernel per test).
 */
final class TestVaultCardProvider implements ChatCardProviderInterface
{
    /** The sentinel a host dialog would hold: it must never reach ChatKit (SC-001). */
    public const string SENTINEL = 'S3NT1NEL-hunter2-value';

    /** @var list<array{string, string, string, string}> [method, viewerPublicId, ids, `web` or `api:<context>`] */
    public array $calls = [];

    /** @var array<string, string> public id => display name of the people the test registered */
    public array $names = [];

    public function key(): string
    {
        return 'vault_item';
    }

    public function label(): string
    {
        return 'Vault item';
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
        $this->calls[] = ['canShare', $authorScope->viewerPublicId, $id, self::where($authorScope)];
        $name          = $this->names[$authorScope->viewerPublicId] ?? '';

        return $id === 'v1' || ($id === 'v2' && $name === 'Ana');
    }

    public function render(ChatCardScope $readerScope, array $ids): array
    {
        $this->calls[] = ['render', $readerScope->viewerPublicId, implode(',', $ids), self::where($readerScope)];
        $name          = $this->names[$readerScope->viewerPublicId] ?? '';
        if (str_starts_with($name, 'Hidden')) {
            return [];
        }
        $cards = [];
        foreach (['v1', 'v2'] as $id) {
            if (!in_array($id, $ids, true)) {
                continue;
            }
            $cards[$id] = str_starts_with($name, 'Locked')
                ? new ChatCard(ChatCardState::Locked, 'Prod root DB', 'Project X vault', '', 'neutral', [new ChatCardFact('Vault', 'Project X')], [new ChatCardAction('request_access', 'Request access', ChatCardActionKind::RequestAccess, '/vault/' . $id . '/request', true)])
                : new ChatCard(ChatCardState::Open, 'Prod root DB', 'Project X vault', 'Shared', 'success', [new ChatCardFact('Vault', 'Project X'), new ChatCardFact('Expires', 'Never')], [new ChatCardAction('open', 'Open', ChatCardActionKind::Open, '/vault/' . $id)]);
        }
        // Something that was not asked is dropped by ChatKit.
        $cards['zz'] = new ChatCard(ChatCardState::Open, 'Not asked');

        return $cards;
    }

    private static function where(ChatCardScope $scope): string
    {
        return $scope->api ? 'api:' . ($scope->contextKey ?? '') : 'web';
    }
}
