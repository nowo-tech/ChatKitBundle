<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatConversationLinkRepository;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatQuickReplyRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\TransactionContract\TransactionRunnerInterface;

use function sprintf;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * 1.30 (Beacon spec 239): the conversation's activity moves forward only, under a row lock; a new message reads the
 * archived rows to reopen instead of locking every member; the reads that grow with a person's history are paged or
 * capped; the default runner is wired under the bundle's own id.
 */
final class ChatLoadReadinessTest extends DatabaseTestCase
{
    public function testTheDefaultRunnerIsWiredToTheInterface(): void
    {
        self::assertInstanceOf(ChatKitTransactionRunner::class, $this->get(TransactionRunnerInterface::class));
    }

    public function testTheActivityMovesForwardOnly(): void
    {
        $chat          = $this->get(ChatService::class);
        $conversations = $this->get(ChatConversationRepository::class);
        $dm            = $chat->openDirect($this->user('Ana'), $this->user('Luis'));
        self::assertNull($conversations->lockActivity(ChatConversation::direct($this->user('Bea'), $this->user('Cai'))), 'a new conversation has no row');

        $chat->send($this->user('Ana2'), $dm, 'skip');
        $later = new DateTimeImmutable('+1 day');
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_conversation SET last_message_at = ? WHERE id = ?', [$later->format('Y-m-d H:i:s'), $dm->getId()]);
        self::assertSame($later->format('Y-m-d H:i:s'), $conversations->lockActivity($dm)?->format('Y-m-d H:i:s'));

        // A message whose clock is behind what another request committed never sets the activity back.
        $this->em->refresh($dm);
        $chat->send($this->em->getRepository(TestUser::class)->findOneBy(['name' => 'Ana']) ?? self::fail('Ana'), $dm, 'late clock');
        self::assertSame($later->format('Y-m-d H:i:s'), (string) $this->em->getConnection()->fetchOne('SELECT last_message_at FROM nowo_chat_conversation WHERE id = ?', [$dm->getId()]));
    }

    public function testANewMessageReopensTheArchivedRowsWithoutLoadingTheMembers(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        foreach (['Luis', 'Bea', 'Cai'] as $name) {
            $chat->openChannel($this->user($name), new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        }
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_participant SET archived_at = ?', ['2026-01-01 00:00:00']);
        $this->em->getConnection()->executeStatement("UPDATE nowo_chat_participant SET muted_until = ? WHERE user_id = (SELECT id FROM test_user WHERE name = 'Cai')", ['2099-01-01 00:00:00']);
        $this->em->clear();
        $ana     = $this->em->getRepository(TestUser::class)->findOneBy(['name' => 'Ana']) ?? self::fail('Ana');
        $channel = $this->em->getRepository(ChatConversation::class)->find($channel->getId()) ?? self::fail('channel');

        $chat->send($ana, $channel, 'back');

        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE archived_at IS NOT NULL'), 'only the muted member stays closed');
        self::assertCount(1, $this->em->getUnitOfWork()->getIdentityMap()[ChatParticipant::class] ?? [], 'only the author\'s row was loaded');
        $mine = $this->em->getRepository(ChatParticipant::class)->findOneBy(['conversation' => $channel, 'user' => $ana]) ?? self::fail('row');
        self::assertNull($mine->getArchivedAt(), 'the managed row was read again');
    }

    public function testTheExportIsCompleteAndReleasesWhatItLoaded(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $dm   = $chat->openDirect($ana, $this->user('Luis'));
        $sent = $chat->send($ana, $dm, 'first');
        $chat->pin($ana, $sent, true);
        $rows       = ChatPersonalData::EXPORT_BATCH + 3;
        $connection = $this->em->getConnection();
        for ($i = 1; $i <= $rows; ++$i) {
            $connection->insert('nowo_chat_message', [
                'uuid'            => sprintf('00000000-0000-7000-8000-%s', str_pad((string) $i, 12, '0', STR_PAD_LEFT)),
                'conversation_id' => $dm->getId(),
                'author_id'       => $ana->getId(),
                'kind'            => ChatMessage::KIND_TEXT,
                'body'            => 'm' . $i,
                'body_html'       => '<p>m' . $i . '</p>',
                'created_at'      => '2026-11-15 10:00:00',
            ]);
        }
        $this->em->clear();
        $ana  = $this->em->getRepository(TestUser::class)->find($ana->getId()) ?? self::fail('Ana');
        $held = $this->em->getRepository(ChatMessage::class)->find($sent->getId());

        $export = $this->get(ChatPersonalData::class)->export($ana);

        self::assertCount($rows + 2, $export['messages'], 'every message (and the pin line), across pages');
        self::assertSame('first', $export['messages'][0]['text']);
        self::assertSame('m' . $rows, $export['messages'][$rows + 1]['text']);
        self::assertCount(1, $export['pins']);
        self::assertSame([$held], array_values($this->em->getUnitOfWork()->getIdentityMap()[ChatMessage::class] ?? []), 'the pages were released; what the caller held stays');
    }

    public function testQuickRepliesAndPinnedLinksAreCapped(): void
    {
        $ana        = $this->user('Ana');
        $dm         = $this->get(ChatService::class)->openDirect($ana, $this->user('Luis'));
        $connection = $this->em->getConnection();
        for ($i = 1; $i <= ChatQuickReply::MAX_PER_USER + 5; ++$i) {
            $connection->insert('nowo_chat_quick_reply', ['user_id' => $ana->getId(), 'title' => 't' . $i, 'body' => 'b', 'created_at' => '2026-11-15 10:00:00']);
        }
        for ($i = 1; $i <= ChatConversationLinkRepository::MAX_PINNED + 5; ++$i) {
            $connection->insert('nowo_chat_conversation_link', ['uuid' => sprintf('00000000-0000-7000-9000-%s', str_pad((string) $i, 12, '0', STR_PAD_LEFT)), 'conversation_id' => $dm->getId(), 'created_by_id' => $ana->getId(), 'url' => 'https://example.com/' . $i, 'host' => 'example.com', 'created_at' => '2026-11-15 10:00:00']);
        }

        self::assertCount(ChatQuickReply::MAX_PER_USER, $this->get(ChatQuickReplyRepository::class)->forUser($ana));
        self::assertCount(ChatConversationLinkRepository::MAX_PINNED, $this->get(ChatConversationLinkRepository::class)->forConversation($dm));
    }
}
