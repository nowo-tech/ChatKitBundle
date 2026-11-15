<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Link\ChatLinkUrls;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationLinkRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageLinkRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use PDOException;

use function str_contains;

/**
 * 1.30 (Beacon spec 239): the rare branches of the units of work on SQLite (the two-session races run in
 * tests/Database): a first opening whose insert race cannot be resolved surfaces the conflict (409 on the API), and a
 * pinned link that a concurrent add made one too many is refused under the row lock, before its insert.
 */
final class ChatLoadReadinessEdgesTest extends DatabaseTestCase
{
    public function testALostDirectOpeningWhoseWinnerCannotBeReadSurfacesTheConflict(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $em      = $this->em;
        $counter = $this->get(QueryCounter::class);
        // The pair's row appears just before this insert, inside the same unit of work: it is rolled back with it, so
        // the winner cannot be read afterwards (what a caller's older snapshot sees on MySQL).
        $key             = (string) ChatConversation::direct($ana, $luis)->getDirectKey();
        $counter->before = static function (string $sql) use ($em, $key): bool {
            if (!str_contains($sql, 'SAVEPOINT nowo_chat_kit_row_') || str_contains($sql, 'RELEASE') || str_contains($sql, 'ROLLBACK')) {
                return false;
            }
            $em->getConnection()->insert('nowo_chat_conversation', ['uuid' => '00000000-0000-7000-8000-0000000000cc', 'kind' => 'direct', 'direct_key' => $key, 'last_message_at' => '2026-11-15 10:00:00', 'created_at' => '2026-11-15 10:00:00']);

            return true;
        };

        try {
            $this->get(ChatService::class)->openDirect($ana, $luis);
            self::fail('the conflict surfaces (409 on the API)');
        } catch (UniqueConstraintViolationException) {
        }
        self::assertTrue($this->em->contains($ana) || $this->em instanceof ChatKitEntityManager, 'the caller\'s entities stay managed');
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
    }

    public function testATransientFailureOfAnAutomaticReplyNeverFailsTheSentMessage(): void
    {
        $chat     = $this->get(ChatService::class);
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $dm       = $chat->openDirect($ana, $luis);
        $settings = $this->get(ChatUserSettingsRepository::class);
        $away     = $settings->forUser($ana);
        $away->setAutoReply(true, 'Away', 'range', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'), false);
        $settings->save($away);
        // The message commits; its automatic reply (a unit of work of its own, after the commit) cannot be written.
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof ChatMessage && $entity->getKind() === ChatMessage::KIND_AUTO) {
                        throw new DeadlockException(PdoDriverException::new(new PDOException('Deadlock found when trying to get lock')), null);
                    }
                }
            }
        });

        $message = $chat->send($luis, $dm, 'are you there?');

        self::assertNotNull($message->getId());
        self::assertSame(['are you there?'], $this->em->getConnection()->fetchFirstColumn('SELECT body FROM nowo_chat_message'));
    }

    public function testALinkThatAConcurrentAddMadeOneTooManyIsRefusedUnderTheLock(): void
    {
        $ana = $this->user('Ana');
        $dm  = $this->get(ChatService::class)->openDirect($ana, $this->user('Luis'));
        // The first count (before the unit of work) still sees room; the count under the row lock does not.
        $pinned = $this->createStub(ChatConversationLinkRepository::class);
        $pinned->method('countFor')->willReturnOnConsecutiveCalls(0, 1);
        $links = new ChatLinks($this->em, $this->get(ChatLinkUrls::class), $pinned, $this->get(ChatMessageLinkRepository::class), $this->get(ChatNotifier::class), maxPinned: 1);

        try {
            $links->add($ana, $dm, 'https://example.com/', null);
            self::fail('one too many');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('too_many_links', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation_link'));
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
    }
}
