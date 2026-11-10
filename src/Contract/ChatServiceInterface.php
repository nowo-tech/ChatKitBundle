<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Service\ChatChannelDeniedException;

/**
 * 1.24: the chat's application service (conversations and messages) as a port. ChatService is the default
 * implementation, aliased to this interface; the bundle's controllers and services depend on the interface, so a host
 * can decorate it (audit, projections into its own model) or replace it in tests. Authorization stays the caller's job
 * (ChatVoter / ChatAccess), exactly as with ChatService.
 */
interface ChatServiceInterface
{
    /**
     * Returns the direct conversation of the two users, creating it (with both participant rows) on first use.
     *
     * @param ChatUserInterface $actor The user who opens it
     * @param ChatUserInterface $other The other person
     *
     * @return ChatConversation The direct conversation
     */
    public function openDirect(ChatUserInterface $actor, ChatUserInterface $other): ChatConversation;

    /**
     * Returns the channel's conversation (created on first use) and makes sure the actor has a participant row. This is
     * the only place a channel conversation is created: ChatChannelOpeningEvent (1.21) runs before it, in the same
     * transaction, once per attempt, and a channel already in use always opens.
     *
     * @param ChatUserInterface $actor The user who opens it
     * @param ChatChannel $channel The application channel
     *
     * @throws ChatChannelDeniedException When a listener refuses to create it; nothing is written
     *
     * @return ChatConversation The channel conversation
     */
    public function openChannel(ChatUserInterface $actor, ChatChannel $channel): ChatConversation;

    /**
     * The owner's latest support conversation (created on first use). `$context` (`url`, `label`) records where the
     * problem was reported from, for the agents. Since 1.8 the dock opens support through ChatSupportTickets::open()
     * (active ticket or the intake draft).
     *
     * @param ChatUserInterface $owner The user asking for support
     * @param array{url?: mixed, label?: mixed}|null $context Page the request comes from; null keeps the stored one
     *
     * @return ChatConversation The support thread
     */
    public function openSupport(ChatUserInterface $owner, ?array $context = null): ChatConversation;

    /**
     * Stores a new message (rendered to HTML) with its validated #references (ChatReferences) and its @mentions of conversation members, bumps the conversation
     * activity, moves the support status (the owner's message reopens a resolved ticket and turns a waiting one open;
     * an agent's reply turns an open ticket to waiting for the user), notifies and dispatches ChatMentionEvent when
     * it mentions someone.
     *
     * @param ChatUserInterface $author The writer
     * @param ChatConversation $conversation The conversation
     * @param string $body Raw Markdown text
     *
     * @throws InvalidArgumentException `empty`, `too_long` or `invalid_link` (1.19: a link card with a link that is not accepted)
     *
     * @return ChatMessage The stored message
     */
    public function send(ChatUserInterface $author, ChatConversation $conversation, string $body): ChatMessage;

    /**
     * A nudge, stored as a `buzz` line so offline recipients see it too.
     *
     * @param ChatUserInterface $author The user who buzzes
     * @param ChatConversation $conversation The conversation
     *
     * @return ChatMessage The stored buzz message
     */
    public function buzz(ChatUserInterface $author, ChatConversation $conversation): ChatMessage;

    /**
     * Replaces the message text (re-rendered) and its @mentions, marks it edited, notifies the recipients and
     * dispatches ChatMentionEvent for the people the edit mentions for the first time.
     *
     * @param ChatMessage $message The message to edit
     * @param string $body New raw Markdown text
     *
     * @throws InvalidArgumentException `empty`, `too_long` or `invalid_link`
     */
    public function edit(ChatMessage $message, string $body): void;

    /**
     * Turns the message into a tombstone, removes its reactions, notifies and dispatches ChatMessageDeletedEvent
     * (moderated when the deleter is not the author).
     *
     * @param ChatUserInterface $by The user who deletes it
     * @param ChatMessage $message The message
     */
    public function delete(ChatUserInterface $by, ChatMessage $message): void;

    /**
     * Adds the reaction, or removes it when the user had already reacted with that emoji.
     *
     * @param ChatUserInterface $user The reacting user
     * @param ChatMessage $message The message
     * @param string $emoji One of the offered emojis
     *
     * @throws InvalidArgumentException `reaction` when the emoji is not offered
     *
     * @return array<string, list<string>> the message's reactions after the change
     */
    public function toggleReaction(ChatUserInterface $user, ChatMessage $message, string $emoji): array;

    /**
     * Marks the support thread resolved by the agent, notifies and dispatches ChatSupportStatusChangedEvent.
     *
     * @param ChatUserInterface $agent The support agent
     * @param ChatConversation $conversation The support thread
     */
    public function resolveSupport(ChatUserInterface $agent, ChatConversation $conversation): void;

    /**
     * An agent reopens a resolved support thread (no-op when it is open).
     *
     * @param ChatUserInterface $agent The support agent
     * @param ChatConversation $conversation The support thread
     */
    public function reopenSupport(ChatUserInterface $agent, ChatConversation $conversation): void;

    /**
     * Moves the user's read cursor forward to the message (marking the user's mentions up to it read) and publishes
     * the read receipt when it moved.
     *
     * @param ChatUserInterface $user The reader
     * @param ChatConversation $conversation The conversation
     * @param int $messageId Id of the last message read
     *
     * @return bool True when the cursor moved (it never goes back)
     */
    public function markRead(ChatUserInterface $user, ChatConversation $conversation, int $messageId): bool;

    /**
     * Participant row of the user, created on first use (channels, support agents in support threads).
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $user The user
     *
     * @return ChatParticipant The existing or newly persisted (not flushed) row
     */
    public function participant(ChatConversation $conversation, ChatUserInterface $user): ChatParticipant;

    /**
     * Pins a message to the top of its conversation (1.10) and posts the "X pinned a message" line, or unpins it.
     * Access (ChatAccess::canPin) is the caller's job.
     *
     * @param ChatUserInterface $user Who pins or unpins
     * @param ChatMessage $message A text message, not deleted
     * @param bool $pinned True to pin, false to unpin
     *
     * @throws InvalidArgumentException `not_pinnable` for lines, bot messages and tombstones; `too_many_pins` over the limit
     */
    public function pin(ChatUserInterface $user, ChatMessage $message, bool $pinned): void;

    /**
     * 1.24: posts a system line in the conversation (no author: e.g. "Ana renamed the channel"), bumps its activity and
     * tells its readers. Server only: no API route posts one, so call it from your own use cases after your own access
     * checks. `$body` is a translation key of `$domain` translated for each reader (plain text when `$domain` is null);
     * `$params` fill its `%name%` placeholders. The dock shows it as text, never as HTML.
     *
     * @param ChatConversation $conversation The conversation
     * @param string $body Translation key, or plain text when `$domain` is null (at most ChatMessage::MAX_LENGTH characters)
     * @param string|null $domain Translation domain of the key; null for plain text
     * @param array<string, string> $params Translation parameters (`name` => value, without the `%`)
     *
     * @throws InvalidArgumentException `empty` or `too_long`
     *
     * @return ChatMessage The stored system line
     */
    public function postSystemLine(ChatConversation $conversation, string $body, ?string $domain = null, array $params = []): ChatMessage;

    /**
     * 1.24: mutes the conversation for the user until the given time (no Web Push, no e-mail digest, left out of
     * group mentions, not counted in the dock's total badge, no sound; its own unread count stays), or unmutes it with
     * null. Unmuting a conversation the user has no participant row in changes nothing (audit KC-L4: no row is created). Access is the caller's job (ChatVoter::VIEW).
     *
     * @param ChatUserInterface $user The user
     * @param ChatConversation $conversation The conversation
     * @param DateTimeImmutable|null $until Mute end; null to unmute
     *
     * @return ChatParticipant|null The user's participant row; null when unmuting without one
     */
    public function mute(ChatUserInterface $user, ChatConversation $conversation, ?DateTimeImmutable $until): ?ChatParticipant;

    /**
     * Closes (archives) conversations in the user's list, or reopens them; only for that user, nothing is deleted.
     *
     * @param ChatUserInterface $user The user
     * @param list<ChatConversation> $conversations The conversations
     * @param bool $archived True to close, false to reopen
     */
    public function archive(ChatUserInterface $user, array $conversations, bool $archived): void;
}
