<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatParticipantView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Service\ChatChannelDeniedException;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;

/**
 * 1.27 (REQ-ARCH-001 rule 1): the chat's application service by id — the framework-free twin of ChatServiceInterface
 * for hosts that keep the bundle's entities out of their code (DDD / hexagonal). Conversations and messages are named by
 * their public uuids; the answers are read-only views (ChatConversationView, ChatMessageView, ChatParticipantView), never
 * an entity. The default implementation (ChatMessaging) loads the rows and calls ChatServiceInterface, so a decoration of
 * that interface applies here too, and the behaviour (events, notifications, validation) is the same.
 *
 * 1.27 (audit OJ-M2): every method that takes an actor authorizes it with the rules of the JSON API (ChatVoter /
 * ChatAccess: view, send, pin, edit and delete only the actor's own message within the edit window, resolve for support
 * agents, …). A conversation or message the actor may not reach throws ChatNotFoundException exactly like one that does
 * not exist, so the port is never an existence oracle. can() answers the same question by uuid without acting.
 * postSystemLine() has no actor: it is server-only, so check access in your own use case first.
 */
interface ChatMessagingInterface
{
    /** The actor may read the conversation. */
    public const string VIEW = 'view';

    /** The actor may write in it (send, buzz). */
    public const string SEND = 'send';

    /** The actor may moderate it (support agents in channels and support threads). */
    public const string MODERATE = 'moderate';

    /** The actor may resolve or reopen it (support agents, support threads). */
    public const string RESOLVE = 'resolve';

    /** The actor may pin and unpin its messages. */
    public const string PIN = 'pin';

    /** The actor may edit the message (their own, within the edit window). */
    public const string EDIT = 'edit';

    /** The actor may delete the message (their own, within the edit window). */
    public const string DELETE = 'delete';

    /** The actor may react to the message. */
    public const string REACT = 'react';

    /**
     * Whether the actor may do `$action` on the conversation (or, for EDIT, DELETE and REACT, on its message), by uuid:
     * the authorization of a DDD host that keeps the bundle's entities out of its code. False for an unknown uuid too.
     *
     * @param ChatUserInterface $actor The user
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $action One of the constants of this interface
     * @param string|null $messageUuid Public uuid of the message (EDIT, DELETE, REACT)
     *
     * @return bool True when allowed
     */
    public function can(ChatUserInterface $actor, string $conversationUuid, string $action, ?string $messageUuid = null): bool;

    /**
     * The direct conversation of the two users (created with both participant rows on first use).
     *
     * @param ChatUserInterface $actor The user who opens it
     * @param ChatUserInterface $other The other person
     *
     * @throws ChatNotFoundException When the actor may not message that person (`user`)
     *
     * @return ChatConversationView The conversation
     */
    public function openDirect(ChatUserInterface $actor, ChatUserInterface $other): ChatConversationView;

    /**
     * The channel's conversation (created on first use); the actor gets a participant row.
     *
     * @param ChatUserInterface $actor The user who opens it
     * @param ChatChannel $channel The application channel
     *
     * @throws ChatNotFoundException When the channel is unknown or the actor may not use it (`channel`)
     * @throws ChatChannelDeniedException When a ChatChannelOpeningEvent listener refuses to create it
     * @throws LogicException When the channel key breaks the ChatChannelKey format rule
     *
     * @return ChatConversationView The conversation
     */
    public function openChannel(ChatUserInterface $actor, ChatChannel $channel): ChatConversationView;

    /**
     * The owner's latest support conversation (created on first use).
     *
     * @param ChatUserInterface $owner The user asking for support
     * @param array{url?: mixed, label?: mixed}|null $context Page the request comes from; null keeps the stored one
     *
     * @throws ChatNotFoundException When the owner is not reachable (`user`)
     *
     * @return ChatConversationView The support thread
     */
    public function openSupport(ChatUserInterface $owner, ?array $context = null): ChatConversationView;

    /**
     * Stores a new message (see ChatServiceInterface::send()).
     *
     * @param ChatUserInterface $author The writer
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $body Raw Markdown text
     *
     * @throws ChatNotFoundException When no conversation has exactly this uuid or the actor may not do this there
     * @throws InvalidArgumentException `empty`, `too_long` or `invalid_link`
     *
     * @return ChatMessageView The stored message
     */
    public function send(ChatUserInterface $author, string $conversationUuid, string $body): ChatMessageView;

    /**
     * A nudge, stored as a `buzz` line.
     *
     * @param ChatUserInterface $author The user who buzzes
     * @param string $conversationUuid Public uuid of the conversation
     *
     * @throws ChatNotFoundException When no conversation has exactly this uuid or the actor may not do this there
     *
     * @return ChatMessageView The stored buzz
     */
    public function buzz(ChatUserInterface $author, string $conversationUuid): ChatMessageView;

    /**
     * Replaces the message text (see ChatServiceInterface::edit()); only the author, within the edit window.
     *
     * @param ChatUserInterface $actor The user who edits it
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $messageUuid Public uuid of the message (in that conversation)
     * @param string $body New raw Markdown text
     *
     * @throws ChatNotFoundException When the conversation or the message is unknown, or the actor may not do this there
     * @throws InvalidArgumentException `empty`, `too_long` or `invalid_link`
     *
     * @return ChatMessageView The message after the edit
     */
    public function edit(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, string $body): ChatMessageView;

    /**
     * Turns the message into a tombstone (see ChatServiceInterface::delete()).
     *
     * @param ChatUserInterface $by The user who deletes it
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $messageUuid Public uuid of the message
     *
     * @throws ChatNotFoundException When the conversation or the message is unknown, or the actor may not do this there
     */
    public function delete(ChatUserInterface $by, string $conversationUuid, string $messageUuid): void;

    /**
     * Adds the reaction, or removes it when the user had already reacted with that emoji.
     *
     * @param ChatUserInterface $user The reacting user
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $messageUuid Public uuid of the message
     * @param string $emoji One of the offered emojis
     *
     * @throws ChatNotFoundException When the conversation or the message is unknown, or the actor may not do this there
     * @throws InvalidArgumentException `reaction` when the emoji is not offered
     *
     * @return array<string, list<string>> the message's reactions after the change
     */
    public function toggleReaction(ChatUserInterface $user, string $conversationUuid, string $messageUuid, string $emoji): array;

    /**
     * Marks the support thread resolved by the agent.
     *
     * @param ChatUserInterface $agent The support agent
     * @param string $conversationUuid Public uuid of the support thread
     *
     * @throws ChatNotFoundException When no conversation has exactly this uuid or the actor may not do this there
     */
    public function resolveSupport(ChatUserInterface $agent, string $conversationUuid): void;

    /**
     * An agent reopens a resolved support thread (no-op when it is open).
     *
     * @param ChatUserInterface $agent The support agent
     * @param string $conversationUuid Public uuid of the support thread
     *
     * @throws ChatNotFoundException When no conversation has exactly this uuid or the actor may not do this there
     */
    public function reopenSupport(ChatUserInterface $agent, string $conversationUuid): void;

    /**
     * Moves the user's read cursor forward to the message.
     *
     * @param ChatUserInterface $user The reader
     * @param string $conversationUuid Public uuid of the conversation
     * @param int $messageId Id of the last message read (ChatMessageView::$id)
     *
     * @throws ChatNotFoundException When no conversation has exactly this uuid or the actor may not do this there
     *
     * @return bool True when the cursor moved
     */
    public function markRead(ChatUserInterface $user, string $conversationUuid, int $messageId): bool;

    /**
     * The user's participant row, created (and saved) on first use.
     *
     * @param string $conversationUuid Public uuid of the conversation
     * @param ChatUserInterface $user The user
     *
     * @throws ChatNotFoundException When no conversation has exactly this uuid or the actor may not do this there
     *
     * @return ChatParticipantView The row
     */
    public function join(string $conversationUuid, ChatUserInterface $user): ChatParticipantView;

    /**
     * Pins a message to the top of its conversation, or unpins it.
     *
     * @param ChatUserInterface $user Who pins or unpins
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $messageUuid Public uuid of the message
     * @param bool $pinned True to pin, false to unpin
     *
     * @throws ChatNotFoundException When the conversation or the message is unknown, or the actor may not do this there
     * @throws InvalidArgumentException `not_pinnable` or `too_many_pins`
     */
    public function pin(ChatUserInterface $user, string $conversationUuid, string $messageUuid, bool $pinned): void;

    /**
     * Posts a system line in the conversation (see ChatServiceInterface::postSystemLine()). Server-only and without an
     * actor: check access in your own use case first.
     *
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $body Translation key, or plain text when `$domain` is null
     * @param string|null $domain Translation domain of the key; null for plain text
     * @param array<string, string> $params Translation parameters (`name` => value, without the `%`)
     *
     * @throws ChatNotFoundException When no conversation has exactly this uuid or the actor may not do this there
     * @throws InvalidArgumentException `empty` or `too_long`
     *
     * @return ChatMessageView The stored system line
     */
    public function postSystemLine(string $conversationUuid, string $body, ?string $domain = null, array $params = []): ChatMessageView;

    /**
     * Mutes the conversation for the user until the given time, or unmutes it with null.
     *
     * @param ChatUserInterface $user The user
     * @param string $conversationUuid Public uuid of the conversation
     * @param DateTimeImmutable|null $until Mute end; null to unmute
     *
     * @throws ChatNotFoundException When no conversation has exactly this uuid or the actor may not do this there
     *
     * @return ChatParticipantView|null The user's participant row; null when unmuting without one
     */
    public function mute(ChatUserInterface $user, string $conversationUuid, ?DateTimeImmutable $until): ?ChatParticipantView;

    /**
     * Closes (archives) conversations in the user's list, or reopens them. Unknown uuids and conversations the user may
     * not read are left out.
     *
     * @param ChatUserInterface $user The user
     * @param list<string> $conversationUuids Public uuids of the conversations
     * @param bool $archived True to close, false to reopen
     */
    public function archive(ChatUserInterface $user, array $conversationUuids, bool $archived): void;
}
