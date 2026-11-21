<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\DecoratorOfParticipants;

use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered): a 1.x application decorator of the removed CurrentChatUserResolverInterface (the interface no longer exists, so the class cannot be
 * loaded; Symfony would drop it silently).
 */
#[AsDecorator('Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface')]
final class AuditedParticipants implements CurrentChatUserResolverInterface
{
    public function __construct(#[AutowireDecorated] private readonly CurrentChatUserResolverInterface $inner)
    {
    }

    public function resolve(?UserInterface $user): ?ChatUserInterface
    {
        return $this->inner->resolve($user);
    }
}
