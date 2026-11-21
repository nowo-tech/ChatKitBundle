<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Port;

use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0: the current-user resolver as the bundle asks it inside (the 1.x `CurrentChatUserResolverInterface`, removed in 2.0). Not a host
 * extension point: applications implement ChatParticipantResolverInterface (the bundle wraps it in its gate, {@see Ports}); the bundle's
 * own defaults implement this interface directly.
 *
 * @internal
 */
interface ChatCurrentUserPort
{
    /**
     * The chat participant of the signed-in user.
     *
     * @param UserInterface|null $user The security user (null for anonymous requests)
     *
     * @return ChatUserInterface|null The participant; null when this user has no chat
     */
    public function resolve(?UserInterface $user): ?ChatUserInterface;
}
