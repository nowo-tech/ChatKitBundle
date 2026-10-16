<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Chat entity invariants (cardinality keys, support lifecycle, edit window, tombstones, cursors).
 */
final class ChatEntitiesTest extends TestCase
{
    public function testDirectConversationKeyIsOrderIndependent(): void
    {
        $ana  = $this->user(7);
        $luis = $this->user(3);

        $conversation = ChatConversation::direct($ana, $luis);

        self::assertSame(ConversationKind::Direct, $conversation->getKind());
        self::assertTrue($conversation->isDirect());
        self::assertFalse($conversation->isChannel());
        self::assertFalse($conversation->isSupport());
        self::assertSame('3:7', $conversation->getDirectKey());
        self::assertSame('3:7', ChatConversation::directKeyFor($luis, $ana));
        self::assertTrue($conversation->involvesDirectly($ana));
        self::assertTrue($conversation->involvesDirectly($luis));
        self::assertFalse($conversation->involvesDirectly($this->user(37)), 'no substring match on "3:7"');
        self::assertFalse($conversation->involvesDirectly(new TestUser('Nobody')));
        self::assertSame([3, 7], $conversation->directUserIds());
        self::assertSame(3, $conversation->peerIdOf($ana));
        self::assertSame(7, $conversation->peerIdOf($luis));
        self::assertNull($conversation->peerIdOf($this->user(37)));
        self::assertNull($conversation->getId());
        self::assertNotSame('', $conversation->getUuid());
        self::assertNull($conversation->getChannelKey());
        self::assertNull($conversation->getSupportOwner());
        self::assertNull($conversation->getSupportStatus());
        self::assertSame($conversation->getCreatedAt(), $conversation->getLastMessageAt(), 'activity starts at creation');
        self::assertLessThanOrEqual(new DateTimeImmutable(), $conversation->getCreatedAt());

        $at = new DateTimeImmutable('2026-10-15 10:00:00');
        $conversation->touch($at);
        self::assertSame($at, $conversation->getLastMessageAt());
    }

    public function testDirectKeyNeedsTwoDifferentPersistedUsers(): void
    {
        $this->expectException(LogicException::class);
        ChatConversation::directKeyFor($this->user(4), $this->user(4));
    }

    public function testDirectKeyRejectsUnsavedUsers(): void
    {
        $this->expectException(LogicException::class);
        ChatConversation::directKeyFor($this->user(4), new TestUser('Unsaved'));
    }

    public function testChannel(): void
    {
        $conversation = ChatConversation::channel('project:acme');

        self::assertTrue($conversation->isChannel());
        self::assertSame('project:acme', $conversation->getChannelKey());
        self::assertNull($conversation->getDirectKey());
        self::assertSame([], $conversation->directUserIds());
        self::assertFalse($conversation->involvesDirectly($this->user(1)));
    }

    public function testChannelKeyMustFit(): void
    {
        $this->expectException(LogicException::class);
        ChatConversation::channel(str_repeat('x', 121));
    }

    public function testChannelKeyCannotBeEmpty(): void
    {
        $this->expectException(LogicException::class);
        ChatConversation::channel('');
    }

    public function testSupportThreadResolvesAndReopens(): void
    {
        $owner        = $this->user(5);
        $admin        = $this->user(1);
        $conversation = ChatConversation::support($owner);

        self::assertTrue($conversation->isSupport());
        self::assertSame($owner, $conversation->getSupportOwner());
        self::assertSame(SupportStatus::Open, $conversation->getSupportStatus());
        self::assertFalse($conversation->isResolved());

        $conversation->resolve($admin);
        self::assertTrue($conversation->isResolved());
        self::assertSame($admin, $conversation->getResolvedBy());
        self::assertNotNull($conversation->getResolvedAt());

        $conversation->reopen();
        self::assertSame(SupportStatus::Open, $conversation->getSupportStatus());
        self::assertNull($conversation->getResolvedBy());
        self::assertNull($conversation->getResolvedAt());
    }

    public function testSupportContextKeepsSafeUrlsAndShortLabels(): void
    {
        $conversation = ChatConversation::support($this->user(5));
        self::assertNull($conversation->getSupportContext());

        $conversation->setSupportContext('/projects/x/issues', "  Error   500\n on export ");
        self::assertSame(['url' => '/projects/x/issues', 'label' => 'Error 500 on export'], $conversation->getSupportContext());
        $conversation->setSupportContext('https://evil.test/a', null);
        self::assertNull($conversation->getSupportContext(), 'CHAT-SEC-04: absolute URLs are dropped (no off-site link for agents)');
        $conversation->setSupportContext('/\\evil.test/x', "Billing\u{202E}page\u{0007}");
        self::assertSame(['label' => 'Billing page'], $conversation->getSupportContext(), 'backslash host trick dropped, control / bidi characters removed');
        $conversation->setSupportContext('javascript:alert(1)', str_repeat('x', 200));
        self::assertSame(['label' => str_repeat('x', 120)], $conversation->getSupportContext(), 'no other schemes, label capped');
        $conversation->setSupportContext('//evil.test/x', '');
        self::assertNull($conversation->getSupportContext(), 'protocol-relative URLs are dropped');
        $conversation->setSupportContext(null, null);
        self::assertNull($conversation->getSupportContext());
    }

    public function testOnlySupportThreadsHaveAContext(): void
    {
        $this->expectException(LogicException::class);
        ChatConversation::channel('project:acme')->setSupportContext('/x', 'x');
    }

    public function testOnlySupportThreadsResolve(): void
    {
        $this->expectException(LogicException::class);
        ChatConversation::channel('project:acme')->resolve($this->user(1));
    }

    public function testOnlySupportThreadsReopen(): void
    {
        $this->expectException(LogicException::class);
        ChatConversation::direct($this->user(1), $this->user(2))->reopen();
    }

    public function testMessageEditWindowAndTombstones(): void
    {
        $author       = $this->user(2);
        $admin        = $this->user(1);
        $created      = new DateTimeImmutable('2026-10-15 10:00:00');
        $conversation = ChatConversation::direct($author, $admin);
        $message      = new ChatMessage($conversation, $author, '**hi**', '<p><strong>hi</strong></p>', $created);

        self::assertNull($message->getId());
        self::assertNotSame('', $message->getUuid());
        self::assertSame($conversation, $message->getConversation());
        self::assertSame($author, $message->getAuthor());
        self::assertTrue($message->isAuthoredBy($author));
        self::assertFalse($message->isAuthoredBy($admin));
        self::assertFalse($message->isAuthoredBy(new TestUser('Unsaved')));
        self::assertSame(ChatMessage::KIND_TEXT, $message->getKind());
        self::assertFalse($message->isBuzz());
        self::assertSame('**hi**', $message->getBody());
        self::assertSame('<p><strong>hi</strong></p>', $message->getBodyHtml());
        self::assertSame($created, $message->getCreatedAt());
        self::assertFalse($message->isEdited());
        self::assertNull($message->getEditedAt());

        self::assertTrue($message->isWithinEditWindow($created->modify('+15 minutes')));
        self::assertFalse($message->isWithinEditWindow($created->modify('+15 minutes +1 second')));

        $message->edit('hello', '<p>hello</p>', $created->modify('+1 minute'));
        self::assertTrue($message->isEdited());
        self::assertSame('hello', $message->getBody());
        self::assertSame('<p>hello</p>', $message->getBodyHtml());
        self::assertEquals($created->modify('+1 minute'), $message->getEditedAt());

        self::assertFalse($message->isDeleted());
        self::assertFalse($message->isModerated());
        $message->delete($author);
        self::assertTrue($message->isDeleted());
        self::assertSame('', $message->getBody());
        self::assertSame('', $message->getBodyHtml());
        self::assertSame($author, $message->getDeletedBy());
        self::assertNotNull($message->getDeletedAt());
        self::assertFalse($message->isModerated(), 'the author deleting is not moderation');

        $moderated = new ChatMessage($conversation, $author, 'spam', '<p>spam</p>');
        $moderated->edit('spam!', '<p>spam!</p>');
        self::assertNotNull($moderated->getEditedAt());
        $moderated->delete($admin);
        self::assertTrue($moderated->isModerated());
    }

    public function testParticipantReadCursorOnlyMovesForwardAndMute(): void
    {
        $user         = $this->user(4);
        $conversation = ChatConversation::direct($user, $this->user(9));
        $participant  = new ChatParticipant($conversation, $user);

        self::assertNull($participant->getId());
        self::assertSame($conversation, $participant->getConversation());
        self::assertSame($user, $participant->getUser());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $participant->getJoinedAt());
        self::assertNull($participant->getLastReadMessageId());

        self::assertTrue($participant->markReadUpTo(10));
        self::assertFalse($participant->markReadUpTo(8), 'a stale tab cannot move the cursor back');
        self::assertFalse($participant->markReadUpTo(10));
        self::assertTrue($participant->markReadUpTo(11));
        self::assertSame(11, $participant->getLastReadMessageId());

        $now = new DateTimeImmutable('2026-10-15 10:00:00');
        self::assertFalse($participant->isMuted($now));
        $participant->muteUntil($now->modify('+1 hour'));
        self::assertTrue($participant->isMuted($now));
        self::assertFalse($participant->isMuted($now->modify('+2 hours')));
        self::assertEquals($now->modify('+1 hour'), $participant->getMutedUntil());
        $participant->muteUntil(null);
        self::assertNull($participant->getMutedUntil());
    }

    public function testReaction(): void
    {
        $user     = $this->user(4);
        $message  = new ChatMessage(ChatConversation::direct($user, $this->user(9)), $user, 'x', '<p>x</p>');
        $reaction = new ChatReaction($message, $user, '👍');

        self::assertNull($reaction->getId());
        self::assertSame($message, $reaction->getMessage());
        self::assertSame($user, $reaction->getUser());
        self::assertSame('👍', $reaction->getEmoji());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $reaction->getCreatedAt());
    }

    public function testTimestampsComeFromTheCallerClock(): void
    {
        $at    = new DateTimeImmutable('2026-01-02 03:04:05');
        $user  = $this->user(4);
        $other = $this->user(9);

        foreach ([ChatConversation::direct($user, $other, $at), ChatConversation::channel('project:acme', $at), ChatConversation::support($user, $at)] as $conversation) {
            self::assertSame($at, $conversation->getCreatedAt());
            self::assertSame($at, $conversation->getLastMessageAt());
        }
        $support = ChatConversation::support($user);
        $support->resolve($other, $at);
        self::assertSame($at, $support->getResolvedAt());
        self::assertSame($at, (new ChatParticipant($support, $user, $at))->getJoinedAt());
        self::assertSame($at, (new ChatReaction(new ChatMessage($support, $user, 'x', '<p>x</p>'), $user, '👍', $at))->getCreatedAt());
    }

    public function testBuzzMessage(): void
    {
        $author = $this->user(2);
        $buzz   = ChatMessage::buzz(ChatConversation::direct($author, $this->user(3)), $author);

        self::assertTrue($buzz->isBuzz());
        self::assertSame(ChatMessage::KIND_BUZZ, $buzz->getKind());
        self::assertSame('', $buzz->getBody());
    }

    public function testUserSettings(): void
    {
        $user     = $this->user(4);
        $settings = new ChatUserSettings($user);

        self::assertNull($settings->getId());
        self::assertSame($user, $settings->getUser());
        self::assertTrue($settings->isPresenceVisible());
        self::assertFalse($settings->isPushEnabled());
        self::assertTrue($settings->isBuzzEnabled());
        self::assertTrue($settings->isEmailDigest());
        $settings->setEmailDigest(false);
        self::assertFalse($settings->isEmailDigest());
        self::assertSame('pop', $settings->getSound());

        $settings->setPresenceVisible(false);
        $settings->setPushEnabled(true);
        $settings->setBuzzEnabled(false);
        $settings->setSound('none');
        self::assertFalse($settings->isPresenceVisible());
        self::assertTrue($settings->isPushEnabled());
        self::assertFalse($settings->isBuzzEnabled());
        self::assertSame('none', $settings->getSound());

        $this->expectException(InvalidArgumentException::class);
        $settings->setSound('foghorn');
    }

    private function user(int $id): TestUser
    {
        $user = new TestUser('User ' . $id);
        (new ReflectionProperty(TestUser::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
