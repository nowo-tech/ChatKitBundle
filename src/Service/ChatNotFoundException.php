<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use InvalidArgumentException;

/**
 * 1.27: ChatMessagingInterface was given a conversation or message uuid that names no row (compared byte for byte).
 * Nothing was written.
 */
final class ChatNotFoundException extends InvalidArgumentException
{
    /**
     * @param string $what `conversation` or `message`
     * @param string $uuid The uuid asked for
     */
    public function __construct(
        public readonly string $what,
        public readonly string $uuid,
    ) {
        parent::__construct('not_found');
    }
}
