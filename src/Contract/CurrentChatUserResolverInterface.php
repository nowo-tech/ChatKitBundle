<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.24: maps the signed-in Symfony security user to the chat participant. The default (SecurityChatUserResolver)
 * accepts a security user that implements ChatUserInterface itself — 1.23 behaviour. A host whose security user is a
 * DTO or a wrapper (common in hexagonal applications) aliases this interface to its own resolver, e.g. one that loads
 * the participant from ChatDirectoryInterface by the security user's identifier. Used by ChatVoter, the dock (Twig) and
 * every chat controller; keep it cheap (memoize per request; no state across requests in worker mode).
 */
interface CurrentChatUserResolverInterface
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
