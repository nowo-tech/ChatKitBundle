<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\TwinPorts;

use Nowo\ChatKitBundle\Contract\ChatChannelMentionPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelWritePolicyInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatRequestContext;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;

/**
 * 1.27: the test channel provider behind the framework-free twin ONLY (plus the companions, as a DDD host writes them):
 * same answers as TestChannelProvider; the page channel is decided from ChatRequestContext::$path.
 */
final readonly class TwinChannelSource implements ChatChannelSourceInterface, ChatChannelWritePolicyInterface, ChatChannelMentionPolicyInterface
{
    public function __construct(private TestChannelProvider $provider)
    {
    }

    public function channelsFor(ChatUserInterface $user): array
    {
        return $this->provider->channelsFor($user);
    }

    public function find(string $key): ?ChatChannel
    {
        return $this->provider->find($key);
    }

    public function contextChannel(ChatUserInterface $user, ChatRequestContext $page): ?ChatChannel
    {
        return str_starts_with($page->path, '/projects/acme') ? $this->find(TestChannelProvider::KEY) : null;
    }

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return $this->provider->canUse($user, $channel);
    }

    public function members(ChatChannel $channel): array
    {
        return $this->provider->members($channel);
    }

    public function canWrite(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return $this->provider->canWrite($user, $channel);
    }

    public function canMentionAll(ChatUserInterface $user, ChatChannel $channel, int $memberCount): bool
    {
        return $this->provider->canMentionAll($user, $channel, $memberCount);
    }
}
