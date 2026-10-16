<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Security;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use ReflectionProperty;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Every chat permission against the test policy (Agent* answer support, Stranger* only reach agents,
 * Disabled* are unreachable).
 */
final class ChatVoterTest extends DatabaseTestCase
{
    private ChatVoter $voter;

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-15 10:00:00');
        $this->voter = new ChatVoter($this->get(ChatAccess::class), $this->clock);
    }

    public function testDirectConversations(): void
    {
        $chat     = $this->get(ChatService::class);
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $eve      = $this->user('Eve');
        $stranger = $this->user('Stranger');
        $agent    = $this->user('Agent Smith');
        $dm       = $chat->openDirect($ana, $luis);

        self::assertTrue($this->can($ana, ChatVoter::VIEW, $dm));
        self::assertTrue($this->can($luis, ChatVoter::SEND, $dm));
        self::assertFalse($this->can($eve, ChatVoter::VIEW, $dm), 'not part of the pair');
        self::assertFalse($this->can($eve, ChatVoter::SEND, $dm));
        self::assertFalse($this->can($ana, ChatVoter::MODERATE, $dm), 'direct threads are never moderated');
        self::assertFalse($this->can($ana, ChatVoter::RESOLVE, $dm));

        // The policy forbids Stranger ↔ Ana, but a thread an agent opened with a stranger stays writable both ways.
        $lost = ChatConversation::direct($ana, $stranger);
        $this->em->persist($lost);
        $this->em->flush();
        self::assertTrue($this->can($stranger, ChatVoter::VIEW, $lost), 'still readable');
        self::assertFalse($this->can($stranger, ChatVoter::SEND, $lost), 'no longer writable');
        $byAgent = $chat->openDirect($agent, $stranger);
        self::assertTrue($this->can($stranger, ChatVoter::SEND, $byAgent), 'replying to an agent');
        self::assertTrue($this->can($agent, ChatVoter::SEND, $byAgent));

        // The peer's account is gone.
        $ghost = new TestUser('Ghost');
        (new ReflectionProperty(TestUser::class, 'id'))->setValue($ghost, 999_999);
        $orphan = ChatConversation::direct($ana, $ghost);
        $this->em->persist($orphan);
        $this->em->flush();
        $this->get(ChatAccess::class)->reset();
        self::assertTrue($this->can($ana, ChatVoter::VIEW, $orphan));
        self::assertFalse($this->can($ana, ChatVoter::SEND, $orphan));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote(new NullToken(), $dm, [ChatVoter::VIEW]), 'anonymous');
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($this->token($ana), $dm, ['other']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($this->token($ana), new stdClass(), [ChatVoter::VIEW]));
        $disabled = $this->user('Disabled Bob');
        self::assertFalse($this->can($disabled, ChatVoter::VIEW, $chat->openDirect($disabled, $ana)));
    }

    public function testChannelsAndSupport(): void
    {
        $chat     = $this->get(ChatService::class);
        $access   = $this->get(ChatAccess::class);
        $ana      = $this->user('Ana');
        $stranger = $this->user('Stranger');
        $agent    = $this->user('Agent Smith');
        $channel  = $chat->openChannel($ana, $access->channel(TestChannelProvider::KEY) ?? self::fail('channel'));

        self::assertTrue($this->can($ana, ChatVoter::SEND, $channel));
        self::assertFalse($this->can($stranger, ChatVoter::VIEW, $channel));
        self::assertFalse($this->can($ana, ChatVoter::MODERATE, $channel));
        self::assertTrue($this->can($agent, ChatVoter::MODERATE, $channel));
        $unknown = ChatConversation::channel('project:gone');
        self::assertFalse($this->can($ana, ChatVoter::VIEW, $unknown), 'the application no longer knows the channel');

        $support = $chat->openSupport($ana);
        self::assertTrue($this->can($ana, ChatVoter::SEND, $support));
        self::assertFalse($this->can($stranger, ChatVoter::VIEW, $support));
        self::assertTrue($this->can($agent, ChatVoter::VIEW, $support));
        self::assertTrue($this->can($agent, ChatVoter::RESOLVE, $support));
        self::assertFalse($this->can($ana, ChatVoter::RESOLVE, $support));
        self::assertTrue($this->can($agent, ChatVoter::MODERATE, $support));
    }

    public function testMessages(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $agent   = $this->user('Agent Smith');
        $dm      = $chat->openDirect($ana, $luis);
        $message = new ChatMessage($dm, $ana, 'hi', '<p>hi</p>', new DateTimeImmutable('2026-10-15 09:50:00'));
        $buzz    = ChatMessage::buzz($dm, $ana, new DateTimeImmutable('2026-10-15 09:55:00'));
        $this->em->persist($message);
        $this->em->persist($buzz);
        $this->em->flush();

        self::assertTrue($this->can($ana, ChatVoter::EDIT, $message));
        self::assertTrue($this->can($ana, ChatVoter::DELETE, $message));
        self::assertFalse($this->can($luis, ChatVoter::EDIT, $message), 'not the author');
        self::assertFalse($this->can($luis, ChatVoter::DELETE, $message));
        self::assertTrue($this->can($luis, ChatVoter::REACT, $message));
        self::assertFalse($this->can($ana, ChatVoter::EDIT, $buzz), 'a buzz has no text');
        self::assertFalse($this->can($luis, ChatVoter::REACT, $buzz));

        $this->clock->modify('+11 minutes');
        self::assertFalse($this->can($ana, ChatVoter::EDIT, $message), 'edit window over');
        self::assertFalse($this->can($ana, ChatVoter::DELETE, $message));

        $support  = $chat->openSupport($luis);
        $reported = $chat->send($luis, $support, 'spam');
        self::assertFalse($this->can($agent, ChatVoter::DELETE, $reported), 'nobody deletes someone else\'s message, not even an agent');
        $reported->delete($agent);
        self::assertFalse($this->can($agent, ChatVoter::DELETE, $reported), 'already deleted');
        self::assertFalse($this->can($luis, ChatVoter::REACT, $reported));
    }

    private function can(TestUser $user, string $attribute, object $subject): bool
    {
        return $this->voter->vote($this->token($user), $subject, [$attribute]) === VoterInterface::ACCESS_GRANTED;
    }

    private function token(TestUser $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
