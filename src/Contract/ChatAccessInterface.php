<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 2.2 (DDD-host checklist B1/B8, REQ-ARCH-001): whether a user may view, post in, pin in or manage the links of a
 * conversation, and view or handle a support ticket, asked by ids. A host that keeps ChatKit's entities out of its code
 * (private entity manager, DDD / hexagonal) checks access without injecting the concrete Security\ChatAccess and without
 * loading a ChatConversation (aliased to Security\ChatUuidAccess).
 *
 * The user is a ChatUserInterface, or the user's public id (ChatUserInterface::getChatPublicId()) looked up through
 * ChatDirectoryInterface::findByPublicId(); the directory's answer counts only when its public id is exactly the one
 * asked for (byte for byte).
 *
 * The rules are the JSON API's (ChatVoter, Security\ChatAccess): reachability and the support-agent role come from
 * ChatAccessPolicyInterface, channel access and channel writes from the channel provider (ChatChannelSourceInterface and
 * its ChatChannelWritePolicyInterface), pins and links from ChatPinPolicyInterface / ChatLinkPolicyInterface. Those
 * host ports can only narrow an answer: every check first needs the conversation's own rule (one of the two people of a
 * direct conversation, the requester or a support agent for a support thread, a channel the provider lets the user use),
 * so a policy never grants a conversation outside that scope. Participant rows alone never grant access.
 *
 * Uuids compare exactly: in SQL and again in PHP (house rule LQ), so a look-alike uuid (other case, padded, folded)
 * never stands for another conversation. An unknown uuid, a look-alike, an unusable user id, another person's
 * conversation and a conversation the user may not reach all give the same answer (false / an empty list): the answer
 * tells nothing about which.
 *
 * Every call reads the conversation and asks the host ports again (answers of the host ports are memoized on the current
 * main request only, never in a service): nothing is kept between calls (FrankenPHP worker mode, kernel reset false).
 * An answer is a check at that moment (REQ-RUNTIME-003): the bundle's write services (ChatMessagingInterface,
 * ChatSupportInboxInterface) re-check under their own transaction instead of trusting it. A failure of the store, of a
 * host port or of the directory is logged (its class only) and answered as the same refusal, so an error never tells an
 * existing conversation from an unknown one.
 */
interface ChatAccessInterface
{
    /**
     * Whether the user may read the conversation now (ChatMessagingInterface::VIEW).
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param string $conversationUuid Public uuid of the conversation
     *
     * @return bool True when allowed
     */
    public function canView(ChatUserInterface|string $user, string $conversationUuid): bool;

    /**
     * Whether the user may write in the conversation now: send, buzz (ChatMessagingInterface::SEND; read-only channels
     * and closed tickets refuse).
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param string $conversationUuid Public uuid of the conversation
     *
     * @return bool True when allowed
     */
    public function canPost(ChatUserInterface|string $user, string $conversationUuid): bool;

    /**
     * Whether the user may pin and unpin messages of the conversation (ChatMessagingInterface::PIN).
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param string $conversationUuid Public uuid of the conversation
     *
     * @return bool True when allowed
     */
    public function canPin(ChatUserInterface|string $user, string $conversationUuid): bool;

    /**
     * Whether the user may add, change and remove the pinned links of the conversation (the Links panel's rule,
     * `links.channel_managers` included; it also needs the write access of canPost()).
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param string $conversationUuid Public uuid of the conversation
     *
     * @return bool True when allowed
     */
    public function canManageLinks(ChatUserInterface|string $user, string $conversationUuid): bool;

    /**
     * Whether the user may read the support ticket (a numbered support thread): its requester, or a support agent.
     * False for any conversation that is not a ticket (a direct or channel conversation, an intake draft).
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param string $ticketUuid Public uuid of the ticket
     *
     * @return bool True when allowed
     */
    public function canViewTicket(ChatUserInterface|string $user, string $ticketUuid): bool;

    /**
     * Whether the user may handle the support ticket as an agent: change its status, topic and assignee, resolve or
     * reopen it (ChatMessagingInterface::RESOLVE on a ticket). The requester is not, unless also an agent.
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param string $ticketUuid Public uuid of the ticket
     *
     * @return bool True when allowed
     */
    public function canHandleTicket(ChatUserInterface|string $user, string $ticketUuid): bool;

    /**
     * The asked conversations the user may read now, in one query (no N+1): only uuids that are exactly one asked for,
     * each at most once, in the order asked. Unknown, look-alike and forbidden uuids are left out alike. At most 500
     * uuids are checked; the rest are left out.
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param list<string> $conversationUuids Public uuids of the conversations
     *
     * @return list<string> The readable uuids
     */
    public function viewableConversationUuids(ChatUserInterface|string $user, array $conversationUuids): array;
}
