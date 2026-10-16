<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Who can talk to whom. Evaluated on every request (never cached across requests by the bundle), so losing a
 * shared context turns a direct thread read-only immediately.
 */
interface ChatAccessPolicyInterface
{
    /**
     * Active account that can use the chat at all (disabled / anonymised accounts: false).
     *
     * @param ChatUserInterface $user The account to check
     *
     * @return bool True when the user can use the chat
     */
    public function isReachable(ChatUserInterface $user): bool;

    /**
     * Answers the support inbox, may resolve threads and moderate channels / support threads.
     *
     * @param ChatUserInterface $user The account to check
     *
     * @return bool True when the user is a support agent
     */
    public function isSupportAgent(ChatUserInterface $user): bool;

    /**
     * May start a direct conversation (and keep writing in it) with `$other`.
     *
     * @param ChatUserInterface $actor The user who wants to write
     * @param ChatUserInterface $other The intended recipient
     *
     * @return bool True when the actor may message the other user
     */
    public function canMessage(ChatUserInterface $actor, ChatUserInterface $other): bool;
}
