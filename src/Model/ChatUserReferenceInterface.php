<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 1.27 (REQ-ARCH-001 rule 2): a ChatUserInterface whose identifier is not an integer (a UUID, a ULID, an IdP subject)
 * implements this too. With `doctrine.user_reference: id` the bundle then stores, compares and looks users up by this
 * reference instead of `getId()` (which may return null): the user columns hold it, ChatUserReferenceDirectoryInterface
 * finds users by it, and every comparison is exact (REQ-SEC-006).
 *
 * The reference is compared byte for byte: `Bob`, `bob` and `bob ` are three different users. It must be 1 to 64
 * characters of valid UTF-8, with no whitespace at either end and no control or format characters (ZWSP, BOM, bidi
 * marks); the bundle refuses any other value before it writes (ChatUserReference::assertValid()). Only
 * `doctrine.user_reference: id` supports it (the `relation` mode keys rows by the user entity's integer id).
 */
interface ChatUserReferenceInterface
{
    /**
     * The user's stable identifier, exactly as the bundle stores it.
     *
     * @return string The reference (1–64 characters)
     */
    public function getChatUserReference(): string;
}
