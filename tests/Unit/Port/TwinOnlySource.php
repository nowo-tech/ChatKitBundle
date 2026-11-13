<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Port;

use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelWritePolicyInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatRequestContext;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ErasedChatUser;

/**
 * A DDD host's channel source with the write companion (no mention policy).
 */
final class TwinOnlySource implements ChatChannelSourceInterface, ChatChannelWritePolicyInterface
{
    public ChatChannel $channel;

    public ?ChatRequestContext $lastPage = null;

    public function __construct()
    {
        $this->channel = new ChatChannel('project:a', 'A');
    }

    public function channelsFor(ChatUserInterface $user): array
    {
        return [$this->channel];
    }

    public function find(string $key): ?ChatChannel
    {
        return $key === 'project:a' ? $this->channel : null;
    }

    public function contextChannel(ChatUserInterface $user, ChatRequestContext $page): ?ChatChannel
    {
        $this->lastPage = $page;

        return $page->path === '/projects/a' && $page->query === ['tab' => 'chat'] ? $this->channel : null;
    }

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return true;
    }

    public function members(ChatChannel $channel): array
    {
        return [new ErasedChatUser(1, 'x', 'X')];
    }

    public function canWrite(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return false;
    }
}
