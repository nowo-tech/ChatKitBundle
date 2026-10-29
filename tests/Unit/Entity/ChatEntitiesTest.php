<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use DateTimeZone;
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

use function sprintf;

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

    public function testChatPreferences(): void
    {
        $settings = new ChatUserSettings($this->user(5));

        self::assertSame(['message' => 'pop', 'mention' => 'chime', 'buzz' => 'buzz', 'support' => 'ding'], array_map($settings->getSoundFor(...), array_combine(array_keys(ChatUserSettings::SOUND_EVENTS), array_keys(ChatUserSettings::SOUND_EVENTS))));
        self::assertSame(80, $settings->getVolume());
        self::assertFalse($settings->isSoundBackgroundOnly());
        self::assertTrue($settings->isBuzzOpenWindow());
        self::assertFalse($settings->isOpenOnMessage());
        self::assertSame('enter', $settings->getSendKey());
        self::assertTrue($settings->isPushPreview());

        $settings->applyPreference('soundMessage', 'bell');
        $settings->applyPreference('soundMention', 'knock');
        $settings->applyPreference('soundBuzz', 'none');
        $settings->applyPreference('soundSupport', 'blip');
        $settings->applyPreference('volume', 35);
        $settings->applyPreference('soundBackgroundOnly', true);
        $settings->applyPreference('buzzEnabled', false);
        $settings->applyPreference('buzzOpenWindow', false);
        $settings->applyPreference('openOnMessage', true);
        $settings->applyPreference('sendKey', 'ctrl_enter');
        $settings->applyPreference('pushPreview', false);
        $settings->applyPreference('presenceVisible', false);
        $settings->applyPreference('emailDigest', false);
        self::assertSame([
            'sound'          => 'bell',
            'sounds'         => ['message' => 'bell', 'mention' => 'knock', 'buzz' => 'none', 'support' => 'blip'],
            'volume'         => 35,
            'backgroundOnly' => true,
            'buzz'           => false,
            'buzzOpenWindow' => false,
            'openOnMessage'  => true,
            'sendKey'        => 'ctrl_enter',
            'pushPreview'    => false,
            'presence'       => false,
            'push'           => false,
            'emailDigest'    => false,
        ], $settings->preferences());
        self::assertSame('bell', $settings->getSound(), 'the 1.x single sound is the new-message sound');

        foreach ([['pushEnabled', true], ['nope', true], ['volume', '50'], ['volume', 101], ['volume', -1], ['buzzEnabled', 'yes'], ['sendKey', 1], ['sendKey', 'shift'], ['soundMessage', 'foghorn'], ['soundBuzz', null]] as [$key, $value]) {
            try {
                $settings->applyPreference($key, $value);
                self::fail(sprintf('%s accepted %s', $key, var_export($value, true)));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $settings->getSoundFor('ringtone');
    }

    public function testUnknownSoundEventIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ChatUserSettings($this->user(6)))->setSoundFor('ringtone', 'pop');
    }

    public function testChatStatus(): void
    {
        $settings = new ChatUserSettings($this->user(7));
        $now      = new DateTimeImmutable('2026-10-29 10:00:00', new DateTimeZone('UTC'));

        self::assertSame(['status' => 'available', 'text' => null, 'emoji' => null, 'expiresAt' => null], $settings->statusView($now));
        $settings->setStatus('busy', '  In a meeting  ', '📅', $now->modify('+1 hour'));
        self::assertSame(['status' => 'busy', 'text' => 'In a meeting', 'emoji' => '📅', 'expiresAt' => '2026-10-29T11:00:00+00:00'], $settings->statusView($now));
        // Expired: back to available, without text.
        self::assertSame(['status' => 'available', 'text' => null, 'emoji' => null, 'expiresAt' => null], $settings->statusView($now->modify('+2 hours')));

        // Empty or control-character text means none.
        $settings->setStatus('dnd', ' ', 'bad');
        self::assertSame(['status' => 'dnd', 'text' => null, 'emoji' => null, 'expiresAt' => null], $settings->statusView($now));
        $settings->setStatus('invisible', str_repeat('x', 80), '👩‍💻');
        self::assertSame('invisible', $settings->getStatus($now));

        foreach ([['sleeping', null, null], ['busy', str_repeat('x', 81), null], ['busy', null, 'ab'], ['busy', null, str_repeat('🙂', 17)], ['busy', null, '1️⃣']] as [$status, $text, $emoji]) {
            try {
                $settings->setStatus($status, $text, $emoji);
                self::fail('accepted ' . $status);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertNull(ChatUserSettings::statusExpiry('never', $now));
        self::assertSame('2026-10-29 10:30:00', ChatUserSettings::statusExpiry('30m', $now)?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-29 11:00:00', ChatUserSettings::statusExpiry('1h', $now)?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-29 14:00:00', ChatUserSettings::statusExpiry('4h', $now)?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-29 23:59:59', ChatUserSettings::statusExpiry('today', $now)?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-01 23:59:59', ChatUserSettings::statusExpiry('week', $now)?->format('Y-m-d H:i:s'), 'Thursday → Sunday');
        $this->expectException(InvalidArgumentException::class);
        ChatUserSettings::statusExpiry('fortnight', $now);
    }

    public function testArchiveAndPinMarks(): void
    {
        $ana          = $this->user(8);
        $luis         = $this->user(9);
        $now          = new DateTimeImmutable('2026-10-29 10:00:00');
        $conversation = ChatConversation::direct($ana, $luis, $now->modify('-1 day'));
        $participant  = new ChatParticipant($conversation, $ana, $now);
        self::assertNull($participant->getArchivedAt());
        self::assertFalse($participant->isArchived($now));
        $participant->archive($now);
        self::assertSame($now, $participant->getArchivedAt());
        self::assertTrue($participant->isArchived($now));
        $conversation->touch($now->modify('+1 minute'));
        self::assertFalse($participant->isArchived($now), 'a newer message brings it back');
        $participant->muteUntil($now->modify('+1 hour'));
        self::assertTrue($participant->isArchived($now), 'muted: stays closed');
        $participant->archive(null);
        self::assertFalse($participant->isArchived($now));

        $message = new ChatMessage($conversation, $luis, 'hi', '<p>hi</p>', $now);
        self::assertTrue($message->isPinnable());
        self::assertNull($message->getPinnedMessageUuid());
        $message->pin($ana, $now);
        self::assertSame($ana, $message->getPinnedBy());
        self::assertSame($now, $message->getPinnedAt());
        $line = ChatMessage::pinLine($conversation, $ana, $message, $now);
        self::assertTrue($line->isPinLine());
        self::assertFalse($line->isPinnable());
        self::assertSame($message->getUuid(), $line->getPinnedMessageUuid());
        $message->pin($ana, null);
        self::assertNull($message->getPinnedBy());
        self::assertNull($message->getPinnedAt());
    }

    private function user(int $id): TestUser
    {
        $user = new TestUser('User ' . $id);
        (new ReflectionProperty(TestUser::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
