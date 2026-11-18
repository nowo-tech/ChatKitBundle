<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatIdempotencyKey;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;
use Nowo\ChatKitBundle\Entity\ChatThreadRead;
use Nowo\ChatKitBundle\Event\ChatMessageEditedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageForwardedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageForwardingEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Event\ChatThreadReplyEvent;
use Nowo\ChatKitBundle\Forward\AllowAllChatForwardPolicy;
use Nowo\ChatKitBundle\History\NoChatHistoryReaders;
use Nowo\ChatKitBundle\Model\ChatForwardRequest;
use Nowo\ChatKitBundle\Model\ChatHistoryRequest;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Service\ChatForwarder;
use Nowo\ChatKitBundle\Service\ChatForwardRefused;
use Nowo\ChatKitBundle\Service\ChatIdempotency;
use Nowo\ChatKitBundle\Service\ChatIdempotencyConflict;
use Nowo\ChatKitBundle\Service\ChatMenuOptions;
use Nowo\ChatKitBundle\Service\ChatThreads;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 1.36: the rows, events, value objects and default ports of forward, threads and edit history.
 */
final class ChatMessageMenu136EntitiesTest extends TestCase
{
    public function testThreadReplyAndForwardMarkers(): void
    {
        $ana    = $this->user('Ana', 1);
        $room   = ChatConversation::channel('project:acme');
        $root   = new ChatMessage($room, $ana, 'root', '<p>root</p>');
        $reply  = new ChatMessage($room, $ana, 'reply', '<p>reply</p>');
        $nested = new ChatMessage($room, $ana, 'nested', '<p>nested</p>');
        self::assertTrue($root->canStartThread());
        $reply->replyIn($root);
        $nested->replyIn($reply);
        self::assertSame($root, $reply->getThreadRoot());
        self::assertSame($root, $nested->getThreadRoot(), 'one level: a reply to a reply goes to the root');
        self::assertFalse($reply->canStartThread());
        self::assertSame($root, ChatThreads::rootOf($nested));
        self::assertSame($root, ChatThreads::rootOf($root));
        self::assertSame([0, null], [$root->getThreadReplyCount(), $root->getThreadLastReplyAt()]);

        $dm   = ChatConversation::direct($ana, $this->user('Luis', 2));
        $copy = new ChatMessage($dm, $ana, 'root', '<p>root</p>');
        self::assertFalse($copy->isForwarded());
        $copy->forwardOf($root);
        self::assertTrue($copy->isForwarded());
        self::assertSame([$root, $room], [$copy->getForwardedFrom(), $copy->getForwardedFromConversation()]);

        $view = ChatMessageView::of($copy);
        self::assertTrue($view->forwarded);
        self::assertNull($view->threadRootUuid);
        self::assertSame($root->getUuid(), ChatMessageView::of($reply)->threadRootUuid);
        self::assertSame($root->getUuid(), (new ChatMessageSentEvent($reply))->threadRootUuid());
        self::assertNull((new ChatMessageSentEvent($root))->threadRootUuid());
    }

    public function testRevisionThreadReadAndIdempotencyRows(): void
    {
        $ana     = $this->user('Ana', 1);
        $message = new ChatMessage(ChatConversation::channel('project:acme'), $ana, 'now', '<p>now</p>');
        $at      = new DateTimeImmutable('2026-11-18 10:00:00');

        $revision = new ChatMessageRevision($message, 'before', $at);
        self::assertSame([null, $message, 'before', $at], [$revision->getId(), $revision->getMessage(), $revision->getBody(), $revision->getEditedAt()]);

        $read = new ChatThreadRead($message, $ana, $at);
        self::assertSame([null, $message, $ana, '1', null, true, $at], [$read->getId(), $read->getRoot(), $read->getUser(), $read->getUserReference(), $read->getLastReadReplyId(), $read->isFollowing(), $read->getUpdatedAt()]);
        (new ReflectionProperty(ChatThreadRead::class, 'userRef'))->setValue($read, null);
        self::assertSame('1', $read->getUserReference(), 'read from the user when the reference is not loaded');

        $key = new ChatIdempotencyKey($ana, 'k-1', 'hash', $at);
        self::assertSame([null, $ana, '1', 'k-1', 'hash', null, $at], [$key->getId(), $key->getUser(), $key->getUserReference(), $key->getKey(), $key->getRequestHash(), $key->getResult(), $key->getCreatedAt()]);
        (new ReflectionProperty(ChatIdempotencyKey::class, 'userRef'))->setValue($key, null);
        self::assertSame('1', $key->getUserReference());
    }

    public function testEventsAndExceptions(): void
    {
        $ana    = $this->user('Ana', 1);
        $room   = ChatConversation::channel('project:acme');
        $source = new ChatMessage($room, $ana, 'source', '<p>source</p>');
        $copy   = new ChatMessage($room, $ana, 'source', '<p>source</p>');

        $forwarding = new ChatMessageForwardingEvent($source, $ana, $room);
        self::assertFalse($forwarding->isDenied());
        self::assertSame('', $forwarding->reason());
        self::assertSame($source->getUuid(), $forwarding->sourceView()->uuid);
        $forwarding->deny('plan.limit');
        self::assertTrue($forwarding->isDenied());
        self::assertSame('plan.limit', $forwarding->reason());
        $forwarding->deny('<b>not a key</b>');
        self::assertSame('', $forwarding->reason(), 'only a short key reaches the client');
        self::assertNull($forwarding->recipient);

        $forwarded = new ChatMessageForwardedEvent($source, $copy, $ana);
        self::assertSame([$copy->getUuid(), $source->getUuid(), $ana->getChatPublicId()], [$forwarded->messageView()->uuid, $forwarded->sourceUuid(), $forwarded->actorPublicId()]);

        $reply = new ChatThreadReplyEvent($source, $copy);
        self::assertSame([$copy->getUuid(), $source->getUuid()], [$reply->replyView()->uuid, $reply->rootUuid()]);

        $edited = new ChatMessageEditedEvent($source, 7);
        self::assertSame([7, $source->getUuid()], [$edited->revisionId, $edited->messageView()->uuid]);
        self::assertNull((new ChatMessageEditedEvent($source))->revisionId);

        $refused = new ChatForwardRefused(2, 'plan.limit');
        self::assertSame([2, 'plan.limit', 'forward_forbidden'], [$refused->target, $refused->reason, $refused->getMessage()]);
        self::assertSame('', (new ChatForwardRefused(0))->reason);
        self::assertStringContainsString('Idempotency-Key', (new ChatIdempotencyConflict())->getMessage());
    }

    public function testDefaultPortsOptionsAndHelpers(): void
    {
        self::assertTrue((new AllowAllChatForwardPolicy())->mayForward(new ChatForwardRequest('a', 'm', 'c', 'direct', null, null, 'direct', null, 'b')));
        $history = new ChatHistoryRequest('a', 'm', null, 'c', 'channel', 'project:acme');
        self::assertFalse((new NoChatHistoryReaders())->mayReadHistory($history));
        self::assertSame('project:acme', $history->channelKey);

        $defaults = new ChatMenuOptions();
        self::assertSame([false, 5, false, false, false, 20], [$defaults->forwardEnabled(), $defaults->maxForwardTargets(), $defaults->forwardFromSupport(), $defaults->threadsEnabled(), $defaults->historyEnabled(), $defaults->maxRevisions()]);
        $set = new ChatMenuOptions(['enabled' => true, 'max_targets' => 3, 'from_support' => true], ['enabled' => true], ['enabled' => true, 'max_revisions' => 4]);
        self::assertSame([true, 3, true, true, true, 4], [$set->forwardEnabled(), $set->maxForwardTargets(), $set->forwardFromSupport(), $set->threadsEnabled(), $set->historyEnabled(), $set->maxRevisions()]);

        self::assertSame('hi @Ana and @channel', ChatForwarder::plainText('hi @[Ana](u-1) and @channel'));
        foreach (['k', 'a.b:c-d_e', str_repeat('x', 80)] as $good) {
            self::assertTrue(ChatIdempotency::isValidKey($good), $good);
        }
        foreach (['', 'has space', str_repeat('x', 81), null, 12] as $bad) {
            self::assertFalse(ChatIdempotency::isValidKey($bad));
        }
        self::assertSame(ChatIdempotency::hash(['a', 1]), ChatIdempotency::hash(['a', 1]));
        self::assertNotSame(ChatIdempotency::hash(['a', 1]), ChatIdempotency::hash(['a', 2]));
    }

    private function user(string $name, int $id): TestUser
    {
        $user = new TestUser($name);
        (new ReflectionProperty(TestUser::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
