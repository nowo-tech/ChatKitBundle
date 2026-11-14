<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use LogicException;

/**
 * 1.28 (`doctrine.user_reference: string`): a chat row was about to be inserted for a user whose look-alike's row the
 * same request found in that table (a user column that folds behind a passed schema check). The insert would clash with
 * the look-alike's unique key inside the flush and close the entity manager, so it is refused before `persist()`.
 * Nothing was written. A LogicException, so callers that already catch one keep working.
 *
 * The message names the column and a keyed hash of the reference, never the reference itself (REQ-OBS-001).
 */
final class ChatUserLookAlikeException extends LogicException
{
}
