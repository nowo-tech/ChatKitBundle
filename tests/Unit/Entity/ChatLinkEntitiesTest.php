<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * 1.19: the pinned link and the link index row.
 */
final class ChatLinkEntitiesTest extends TestCase
{
    public function testPinnedLink(): void
    {
        $ana          = new TestUser('Ana');
        $conversation = ChatConversation::channel('project:acme');
        $at           = new DateTimeImmutable('2026-11-05 10:00');
        $link         = new ChatConversationLink($conversation, $ana, 'https://zoom.us/j/1', 'zoom.us', 'Retro', $at);

        self::assertNull($link->getId());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $link->getUuid());
        self::assertSame([$conversation, $ana, 'https://zoom.us/j/1', 'zoom.us', 'Retro', $at, null], [$link->getConversation(), $link->getCreatedBy(), $link->getUrl(), $link->getHost(), $link->getTitle(), $link->getCreatedAt(), $link->getUpdatedAt()]);

        $link->change('https://example.com/', 'example.com', null);
        self::assertSame(['https://example.com/', 'example.com', null], [$link->getUrl(), $link->getHost(), $link->getTitle()]);
        self::assertNotNull($link->getUpdatedAt());
        self::assertNull((new ChatConversationLink($conversation, null, 'https://a.example/', 'a.example', null))->getCreatedBy());
    }

    public function testIndexRow(): void
    {
        $message = new ChatMessage(ChatConversation::channel('project:acme'), new TestUser('Ana'), 'x', 'x');
        $at      = new DateTimeImmutable('2026-11-05 10:00');
        $row     = new ChatMessageLink($message, 'https://teams.microsoft.com/l/x', 'teams.microsoft.com', 'Daily', ChatMessageLink::KIND_CARD, 'teams', $at);

        self::assertNull($row->getId());
        self::assertSame([$message, 'https://teams.microsoft.com/l/x', 'teams.microsoft.com', 'Daily', 'card', 'teams', $at], [$row->getMessage(), $row->getUrl(), $row->getHost(), $row->getTitle(), $row->getKind(), $row->getProvider(), $row->getCreatedAt()]);
    }
}
