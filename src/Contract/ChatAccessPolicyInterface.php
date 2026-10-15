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
    /** Active account that can use the chat at all (disabled / anonymised accounts: false). */
    public function isReachable(ChatUserInterface $user): bool;

    /** Answers the support inbox, may resolve threads and moderate channels / support threads. */
    public function isSupportAgent(ChatUserInterface $user): bool;

    /** May start a direct conversation (and keep writing in it) with `$other`. */
    public function canMessage(ChatUserInterface $actor, ChatUserInterface $other): bool;
}
