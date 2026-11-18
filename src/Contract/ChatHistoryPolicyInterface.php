<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatHistoryRequest;

/**
 * 1.36: who else may read the previous texts of an edited message (`message_menu.edit_history`) — e.g. the managers of
 * a channel. The author and moderators (support agents outside direct conversations) always may, and only readers of
 * the conversation are asked. The default allows nobody else. Framework-free (REQ-ARCH-001 rule 3); keep no state
 * across requests (worker mode).
 */
interface ChatHistoryPolicyInterface
{
    /**
     * Whether the viewer may read the previous texts.
     *
     * @param ChatHistoryRequest $request The viewer and the message
     *
     * @return bool True when allowed
     */
    public function mayReadHistory(ChatHistoryRequest $request): bool;
}
