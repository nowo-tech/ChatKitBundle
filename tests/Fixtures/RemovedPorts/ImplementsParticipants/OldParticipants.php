<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\ImplementsParticipants;

use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered): an application class that still implements only the removed resolver interface.
 */
final class OldParticipants implements CurrentChatUserResolverInterface
{
    public function resolve(?UserInterface $user): ?ChatUserInterface
    {
        return null;
    }
}
