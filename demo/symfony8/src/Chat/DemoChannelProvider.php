<?php

declare(strict_types=1);

namespace App\Chat;

use App\Repository\UserRepository;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * One "General" channel for every demo account, offered as the page's channel on the home page.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DemoChannelProvider implements ChatChannelProviderInterface
{
    public const string KEY = 'demo:general';

    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function channelsFor(ChatUserInterface $user): array
    {
        return [$this->general()];
    }

    public function find(string $key): ?ChatChannel
    {
        return self::KEY === $key ? $this->general() : null;
    }

    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel
    {
        return 'homepage' === $request->attributes->get('_route') ? $this->general() : null;
    }

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return self::KEY === $channel->key;
    }

    public function members(ChatChannel $channel): array
    {
        return self::KEY === $channel->key ? $this->users->findAll() : [];
    }

    private function general(): ChatChannel
    {
        return new ChatChannel(self::KEY, 'General');
    }
}
