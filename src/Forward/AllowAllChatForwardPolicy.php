<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Forward;

use Nowo\ChatKitBundle\Contract\ChatForwardPolicyInterface;
use Nowo\ChatKitBundle\Model\ChatForwardRequest;

/**
 * 1.36: the default {@see ChatForwardPolicyInterface}: ChatKit's own checks decide. Stateless.
 */
final readonly class AllowAllChatForwardPolicy implements ChatForwardPolicyInterface
{
    /**
     * Allows every forward ChatKit's checks allowed.
     *
     * @param ChatForwardRequest $request Ignored
     */
    public function mayForward(ChatForwardRequest $request): bool
    {
        return true;
    }
}
