<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\SearchKit;

use Nowo\ChatKitBundle\Tests\Support\SearchKit\MessageSearchContractCase;

/**
 * 1.38: the SearchKit contract of the "Messages" group on in-memory SQLite.
 */
final class ChatMessageSearchContractTest extends MessageSearchContractCase
{
    protected function realDatabase(): bool
    {
        return false;
    }
}
