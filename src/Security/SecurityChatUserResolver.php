<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Security;

use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.24: default CurrentChatUserResolverInterface — the security user is the chat participant when it implements
 * ChatUserInterface (1.23 behaviour); anything else has no chat.
 */
final class SecurityChatUserResolver implements CurrentChatUserResolverInterface
{
    /**
     * The security user itself when it implements ChatUserInterface.
     *
     * @param UserInterface|null $user The security user
     *
     * @return ChatUserInterface|null The participant, or null
     */
    public function resolve(?UserInterface $user): ?ChatUserInterface
    {
        return $user instanceof ChatUserInterface ? $user : null;
    }
}
