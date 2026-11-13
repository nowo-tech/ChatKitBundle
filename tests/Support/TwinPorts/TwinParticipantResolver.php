<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\TwinPorts;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;

/**
 * 1.27: the default resolver's answer through the framework-free twin ONLY: the signed-in TestUser, found by the id the
 * value carries and checked against its identifier (the public id the test firewall's provider loads it by).
 */
final readonly class TwinParticipantResolver implements ChatParticipantResolverInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function resolve(?ChatSecurityUser $user): ?ChatUserInterface
    {
        if (!$user instanceof ChatSecurityUser || $user->userClass !== TestUser::class || $user->id === null) {
            return null;
        }
        // By id: the identity map answers (the voter asks once per conversation, so no query per call).
        $found = $this->entityManager->find(TestUser::class, (int) $user->id);

        return $found instanceof TestUser && $found->getChatPublicId() === $user->identifier ? $found : null;
    }
}
