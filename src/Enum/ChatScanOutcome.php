<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

/**
 * 2.7 (spec 259): the answer of the host's ChatAttachmentScannerInterface for one file. Kept bundle-neutral so the
 * scanner port can move to StorageKit later (Q-259-3 C). There is no fail-open answer: anything but Clean keeps the
 * file away from every reader.
 */
enum ChatScanOutcome: string
{
    /** Nothing found: the file may be sent. */
    case Clean = 'clean';

    /** A signature matched: the file is blocked and its objects removed. */
    case Infected = 'infected';

    /** The engine cannot inspect it (an encrypted archive or PDF): blocked unless `scan.unscannable: allow`. */
    case Unscannable = 'unscannable';

    /** Try again later (the engine is busy or unreachable); after `scan.max_attempts` the file is `failed`. */
    case Retry = 'retry';
}
