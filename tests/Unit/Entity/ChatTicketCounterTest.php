<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Entity;

use Nowo\ChatKitBundle\Entity\ChatTicketCounter;
use PHPUnit\Framework\TestCase;

final class ChatTicketCounterTest extends TestCase
{
    public function testItHoldsTheLastNumberOfItsTable(): void
    {
        $counter = new ChatTicketCounter('nowo_chat_conversation', 41);

        self::assertSame('nowo_chat_conversation', $counter->getKey());
        self::assertSame(41, $counter->getLastNumber());
    }
}
