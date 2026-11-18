<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;

/**
 * 1.35: the richer message menu by public uuid, for DDD hosts that keep the bundle's entities out of their code (the
 * same rules as the API: ChatVoter SAVE, MARK_UNREAD, INFO). A conversation or message the actor may not use is
 * reported exactly like an unknown one.
 */
interface ChatMessageActionsInterface
{
    /**
     * Saves or unsaves a message for the actor (idempotent).
     *
     * @param ChatUserInterface $actor The user
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $messageUuid Public uuid of the message
     * @param bool $saved True to save, false to unsave
     *
     * @throws ChatNotFoundException When the conversation or the message is unknown or not allowed (a line or a tombstone
     *                               included: the SAVE voter attribute refuses them)
     * @throws InvalidArgumentException `too_many_saved` when the actor already saved `message_menu.save.max_per_user` messages
     *                                  (`not_savable` when a caller of ChatMessageActions passes a line or a tombstone)
     *
     * @return bool Whether the message is saved now
     */
    public function markSaved(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, bool $saved): bool;

    /**
     * A page of the actor's Saved list, newest save first (messages the actor may still read, never deleted ones).
     *
     * @param ChatUserInterface $actor The user
     * @param int|null $before The `next` cursor of the previous page; null for the first page
     *
     * @return array{items: list<array{savedAt: string, message: ChatMessageView}>, next: int|null}
     */
    public function savedMessages(ChatUserInterface $actor, ?int $before = null): array;

    /**
     * "Mark unread from here": the actor's read marker moves to just before the message.
     *
     * @param ChatUserInterface $actor The user
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $messageUuid Public uuid of the message
     *
     * @throws ChatNotFoundException When the conversation or the message is unknown or not allowed
     *
     * @return int|null The read marker now (null: nothing read)
     */
    public function markUnreadFrom(ChatUserInterface $actor, string $conversationUuid, string $messageUuid): ?int;

    /**
     * Message info: readers (and read times, reciprocal) up to `per_person_max_members` people, counts above.
     *
     * @param ChatUserInterface $actor The user
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $messageUuid Public uuid of the message
     *
     * @throws ChatNotFoundException When the conversation or the message is unknown or not allowed
     *
     * @return array{mode: string, memberCount: int, readCount: int, readTimes: bool, readers: list<array{uuid: string, name: string, initials: string, avatarUrl: string|null, read: bool, readAt: string|null}>, editedAt: string|null}
     */
    public function messageInfo(ChatUserInterface $actor, string $conversationUuid, string $messageUuid): array;
}
