<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatMentionInboxInterface;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatMentionView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Service\ChatMentionInbox;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;

use function array_map;
use function range;

/**
 * 2.0: ChatMentionInboxInterface, the host's Mentions page under the private entity manager: views instead of entities,
 * and mark-read by exact user key (another user's id and unknown ids skipped silently, idempotent, first read time kept).
 */
final class ChatMentionInboxTest extends DatabaseTestCase
{
    public function testTheListingAnswersWithViews(): void
    {
        $inbox = $this->get(ChatMentionInboxInterface::class);
        self::assertInstanceOf(ChatMentionInbox::class, $inbox);
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->openChannel($luis, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $dm     = $chat->openDirect($ana, $luis);
        $first  = $chat->send($ana, $channel, 'one ' . $this->token('Luis', $luis));
        $second = $chat->send($ana, $dm, 'two ' . $this->token('Luis', $luis));
        $keys   = [TestChannelProvider::KEY];

        $views = $inbox->mentions($luis, $keys, false, 10);
        self::assertContainsOnlyInstancesOf(ChatMentionView::class, $views);
        self::assertSame([$second->getUuid(), $first->getUuid()], array_map(static fn (ChatMentionView $view): string => $view->message->uuid, $views));
        [$direct, $inChannel] = $views;
        self::assertSame([true, null, 'Ana', (string) $ana->getId(), 'direct', $dm->getUuid()], [$direct->unread, $direct->readAt, $direct->authorDisplayName, $direct->peerReference, $direct->message->conversationKind, $direct->message->conversationUuid]);
        self::assertSame([null, 'channel', TestChannelProvider::KEY], [$inChannel->peerReference, $inChannel->message->conversationKind, $inChannel->message->channelKey]);
        self::assertSame('one ' . $this->token('Luis', $luis), $inChannel->message->body);
        self::assertSame(2, $inbox->countMentions($luis, $keys, false));
        self::assertSame(1, $inbox->countMentions($luis, [], false), 'a channel the user may no longer use is left out');
        self::assertSame(1, $inbox->countMentions($luis, $keys, false, channelsOnly: true));
        self::assertSame(0, $inbox->countMentions($luis, $keys, false, since: new DateTimeImmutable('+1 day')));
        self::assertSame([], $inbox->mentions($luis, $keys, false, 0));

        self::assertTrue($inbox->markMentionRead($luis, $direct->id));
        $after = $inbox->mentions($luis, $keys, false, 10, unreadOnly: true);
        self::assertSame([$inChannel->id], array_map(static fn (ChatMentionView $view): int => $view->id, $after));
        $read = $inbox->mentions($luis, $keys, false, 10)[0];
        self::assertSame([$direct->id, false], [$read->id, $read->unread]);
        self::assertNotNull($read->readAt);
    }

    public function testOnlyTheUsersOwnMentionsAreMarkedAndRepeatsChangeNothing(): void
    {
        $inbox = $this->get(ChatMentionInboxInterface::class);
        $chat  = $this->get(ChatService::class);
        $ana   = $this->user('Ana');
        $luis  = $this->user('Luis');
        $dm    = $chat->openDirect($ana, $luis);
        $chat->send($ana, $dm, 'for luis ' . $this->token('Luis', $luis));
        $chat->send($ana, $dm, 'again ' . $this->token('Luis', $luis));
        $chat->send($luis, $dm, 'for ana ' . $this->token('Ana', $ana));
        [$luisNewer, $luisOlder] = $this->ids($luis);
        [$anaOwn]                = $this->ids($ana);

        // Another user's mention id: skipped silently, nothing written.
        self::assertFalse($inbox->markMentionRead($ana, $luisNewer));
        self::assertFalse($inbox->markMentionRead((string) $ana->getId(), $luisNewer));
        self::assertSame(0, $inbox->markMentionsRead($ana, [$luisNewer, $luisOlder, 999_999]));
        self::assertSame(3, $this->unread());
        // Unknown, malformed, zero and negative ids; a key nobody has; an unsaved user (no key); an empty key.
        foreach (['999999', '007', ' 7', '7.0', 'abc', '0', 0, -1] as $id) {
            self::assertFalse($inbox->markMentionRead($luis, $id), 'id ' . $id);
        }
        self::assertFalse($inbox->markMentionRead('luis', $luisNewer), 'a key that is no integer id in relation mode');
        self::assertFalse($inbox->markMentionRead((string) $ana->getId() . ' ', $luisNewer));
        self::assertFalse($inbox->markMentionRead(new TestUser('Nobody', 'nobody@example.test'), $luisNewer));
        self::assertFalse($inbox->markMentionRead('', $luisNewer));
        self::assertSame(0, $inbox->markMentionsRead($luis, []));
        self::assertSame(3, $this->unread());

        // The user's own: by user or by key, by int or by canonical string; the foreign id in the list is skipped.
        self::assertSame(2, $inbox->markMentionsRead((string) $luis->getId(), [(string) $luisNewer, $luisOlder, $anaOwn, $luisOlder]));
        self::assertSame(1, $this->unread());
        $readAt = $this->em->getConnection()->fetchOne('SELECT read_at FROM nowo_chat_message_mention WHERE id = ?', [$luisNewer]);
        // Idempotent: already read is still the user's (true), nothing more is written, the first read time stays.
        self::assertTrue($inbox->markMentionRead($luis, $luisNewer));
        self::assertSame(0, $inbox->markMentionsRead($luis, [$luisNewer, $luisOlder]));
        self::assertSame($readAt, $this->em->getConnection()->fetchOne('SELECT read_at FROM nowo_chat_message_mention WHERE id = ?', [$luisNewer]));
        self::assertTrue($inbox->markMentionRead($ana, (string) $anaOwn));
        self::assertSame(0, $this->unread());
    }

    public function testARowTheManagerHoldsIsRefreshedAndLongListsAreChunked(): void
    {
        $inbox = $this->get(ChatMentionInboxInterface::class);
        $chat  = $this->get(ChatService::class);
        $ana   = $this->user('Ana');
        $luis  = $this->user('Luis');
        $dm    = $chat->openDirect($ana, $luis);
        $chat->send($ana, $dm, 'hi ' . $this->token('Luis', $luis));
        $held = $this->get(ChatMessageMentionRepository::class)->findOneForUser($luis, $this->ids($luis)[0]);
        self::assertInstanceOf(ChatMessageMention::class, $held);
        self::assertTrue($held->isUnread());

        // More ids than one statement takes: the user's one is found in a later chunk.
        $ids = [...range(100_000, 100_000 + ChatMentionInbox::CHUNK), (int) $held->getId()];
        self::assertSame(1, $inbox->markMentionsRead($luis, $ids));
        self::assertFalse($held->isUnread(), 'the instance the manager holds shows the stored read time');
    }

    /**
     * The user's mention ids, newest first.
     *
     * @return list<int>
     */
    private function ids(ChatUserInterface $user): array
    {
        return array_map(static fn (ChatMessageMention $mention): int => (int) $mention->getId(), $this->get(ChatMessageMentionRepository::class)->findForUser($user, [], false, 10));
    }

    private function unread(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message_mention WHERE read_at IS NULL');
    }

    private function token(string $name, ChatUserInterface $user): string
    {
        return '@[' . $name . '](' . $user->getChatPublicId() . ')';
    }
}
