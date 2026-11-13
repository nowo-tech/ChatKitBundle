<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use InvalidArgumentException;
use LogicException;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\Digest\ChatDigestDispatcher;
use Nowo\ChatKitBundle\Digest\SendChatDigestMessage;
use Nowo\ChatKitBundle\Doctrine\ChannelKeyCollationListener;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ErasedChatUserReference;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingDigestSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefDirectory;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function assert;

/**
 * 1.27: the smaller pieces of the string references — the key helpers, the erased account by reference, the digest of a
 * string-keyed user, the collation listener's fields, and an id-mode host whose directory knows integer ids only.
 */
final class StringReferencePartsTest extends DatabaseTestCase
{
    private RefDirectory $directory;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['user_class' => null, 'doctrine' => ['user_reference' => 'id']]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = new RefDirectory();
        $container       = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $container->set(TestDirectory::class, $this->name() === 'testAnIdModeDirectoryWithoutReferencesNeverSeesNonIntegerKeys' ? new TestDirectory($this->em) : $this->directory);
    }

    public function testTheKeyHelpers(): void
    {
        $refs = new ChatUserReferences(ChatUserReferences::ID);
        self::assertSame('12', $refs->sqlValue(12));
        self::assertSame(12, (new ChatUserReferences())->sqlValue('12'));
        self::assertSame(['1', 'x'], $refs->ids([1, 'x']));
        self::assertSame(12, ChatUserReferences::idOrKey('12'));
        self::assertSame('012', ChatUserReferences::idOrKey('012'), 'not canonical: a reference');
        self::assertSame('a-1', ChatUserReferences::idOrKey('a-1'));
        self::assertSame(7, ChatUserReferences::idOrKey(7));
        $bob = new RefChatUser('bob', 'p1', 'Bob');
        self::assertTrue(ChatUserReferences::same($bob, new RefChatUser('bob', 'p2', 'Other name')));
        self::assertFalse(ChatUserReferences::same($bob, new RefChatUser('Bob', 'p1', 'Bob')));
        self::assertFalse(ChatUserReferences::same($bob, null));
        self::assertFalse(ChatUserReferences::same(new TestUser('A'), new TestUser('B')), 'two users without an id are never the same');
    }

    public function testAnErasedAccountByReference(): void
    {
        $gone = new ErasedChatUserReference('bob', 'pub-bob', 'Bob');
        self::assertNull($gone->getId());
        self::assertSame(['bob', 'pub-bob', 'Bob', '?', null, null], [$gone->getChatUserReference(), $gone->getChatPublicId(), $gone->getChatDisplayName(), $gone->getChatInitials(), $gone->getChatEmail(), $gone->getChatLocale()]);
        $this->expectException(InvalidArgumentException::class);
        new ErasedChatUserReference('bob ', 'pub', '');
    }

    public function testEraseFormerAccountByReference(): void
    {
        $ana  = $this->directory->add(new RefChatUser('ana', 'pub-ana', 'Ana'));
        $bob  = $this->directory->add(new RefChatUser('bob', 'pub-bob', 'Bob'));
        $chat = $this->get(ChatServiceInterface::class);
        $chat->send($bob, $chat->openDirect($ana, $bob), 'hi');
        $privacy = $this->get(ChatPersonalData::class);
        try {
            $privacy->eraseFormerAccount('bob', 'pub-bob', 'Bob');
            self::fail('a living account was erased as a former one');
        } catch (LogicException $exception) {
            self::assertStringContainsString('User bob still exists', $exception->getMessage());
        }
        $this->directory->forget($bob);
        $privacy->eraseFormerAccount('bob', 'pub-bob', 'Bob');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM nowo_chat_message WHERE author_id = 'bob'"));
    }

    public function testTheDigestOfAStringKeyedUser(): void
    {
        $sender     = new RecordingDigestSender();
        $dispatcher = new ChatDigestDispatcher(
            $this->directory,
            $this->get(ChatConversationRepository::class),
            $this->get(ChatMessageRepository::class),
            $this->get(ChatUserSettingsRepository::class),
            $this->get(ChatPresence::class),
            $this->get(ChatAccess::class),
            $this->get(ChatPresenter::class),
            new ChatMarkdownRenderer(),
            $sender,
            new ArrayAdapter(),
        );
        $ana  = $this->directory->add(new RefChatUser('ana', 'pub-ana', 'Ana'));
        $bob  = $this->directory->add(new RefChatUser('bob', 'pub-bob', 'Bob'));
        $chat = $this->get(ChatServiceInterface::class);
        $chat->send($ana, $chat->openDirect($ana, $bob), 'unread for bob');

        $dispatcher(new SendChatDigestMessage('Bob'));
        self::assertSame([], $sender->sent, 'a look-alike key gets nothing');
        $dispatcher(new SendChatDigestMessage('bob'));
        self::assertCount(1, $sender->sent);
        self::assertSame($bob, $sender->sent[0]['user']);
    }

    public function testAnIdModeDirectoryWithoutReferencesNeverSeesNonIntegerKeys(): void
    {
        $access = $this->get(ChatAccess::class);
        $access->reset();
        self::assertSame([], $access->usersByIds(['bob', '012', '-1']));
        // Such a directory cannot know a string reference: the former account is erased.
        self::assertCount(0, $this->get(ChatPersonalData::class)->eraseFormerAccount('bob', 'pub-bob'));
    }

    public function testARelationModeUserWithAReferenceFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.secret', 'x');
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('needs "doctrine.user_reference: id"');
        (new NowoChatKitExtension())->load([['profiles' => ['default' => ['user_class' => RefChatUser::class]]]], $container);
    }

    public function testTheCollationFieldsOfEveryUserColumn(): void
    {
        self::assertSame([ChannelKeyCollationListener::FIELD], ChannelKeyCollationListener::fieldsOf(ChatConversation::class, false));
        self::assertSame([ChannelKeyCollationListener::FIELD, 'supportOwnerRef', 'resolvedByRef', 'assigneeRef', 'directUserLow', 'directUserHigh'], ChannelKeyCollationListener::fieldsOf(ChatConversation::class, true));
        self::assertSame(['authorRef', 'deletedByRef', 'pinnedByRef'], ChannelKeyCollationListener::fieldsOf(ChatMessage::class, true));
        self::assertSame([], ChannelKeyCollationListener::fieldsOf(ChatMessage::class, false));
    }
}
