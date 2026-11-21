<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.27: framework-free twin of the 1.x CurrentChatUserResolverInterface (REQ-ARCH-001 rules 1 and 3): maps the
 * signed-in user, given as a ChatSecurityUser (class, id, identifier, roles) instead of Symfony's `UserInterface`, to the
 * chat participant, e.g. through the host's directory. 2.0: the only host interface of the current-user port
 * (CurrentChatUserResolverInterface was removed). Alias this interface to a service that implements it; the bundle asks
 * it through an internal gate. Without one the security user is the participant when it implements ChatUserInterface.
 * Keep it cheap and stateless across requests (FrankenPHP worker mode).
 */
interface ChatParticipantResolverInterface
{
    /**
     * The chat participant of the signed-in user.
     *
     * @param ChatSecurityUser|null $user The signed-in user (null for anonymous requests)
     *
     * @return ChatUserInterface|null The participant; null when this user has no chat
     */
    public function resolve(?ChatSecurityUser $user): ?ChatUserInterface;
}
