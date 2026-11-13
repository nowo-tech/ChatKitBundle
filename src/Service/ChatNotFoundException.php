<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use InvalidArgumentException;

/**
 * 1.27: ChatMessagingInterface was given a conversation or message uuid that names no row (compared byte for byte),
 * or one the actor may not reach (audit OJ-M2: forbidden and unknown are the same answer, never an existence oracle).
 * Nothing was written.
 */
final class ChatNotFoundException extends InvalidArgumentException
{
    /**
     * @param string $what `conversation`, `message`, `user` (openDirect / openSupport) or `channel` (openChannel)
     * @param string $uuid The uuid (or public id, or channel key) asked for
     */
    public function __construct(
        public readonly string $what,
        public readonly string $uuid,
    ) {
        parent::__construct('not_found');
    }
}
