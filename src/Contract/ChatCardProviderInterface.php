<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatCard;
use Nowo\ChatKitBundle\Model\ChatCardScope;

/**
 * 2.6 (spec 259): one type of typed card a message may hold (Beacon: `vault_item`, `one_time_secret`). Tag the service
 * `nowo_chat_kit.card_provider` (autoconfigured). A card is stored as a reference token `#[…](type:id)` with the neutral
 * title `…` and an **opaque host id**: never a value, a key, a vault title or a link that holds a key. Permissions are
 * the provider's job and deny by default.
 *
 * - On send, canShare() is asked once per card (outside any lock); `false` refuses the whole send (`card_not_allowed`).
 * - At read time render() is asked per viewer, batched (at most 50 ids). An id left out of the answer is hidden: the
 *   neutral "Content not visible" chip. render() must have no side effect (it never burns a one-time secret).
 * - isSecret(): push, digest, list previews and search show a generic "🔑 Shared a secret" instead of the title.
 *
 * Both methods run in FrankenPHP worker mode: keep no per-request state between calls.
 */
interface ChatCardProviderInterface
{
    /** Service tag of card providers. */
    public const string TAG = 'nowo_chat_kit.card_provider';

    /**
     * Type key (`vault_item`): lowercase letters, digits, `_` or `-`, starting with a letter, at most 32 characters;
     * unique across card and reference providers.
     */
    public function key(): string;

    /**
     * Label of the card type, translated for the current request ("Vault item").
     */
    public function label(): string;

    /**
     * Dock icon of the card type (`key`, `lock` …).
     */
    public function icon(): string;

    /**
     * Whether the card stands for a secret: its title never leaves the reader's own card (generic push, digest,
     * previews and search line).
     */
    public function isSecret(): bool;

    /**
     * Whether the author may put this card in this conversation (send). Asked once per card and message, outside any
     * lock or transaction; deny by default.
     *
     * @param ChatCardScope $authorScope The author and the conversation
     * @param string $id The card id (opaque, matched byte for byte)
     */
    public function canShare(ChatCardScope $authorScope, string $id): bool;

    /**
     * The cards as the reader sees them (Open or Locked); ids the reader may not even know about are left out.
     *
     * @param ChatCardScope $readerScope The reader and the conversation
     * @param list<string> $ids At most 50 card ids
     *
     * @return array<array-key, ChatCard> id => card; unknown and hidden ids are left out
     */
    public function render(ChatCardScope $readerScope, array $ids): array;
}
