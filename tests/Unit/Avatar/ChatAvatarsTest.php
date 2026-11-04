<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Avatar;

use Nowo\ChatKitBundle\Avatar\ChatAvatars;
use Nowo\ChatKitBundle\Avatar\NullChatAvatarUrlResolver;
use Nowo\ChatKitBundle\Tests\Support\Chat\CountingAvatarResolver;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingLogger;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * 1.16 per-request memo of profile pictures: one batch per prime, sanitised values, nulls on failure, reset.
 */
final class ChatAvatarsTest extends TestCase
{
    public function testPrimeBatchesUnknownUsersOnceAndMemoises(): void
    {
        $resolver       = new CountingAvatarResolver();
        $ana            = new TestUser('Ana');
        $luis           = new TestUser('Luis');
        $eve            = new TestUser('Eve');
        $resolver->urls = [
            $ana->getChatPublicId()  => 'https://cdn.example.com/ana.webp',
            $luis->getChatPublicId() => '/files/luis/s128',
            $eve->getChatPublicId()  => 'javascript:alert(1)',
        ];
        $logger  = $this->logger();
        $avatars = new ChatAvatars($resolver, $logger);

        $avatars->prime([$ana, null, $luis, $ana, $eve]);
        self::assertSame([['ids' => [$ana->getChatPublicId(), $luis->getChatPublicId(), $eve->getChatPublicId()], 'size' => ChatAvatars::SIZE]], $resolver->calls);
        self::assertSame('https://cdn.example.com/ana.webp', $avatars->urlOf($ana));
        self::assertSame('/files/luis/s128', $avatars->urlOf($luis));
        self::assertNull($avatars->urlOf($eve), 'rejected by the URL policy');
        self::assertNull($avatars->urlOf(null));
        $avatars->prime([$ana, $luis]);
        self::assertCount(1, $resolver->calls, 'known users are not asked again');
        self::assertSame(['debug Chat avatar URL rejected for {user} ' . $eve->getChatPublicId()], $logger->lines, 'the id, never the URL');

        // A miss resolves the user on its own (a second call); an empty prime asks nothing.
        $bob = new TestUser('Bob');
        self::assertNull($avatars->urlOf($bob));
        self::assertCount(2, $resolver->calls);
        $avatars->prime([]);
        self::assertCount(2, $resolver->calls);

        // Worker mode: the next request starts empty.
        $avatars->reset();
        $avatars->urlOf($ana);
        self::assertCount(3, $resolver->calls);
    }

    public function testAThrowingResolverGivesNullForTheBatch(): void
    {
        $resolver       = new CountingAvatarResolver();
        $resolver->fail = true;
        $ana            = new TestUser('Ana');
        $resolver->urls = [$ana->getChatPublicId() => 'https://cdn.example.com/ana.webp'];
        $logger         = $this->logger();
        $avatars        = new ChatAvatars($resolver, $logger);

        $avatars->prime([$ana]);
        self::assertNull($avatars->urlOf($ana));
        self::assertCount(1, $resolver->calls, 'the failure is memoised for the request');
        self::assertSame(['warning Chat avatar resolver failed: {error} RuntimeException'], $logger->lines);
    }

    public function testTheNullResolverGivesNoPictures(): void
    {
        $resolver = new NullChatAvatarUrlResolver();
        $ana      = new TestUser('Ana');
        self::assertNull($resolver->urlFor($ana, 56));
        self::assertSame([], $resolver->urlsFor([$ana], 56));
        self::assertNull((new ChatAvatars($resolver))->urlOf($ana));
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }
}
