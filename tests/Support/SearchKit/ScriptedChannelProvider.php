<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\SearchKit;

use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * 1.38: channels a test scripts per person (SearchKit conversation group): `known` channels (find()), the keys listed
 * for each public id (channelsFor()) and the keys each public id may use (canUse()).
 */
final class ScriptedChannelProvider implements ChatChannelProviderInterface
{
    /** @var array<string, ChatChannel> key => channel */
    public array $known = [];

    /** @var array<string, list<string>> public id => listed keys */
    public array $listed = [];

    /** @var array<string, array<string, true>> public id => usable keys */
    public array $usable = [];

    public function add(ChatChannel $channel, ChatUserInterface $user, bool $listed = true, bool $usable = true): void
    {
        $this->known[$channel->key] = $channel;
        if ($listed) {
            $this->listed[$user->getChatPublicId()][] = $channel->key;
        }
        if ($usable) {
            $this->usable[$user->getChatPublicId()][$channel->key] = true;
        }
    }

    public function channelsFor(ChatUserInterface $user): array
    {
        $channels = [];
        foreach ($this->listed[$user->getChatPublicId()] ?? [] as $key) {
            $channels[] = $this->known[$key];
        }

        return $channels;
    }

    public function find(string $key): ?ChatChannel
    {
        return $this->known[$key] ?? null;
    }

    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel
    {
        return null;
    }

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return isset($this->usable[$user->getChatPublicId()][$channel->key]);
    }

    public function members(ChatChannel $channel): array
    {
        return [];
    }
}
