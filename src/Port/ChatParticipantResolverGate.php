<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Port;

use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.27: asks a host ChatParticipantResolverInterface where the bundle asks its internal
 * ChatCurrentUserPort, with the security user copied into a ChatSecurityUser. Exceptions propagate.
 * Stateless.
 *
 * @internal built by {@see Ports}
 */
final readonly class ChatParticipantResolverGate implements ChatCurrentUserPort
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
