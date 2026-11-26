<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Service;

use Nowo\ChatKitBundle\Service\ChatIdempotency;
use PHPUnit\Framework\TestCase;

/**
 * 2.6 (audit ZCG-v-I1): the client API's `client:` key namespace is matched in any letter case (the column has no
 * binary collation on MySQL / MariaDB).
 */
final class ChatIdempotencyClientKeyTest extends TestCase
{
    public function testClientPrefixIsCaseInsensitive(): void
    {
        foreach (['client:abc', 'CLIENT:abc', 'Client:', 'cLiEnT:x'] as $key) {
            self::assertTrue(ChatIdempotency::isClientKey($key), $key);
        }
        foreach (['clien:abc', 'xclient:abc', 'client', '', 'k-1'] as $key) {
            self::assertFalse(ChatIdempotency::isClientKey($key), $key);
        }
    }
}
