<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * One channel, `project:acme` ("Acme"), for everyone except names starting with "Stranger".
 */
final readonly class TestChannelProvider implements ChatChannelProviderInterface
{
    public const string KEY = 'project:acme';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function channelsFor(ChatUserInterface $user): array
    {
        $channel = new ChatChannel(self::KEY, 'Acme');

        return $this->canUse($user, $channel) ? [$channel] : [];
    }

    public function find(string $key): ?ChatChannel
    {
        return $key === self::KEY ? new ChatChannel(self::KEY, 'Acme') : null;
    }

    /** Pages under /projects/acme offer the channel. */
    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel
    {
        return str_starts_with($request->getPathInfo(), '/projects/acme') ? $this->find(self::KEY) : null;
    }

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return $channel->key === self::KEY && !str_starts_with($user->getChatDisplayName(), 'Stranger');
    }

    public function members(ChatChannel $channel): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(TestUser::class)->findAll(),
            fn (TestUser $user): bool => $this->canUse($user, $channel),
        ));
    }
}
