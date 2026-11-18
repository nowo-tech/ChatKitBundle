<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatForwardRequest;

/**
 * 1.36: the application's veto on forwards (`message_menu.forward`), asked once per target after ChatKit's own checks
 * (VIEW on the source, SEND on the target) and before anything is written. The default allows every forward. A refused
 * target refuses the whole forward (403 `forward_forbidden`). Framework-free (REQ-ARCH-001 rule 3); keep no state
 * across requests (worker mode). ChatMessageForwardingEvent is the event-listener twin.
 */
interface ChatForwardPolicyInterface
{
    /**
     * Whether the forward to this target is allowed.
     *
     * @param ChatForwardRequest $request The source, the target and the actor
     *
     * @return bool True when allowed
     */
    public function mayForward(ChatForwardRequest $request): bool;
}
