<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use DateTimeImmutable;

/**
 * 2.7 (spec 259): a short-lived download URL of one attachment, signed for one reader (and, on the client API, one
 * token and context). It expires at {@see self::$expiresAt}; every request re-checks the reader's access.
 */
final readonly class ChatAttachmentUrl
{
    /**
     * @param string $url Root-relative URL (`/chat/files/<uuid>/original?e=…&s=…`)
     * @param DateTimeImmutable $expiresAt When the signature expires
     */
    public function __construct(
        public string $url,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
