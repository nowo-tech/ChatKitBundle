<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelMentionPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelWritePolicyInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * One channel, `project:acme` ("Acme"), for everyone except names starting with "Stranger".
 *
 * 1.24: also `room:archive` (read only, with a subtitle and an icon; never listed, opened by key), and the optional
 * policies: names starting with "Reader" read but never write, names starting with "Lead" may use @channel / @here.
 */
final class TestChannelProvider implements ChatChannelProviderInterface, ChatChannelWritePolicyInterface, ChatChannelMentionPolicyInterface
{
    public const string KEY = 'project:acme';

    public const string ARCHIVE_KEY = 'room:archive';

    /** The member count of the last canMentionAll() call. */
    public ?int $lastMemberCount = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function channelsFor(ChatUserInterface $user): array
    {
        $channel = new ChatChannel(self::KEY, 'Acme');

        return $this->canUse($user, $channel) ? [$channel] : [];
    }

    public function find(string $key): ?ChatChannel
    {
        return match ($key) {
            self::KEY         => new ChatChannel(self::KEY, 'Acme'),
            self::ARCHIVE_KEY => new ChatChannel(self::ARCHIVE_KEY, 'Archive', true, "Old\u{202E} work\n", '📦'),
            default           => null,
        };
    }

    /** Pages under /projects/acme offer the channel. */
    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel
    {
        return str_starts_with($request->getPathInfo(), '/projects/acme') ? $this->find(self::KEY) : null;
    }

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return ($channel->key === self::KEY || $channel->key === self::ARCHIVE_KEY) && !str_starts_with($user->getChatDisplayName(), 'Stranger');
    }

    public function members(ChatChannel $channel): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(TestUser::class)->findAll(),
            fn (TestUser $user): bool => $this->canUse($user, $channel),
        ));
    }

    public function canWrite(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return !str_starts_with($user->getChatDisplayName(), 'Reader');
    }

    public function canMentionAll(ChatUserInterface $user, ChatChannel $channel, int $memberCount): bool
    {
        $this->lastMemberCount = $memberCount;

        return str_starts_with($user->getChatDisplayName(), 'Lead');
    }
}
