<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\User;
use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Every demo account can use the chat and message anyone; ROLE_SUPPORT answers the support inbox.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoChatAccessPolicy implements ChatAccessPolicyInterface
{
    public function isReachable(ChatUserInterface $user): bool
    {
        return $user instanceof User;
    }

    public function isSupportAgent(ChatUserInterface $user): bool
    {
        return $user instanceof User && $user->isSupportAgent();
    }

    public function canMessage(ChatUserInterface $actor, ChatUserInterface $other): bool
    {
        return $actor->getId() !== $other->getId();
    }
}
