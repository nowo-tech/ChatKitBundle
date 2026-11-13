<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Port;

use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.27: asks a host ChatParticipantResolverInterface where the bundle asks the deprecated
 * CurrentChatUserResolverInterface, with the security user copied into a ChatSecurityUser. Exceptions propagate.
 * Stateless.
 *
 * @internal built by {@see Ports}
 */
final readonly class ChatParticipantResolverGate implements CurrentChatUserResolverInterface
{
    /**
     * @param ChatParticipantResolverInterface $resolver The host's twin
     */
    public function __construct(public ChatParticipantResolverInterface $resolver)
    {
    }

    /**
     * The participant of the signed-in user.
     *
     * @param UserInterface|null $user The security user
     *
     * @return ChatUserInterface|null The resolver's answer
     */
    public function resolve(?UserInterface $user): ?ChatUserInterface
    {
        return $this->resolver->resolve(ChatRequestContexts::fromUser($user));
    }
}
