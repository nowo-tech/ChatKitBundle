<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Names starting with "Disabled" are unreachable, "Agent" answer support, "Stranger" can only message agents.
 */
final class TestAccessPolicy implements ChatAccessPolicyInterface
{
    public function isReachable(ChatUserInterface $user): bool
    {
        return !str_starts_with($user->getChatDisplayName(), 'Disabled');
    }

    public function isSupportAgent(ChatUserInterface $user): bool
    {
        return str_starts_with($user->getChatDisplayName(), 'Agent');
    }

    public function canMessage(ChatUserInterface $actor, ChatUserInterface $other): bool
    {
        $stranger = static fn (ChatUserInterface $user): bool => str_starts_with($user->getChatDisplayName(), 'Stranger');

        return $this->isSupportAgent($actor) || !($stranger($actor) || $stranger($other));
    }
}
