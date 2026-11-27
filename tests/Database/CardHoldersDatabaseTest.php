<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;

use function array_map;

/**
 * 2.8 (spec 260, audit ZCW-I2): ChatMessageRepository::findCardHolders() on real servers (MySQL 8.4, MariaDB 11.4,
 * PostgreSQL in CI): DQL `LIKE … ESCAPE '!'` keeps `_` literal, the window floor counts top-level messages only and the
 * replies posted since are found, deleted messages and other conversations are left out.
 */
final class CardHoldersDatabaseTest extends LoadDatabaseTestCase
{
    public function testHoldersOnARealServer(): void
    {
        $ana   = $this->user('Ana');
        $luis  = $this->user('Luis');
        $dm    = ChatConversation::direct($ana, $luis);
        $other = ChatConversation::direct($ana, $this->user('Eve'));
        $this->em->persist($dm);
        $this->em->persist($other);
        $holder = new ChatMessage($dm, $ana, 'see #[…](note:n_1)', '<p>card</p>');
        $decoy  = new ChatMessage($dm, $ana, '#[…](note:nx1)', '<p>decoy</p>');
        $gone   = new ChatMessage($dm, $ana, '#[…](note:n_1)', '<p>gone</p>');
        $gone->delete($ana);
        $last = new ChatMessage($dm, $ana, 'newest top-level', '<p>newest</p>');
        foreach ([$holder, $decoy, $gone, $last, new ChatMessage($other, $ana, '#[…](note:n_1)', '<p>other</p>')] as $message) {
            $this->em->persist($message);
        }
        $this->em->flush();
        $reply = new ChatMessage($dm, $luis, 'joined #[…](note:n_1)', '<p>reply</p>');
        $reply->replyIn($holder);
        $this->em->persist($reply);
        $this->em->flush();

        $repository = $this->get(ChatMessageRepository::class);
        $uuids      = static fn (array $messages): array => array_map(static fn (ChatMessage $message): string => $message->getUuid(), $messages);
        self::assertSame([$reply->getUuid(), $holder->getUuid()], $uuids($repository->findCardHolders($dm, 'note', 'n_1', 1000, 20)));
        self::assertSame([$decoy->getUuid()], $uuids($repository->findCardHolders($dm, 'note', 'nx1', 1000, 20)));
        self::assertSame([], $repository->findCardHolders($dm, 'note', 'nz1', 1000, 20), '`_` is literal');
        self::assertSame([$reply->getUuid()], $uuids($repository->findCardHolders($dm, 'note', 'n_1', 1, 20)), 'window of one top-level message: the reply since');
        self::assertSame([$reply->getUuid()], $uuids($repository->findCardHolders($dm, 'note', 'n_1', 1000, 1)), 'limit');
    }
}
