<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\UnresolvedChatUser;
use Nowo\ChatKitBundle\Doctrine\UserReferenceListener;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ErasedChatUser;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;

use function assert;

/**
 * 1.24 `doctrine.user_reference: id`: no relation and no foreign key to the user table (string columns with the user
 * id, indexed), the users loaded through the directory in one call per batch after the rows, a user the directory no
 * longer knows as null or an UnresolvedChatUser, and the queries of the repositories and the privacy service working on
 * the reference columns.
 */
final class UserReferenceIdModeTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['doctrine' => ['user_reference' => 'id']]]];
    }

    public function testTheSchemaHasNoForeignKeyToTheUserTable(): void
    {
        self::assertTrue($this->get(ChatUserReferences::class)->isIdMode());
        $metadata = array_values(array_filter(
            $this->em->getMetadataFactory()->getAllMetadata(),
            static fn (ClassMetadata $class): bool => str_starts_with($class->getName(), 'Nowo\\ChatKitBundle\\Entity\\'),
        ));
        $schema = (new SchemaTool($this->em))->getSchemaFromMetadata($metadata);
        foreach ($metadata as $class) {
            foreach ($class->getAssociationMappings() as $mapping) {
                self::assertNotSame(ChatUserInterface::class, $mapping->targetEntity, $class->getName());
            }
            foreach ($schema->getTable($class->getTableName())->getForeignKeys() as $foreignKey) {
                self::assertNotSame('test_user', $foreignKey->getForeignTableName(), $class->getName());
            }
            self::assertSame(array_map(static fn (string $relation): string => $relation . 'Ref', array_keys(UserReferenceListener::RELATIONS[$class->getName()] ?? [])), UserReferenceListener::referenceFields($class));
        }
        $messages = $schema->getTable('nowo_chat_message');
        self::assertSame(64, $messages->getColumn('author_id')->getLength());
        self::assertFalse($messages->getColumn('author_id')->getNotnull());
        self::assertTrue($schema->getTable('nowo_chat_participant')->getColumn('user_id')->getNotnull());
        self::assertTrue($messages->hasIndex('idx_nowo_chat_msg_author'));
        self::assertTrue($schema->getTable('nowo_chat_reaction')->hasIndex('idx_nowo_chat_reaction_user'));
    }

    public function testUsersAreLoadedThroughTheDirectoryInOneCallPerBatch(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $bea  = $this->user('Bea');
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($ana, $dm, 'one');
        $chat->send($luis, $dm, 'two');
        $hello = $chat->send($ana, $dm, 'hello @[Luis](' . $luis->getChatPublicId() . ')');
        $chat->toggleReaction($bea, $hello, '👍');
        $chat->pin($luis, $hello, true);
        $acme = $chat->openChannel($bea, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($bea, $acme, 'channel');
        $raw = $this->em->getConnection()->fetchOne('SELECT author_id FROM nowo_chat_message WHERE id = ?', [$hello->getId()]);
        self::assertSame((string) $ana->getId(), $raw, 'the id, as text');
        $this->em->clear();

        $counter = $this->get(QueryCounter::class);
        $counter->clear();
        $conversation = $this->get(ChatConversationRepository::class)->findOneByUuid($dm->getUuid());
        self::assertInstanceOf(ChatConversation::class, $conversation);
        $page = $this->get(ChatMessageRepository::class)->findPage($conversation, 30);
        self::assertSame(['Ana', 'Luis', 'Ana', 'Luis'], array_map(static fn (ChatMessage $message): ?string => $message->getAuthor()?->getChatDisplayName(), $page), 'the pin line too');
        self::assertCount(1, array_filter($counter->queries(), static fn (string $sql): bool => str_contains($sql, 'test_user')), 'one directory call for the page');
        self::assertSame('Luis', $page[2]->getPinnedBy()?->getChatDisplayName());
        self::assertSame([(string) $ana->getId(), (string) $luis->getId(), null], [$page[2]->getAuthorReference(), $page[2]->getPinnedByReference(), $page[2]->getDeletedByReference()], 'readable without loading the user');
        $listener = $this->get(UserReferenceListener::class);
        self::assertTrue($listener->isResolved($page[0]));

        $reactions = $this->get(ChatReactionRepository::class)->summaryFor([(int) $page[2]->getId()]);
        self::assertSame(['👍' => [$bea->getChatPublicId()]], $reactions[(int) $page[2]->getId()]);
        self::assertSame([(int) $luis->getId()], $this->get(ChatMessageMentionRepository::class)->userIdsOf($page[2]));
        self::assertSame([(int) $luis->getId()], $this->get(ChatConversationRepository::class)->findDirectPeerIds($ana));
        self::assertSame(['Luis'], array_map(static fn (ChatParticipant $row): string => $row->getUser()->getChatDisplayName(), $this->get(ChatParticipantRepository::class)->findReaders($conversation, $ana)));
        self::assertInstanceOf(ChatParticipant::class, $this->get(ChatParticipantRepository::class)->findOneFor($conversation, $ana));
        self::assertCount(2, $this->get(ChatParticipantRepository::class)->findForUserIds($conversation, [(int) $ana->getId(), (int) $luis->getId()]));
        self::assertSame([], $this->get(ChatParticipantRepository::class)->findForUserIds($conversation, []));
        $list = $this->get(ChatConversationRepository::class)->findListForUser($bea, [TestChannelProvider::KEY], false, 10);
        self::assertSame([$acme->getUuid()], array_map(static fn (ChatConversation $row): string => $row->getUuid(), $list));
        self::assertSame(1, $this->get(ChatMessageRepository::class)->countUnreadByConversation((int) $luis->getId(), [(int) $conversation->getId()])[(int) $conversation->getId()] ?? null);
        self::assertCount(2, $this->get(ChatUserSettingsRepository::class)->forUsers([$ana, $luis]));
    }

    public function testAUserTheDirectoryNoLongerKnows(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $gone = $this->user('Gone');
        $dm   = $chat->openDirect($ana, $gone);
        $chat->send($gone, $dm, 'bye');
        $chat->toggleReaction($gone, $chat->send($ana, $dm, 'ok'), '👍');
        $goneId = (int) $gone->getId();
        // The host deletes the account without ChatPersonalData::erase(): no foreign key, the rows stay.
        $this->em->getConnection()->executeStatement('DELETE FROM test_user WHERE id = ?', [$goneId]);
        $this->em->clear();

        $conversation = $this->get(ChatConversationRepository::class)->findOneByUuid($dm->getUuid());
        assert($conversation instanceof ChatConversation);
        $page = $this->get(ChatMessageRepository::class)->findPage($conversation, 30);
        self::assertNull($page[0]->getAuthor(), 'a nullable relation: null, like "Deleted user"');
        $reaction = $this->em->getRepository(ChatReaction::class)->findOneBy(['message' => $page[1]->getId()]);
        assert($reaction instanceof ChatReaction);
        $former = $reaction->getUser();
        self::assertInstanceOf(UnresolvedChatUser::class, $former);
        self::assertSame($goneId, $former->getId());
        self::assertStringStartsWith('former-', $former->getChatPublicId());
        self::assertSame(['', '?', null, null], [$former->getChatDisplayName(), $former->getChatInitials(), $former->getChatEmail(), $former->getChatLocale()]);
        // Audit KC-L3: an HMAC keyed with kernel.secret, and left out of reaction summaries.
        self::assertSame('former-' . substr(hash_hmac('sha256', 'nowo_chat_former_user|' . $goneId, 'chat-kit-test'), 0, 24), $former->getChatPublicId());
        self::assertNotSame($former->getChatPublicId(), (new UnresolvedChatUser((string) $goneId, 'other'))->getChatPublicId());
        self::assertSame([], $this->get(ChatReactionRepository::class)->summaryFor([(int) $page[1]->getId()]));
        self::assertNull((new UnresolvedChatUser('abc', 's'))->getId());
        self::assertFalse($this->get(ChatAccess::class)->isReachable($former), 'never reachable');

        // Erasing by id cleans the rows that point at it.
        $this->get(ChatPersonalData::class)->erase($former);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_reaction WHERE user_id = ?', [(string) $goneId]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message WHERE author_id = ?', [(string) $goneId]));
    }

    public function testSupportTicketsAndErasureOnTheReferenceColumns(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $agent   = $this->user('Agent Ada');
        $support = $chat->openSupport($ana);
        $chat->send($ana, $support, 'help');
        $support->assign($agent);
        $chat->resolveSupport($agent, $support);
        $mention = $chat->send($agent, $chat->openDirect($agent, $ana), 'hi @[Ana](' . $ana->getChatPublicId() . ')');
        $this->em->clear();

        $conversations = $this->get(ChatConversationRepository::class);
        $reloaded      = $conversations->findSupportFor($ana);
        self::assertInstanceOf(ChatConversation::class, $reloaded);
        self::assertSame(['Ana', 'Agent Ada', 'Agent Ada'], [$reloaded->getSupportOwner()?->getChatDisplayName(), $reloaded->getAssignee()?->getChatDisplayName(), $reloaded->getResolvedBy()?->getChatDisplayName()]);
        self::assertSame([$reloaded->getUuid()], array_map(static fn (ChatConversation $row): string => $row->getUuid(), $conversations->findSupportInbox('mine', $agent, 10)));
        self::assertSame(1, $this->get(ChatMessageMentionRepository::class)->countForUser($ana, [], false));

        $this->get(ChatPersonalData::class)->erase($ana);
        $this->em->clear();
        self::assertNull($conversations->findSupportFor($ana));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE user_id = ?', [(string) $ana->getId()]));
        self::assertNull($this->em->getRepository(ChatMessageMention::class)->findOneBy(['message' => $mention->getId()]));
    }

    public function testErasingAnAccountThatIsAlreadyGone(): void
    {
        $chat                = $this->get(ChatService::class);
        $ana                 = $this->user('Ana');
        $gone                = $this->user('Luis Hidden');
        $dm                  = $chat->openDirect($ana, $gone);
        $hi                  = $chat->send($ana, $dm, 'hi @[Luis Hidden](' . $gone->getChatPublicId() . ')');
        $acme                = $chat->openChannel($gone, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $line                = $chat->postSystemLine($acme, '%actor% joined', null, ['actor' => 'Luis Hidden']);
        [$goneId, $publicId] = [(int) $gone->getId(), $gone->getChatPublicId()];
        $this->em->getConnection()->executeStatement('DELETE FROM test_user WHERE id = ?', [$goneId]);
        $this->em->clear();

        // The placeholder alone knows only the id: rows by reference go, the mention token stays (audit KC-L2).
        $this->get(ChatPersonalData::class)->erase(new UnresolvedChatUser((string) $goneId, 's'));
        $this->em->clear();
        self::assertStringContainsString($publicId, (string) $this->em->find(ChatMessage::class, $hi->getId())?->getBody());

        // What the host kept (id, public id, name) erases the rest.
        $erasure = $this->get(ChatPersonalData::class)->eraseFormerAccount($goneId, $publicId, 'Luis Hidden');
        $this->em->clear();
        self::assertSame([(int) $hi->getId()], $erasure->messageIds);
        self::assertStringNotContainsString($publicId, (string) $this->em->find(ChatMessage::class, $hi->getId())?->getBody());
        self::assertSame(['actor' => '…'], $this->em->find(ChatMessage::class, $line->getId())?->getMeta()['params'] ?? null, 'audit KC-M1 in id mode');
        // Audit KC-L5: a living account is never erased by id.
        try {
            $this->get(ChatPersonalData::class)->eraseFormerAccount((int) $ana->getId(), $ana->getChatPublicId(), 'Ana');
            self::fail('a living account is refused');
        } catch (LogicException $exception) {
            self::assertStringContainsString('still exists', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message WHERE author_id = ? AND deleted_at IS NULL', [(string) $ana->getId()]));
        $bare = new ChatPersonalData($this->em, $this->get(ChatUserSettingsRepository::class), $this->get(ChatConversationRepository::class), refs: new ChatUserReferences(ChatUserReferences::ID));
        try {
            $bare->eraseFormerAccount($goneId, $publicId);
            self::fail('no directory, no check');
        } catch (LogicException $exception) {
            self::assertStringContainsString('ChatDirectoryInterface', $exception->getMessage());
        }
        $former = new ErasedChatUser(7, 'p', 'N');
        self::assertSame([7, 'p', 'N', '?', null, null], [$former->getId(), $former->getChatPublicId(), $former->getChatDisplayName(), $former->getChatInitials(), $former->getChatEmail(), $former->getChatLocale()]);
    }
}
