<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use InvalidArgumentException;

/**
 * 2.8 (Beacon spec 260): tells open clients that a typed card changed after it was posted (a call card that now reads
 * "Call ended"), so every viewer renders it again with their own permissions. BC-promised; autowire it.
 *
 * - The hint is the existing `message_updated` event, sent for every message of the conversation that holds the card
 *   (thread replies included, deleted messages left out). It never carries card content: in `realtime_events: full` the
 *   message is presented without a viewer (card slots pending, the dock asks the server for this viewer's cards), in
 *   `ids` only its ids travel. The client API re-reads the message (`GET conversations/{uuid}/messages/{messageUuid}`),
 *   where ChatCardProviderInterface::render() runs for the caller.
 * - **Call it after the host's transaction commits.** ChatKit runs on its own connection and cannot see the host's
 *   transaction: a hint sent before the commit makes clients render the old state. Calling it inside ChatKit's own
 *   transaction is harmless (it only reads), but the same rule applies to the host data render() reads.
 * - Bounded: only the newest {@see self::SCAN_WINDOW} top-level messages of the conversation (and the thread replies
 *   posted since the oldest of them) are searched, and at most {@see self::MAX_MESSAGES} messages (the newest holders)
 *   are refreshed per call. Older messages render the new state on the next load.
 * - Do not call it while ChatKit's own unit of work holds unflushed changes to a message that holds the card: the
 *   holders are re-read from the database (`HINT_REFRESH`) and such changes would be discarded. ChatKit's services
 *   flush at once, so "after the commit" is enough.
 * - Scoped to one conversation: the host names the conversation the card was shared in (a card id is never looked up
 *   across conversations). An unknown conversation, an unregistered card type or a card no message holds sends nothing.
 * - Idempotent and stateless (FrankenPHP worker mode): calling it twice sends the hint twice, nothing else changes.
 *   It never throws for a database or hub failure (logged by class name, never ids); the clients' catch-up covers it.
 */
interface ChatCardUpdatesInterface
{
    /** Messages refreshed per call at most (the newest). */
    public const int MAX_MESSAGES = 20;

    /** Newest top-level messages of the conversation searched per call (with the thread replies posted since). */
    public const int SCAN_WINDOW = 1000;

    /**
     * Sends `message_updated` for the messages of the conversation that hold the card `#[…](type:id)`.
     *
     * @param string $type The card type key (ChatCardProviderInterface::key())
     * @param string $id The opaque card id, matched byte for byte
     * @param string $conversationUuid Public uuid of the conversation the card was shared in, matched byte for byte
     *
     * @throws InvalidArgumentException `invalid_card` when the type or the id does not have the card token's shape
     *
     * @return int How many messages were signalled (0 to MAX_MESSAGES)
     */
    public function changed(string $type, string $id, string $conversationUuid): int;
}
