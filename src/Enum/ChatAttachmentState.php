<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

/**
 * 2.7 (spec 259 block B): the life of a chat attachment. A file is uploaded as a draft of its uploader, scanned by the
 * host's scanner (mandatory, fail-closed) and only a `clean` file can be sent; nobody but the uploader ever sees a file
 * in another state. Every transition is a compare-and-set on the row (docs/CONCURRENCY.md).
 */
enum ChatAttachmentState: string
{
    /** Stored, waiting for its scan (or for a retry after a scanner failure). */
    case Uploaded = 'uploaded';

    /** A scan worker holds the lease (`lease_until`, `state_token`). */
    case Scanning = 'scanning';

    /** The scanner said clean: it can be sent, and once sent every reader of the message sees it. */
    case Clean = 'clean';

    /** The scanner found something (or could not inspect it and `scan.unscannable` is `block`), or the host blocked it. */
    case Blocked = 'blocked';

    /** The scanner kept failing (`scan.max_attempts`): never served; the uploader removes it. */
    case Failed = 'failed';

    /** Removed (draft removed, message deleted or purged, person erased): its objects go after the commit. */
    case Deleted = 'deleted';
}
