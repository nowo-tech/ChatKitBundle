<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\TwinPorts;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;

/**
 * 1.27: the default resolver's answer through the framework-free twin ONLY: the signed-in TestUser (its identifier is
 * the public id, as the test firewall's provider loads it) is the participant.
 */
final readonly class TwinParticipantResolver implements ChatParticipantResolverInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function resolve(?ChatSecurityUser $user): ?ChatUserInterface
    {
        if (!$user instanceof ChatSecurityUser || $user->userClass !== TestUser::class) {
            return null;
        }
        $found = $this->entityManager->getRepository(TestUser::class)->findOneBy(['publicId' => $user->identifier]);

        return $found instanceof TestUser && $found->getChatPublicId() === $user->identifier ? $found : null;
    }
}
