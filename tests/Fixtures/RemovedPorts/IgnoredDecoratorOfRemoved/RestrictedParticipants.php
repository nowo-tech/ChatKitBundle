<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\IgnoredDecoratorOfRemoved;

use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered, loadable): a host restriction moved to the twin's interface but still decorating the
 * removed id with `onInvalid: IGNORE` (Symfony would remove it silently: the restriction would be lost).
 */
#[AsDecorator('Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class RestrictedParticipants implements ChatParticipantResolverInterface
{
    public function resolve(?ChatSecurityUser $user): ?ChatUserInterface
    {
        return null;
    }
}
