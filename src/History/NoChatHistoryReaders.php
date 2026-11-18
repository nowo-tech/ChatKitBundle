<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\History;

use Nowo\ChatKitBundle\Contract\ChatHistoryPolicyInterface;
use Nowo\ChatKitBundle\Model\ChatHistoryRequest;

/**
 * 1.36: the default {@see ChatHistoryPolicyInterface}: only the author and moderators read previous texts. Stateless.
 */
final readonly class NoChatHistoryReaders implements ChatHistoryPolicyInterface
{
    /**
     * Allows nobody beyond the author and moderators.
     *
     * @param ChatHistoryRequest $request Ignored
     */
    public function mayReadHistory(ChatHistoryRequest $request): bool
    {
        return false;
    }
}
