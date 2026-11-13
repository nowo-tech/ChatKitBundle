<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ChatUserReferenceInterface;

/**
 * 1.27 (REQ-ARCH-001 rule 2): optional, implemented by the application's ChatDirectoryInterface when its users implement
 * ChatUserReferenceInterface (string identifiers) with `doctrine.user_reference: id`. The bundle then loads the users
 * behind the stored references through it instead of ChatDirectoryInterface::findByIds().
 *
 * Return users whose ChatUserReferenceInterface::getChatUserReference() is exactly one of the asked references (byte for
 * byte: no case, accent, padding or Unicode-normalisation folding). The bundle re-checks every answer with `===` and
 * ignores any other user (REQ-SEC-006 item 4).
 */
interface ChatUserReferenceDirectoryInterface
{
    /**
     * Loads users by their references; unknown references are left out.
     *
     * @param list<string> $references Stored references
     *
     * @return list<ChatUserInterface> The users found
     */
    public function findByReferences(array $references): array;
}
