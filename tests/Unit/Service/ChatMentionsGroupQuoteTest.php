<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Service;

use Nowo\ChatKitBundle\Service\ChatMentions;
use PHPUnit\Framework\TestCase;

/**
 * 1.35 (audit TK-5): a group mention inside a quote (`> …`, what Quote and Reply insert) is someone else's words and
 * notifies nobody; outside the quote it still counts.
 */
final class ChatMentionsGroupQuoteTest extends TestCase
{
    public function testQuotedGroupMentionsDoNotCount(): void
    {
        self::assertNull(ChatMentions::parseGroup("> **Ana** wrote:\n> @channel deploy at 5\n\nok"));
        self::assertNull(ChatMentions::parseGroup('   > @here look'));
        self::assertSame('here', ChatMentions::parseGroup("> @channel quoted\n\n@here mine"));
        self::assertSame('channel', ChatMentions::parseGroup('@channel not a quote > here'));
    }
}
