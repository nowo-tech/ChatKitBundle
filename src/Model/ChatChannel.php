<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * A group conversation defined by the application (e.g. one per project): a stable key and a label.
 */
final readonly class ChatChannel
{
    /**
     * Builds a channel from its stable key and its display label.
     *
     * @param string $key Stable, unique key such as `project:<uuid>`
     * @param string $label Human-readable channel name
     */
    public function __construct(
        /** Stable, unique key such as `project:<uuid>` (max. 120 characters). */
        public string $key,
        public string $label,
    ) {
    }
}
