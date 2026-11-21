<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\AliasOfParticipants;

use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * 2.0 W1-1 fixture (autodiscovered): an application service moved to ChatParticipantResolverInterface but still aliased under the removed CurrentChatUserResolverInterface.
 */
#[AsAlias('Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface')]
final class HostParticipants implements ChatParticipantResolverInterface
{
    public function resolve(?ChatSecurityUser $user): ?ChatUserInterface
    {
        return null;
    }
}
