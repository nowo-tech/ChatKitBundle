<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\HostEntities;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestStiGuest;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestStiMember;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestStiStaff;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function assert;

/**
 * 2.0 (audit lesson W1-2, XS-I1 / XS-I2): `user_class` is a single-table-inheritance root (TestStiMember, with the
 * TestStiStaff and TestStiGuest subclasses). The private manager's reference to such a root is a database read: a host
 * user of a subclass is written by id like any other, and a user whose row the private manager cannot see fails clearly
 * (never Doctrine's "A new entity was found", never a silent null), as does an unsaved one; nothing is written then.
 *
 * Used on SQLite (tests/Integration) and on the servers of CHAT_KIT_RACE_DATABASE_URL (tests/Database).
 */
trait HostStiUserCases
{
    /**
     * @return array<string, mixed>
     */
    public static function stiConfig(): array
    {
        return ['profiles' => ['default' => ['user_class' => TestStiMember::class]]];
    }

    public function testHostUsersOfStiSubclassesAreWrittenByIdThroughTheRoot(): void
    {
        $host  = $this->stiHost();
        $agent = new TestStiStaff('Agent Staff');
        $gina  = new TestStiGuest('Gina');
        $host->persist($agent);
        $host->persist($gina);
        $host->flush();

        $chat    = $this->stiService(ChatServiceInterface::class);
        $direct  = $chat->openDirect($gina, $agent);
        $message = $chat->send($gina, $direct, 'hello');
        $chat->toggleReaction($agent, $message, '👍');
        $support = $chat->openSupport($gina);
        $chat->resolveSupport($agent, $support);

        $sql = $host->getConnection();
        self::assertSame((string) $gina->getId(), (string) $sql->fetchOne('SELECT author_id FROM nowo_chat_message WHERE id = ?', [$message->getId()]));
        self::assertSame((string) $agent->getId(), (string) $sql->fetchOne('SELECT user_id FROM nowo_chat_reaction'));
        self::assertSame((string) $agent->getId(), (string) $sql->fetchOne('SELECT resolved_by_id FROM nowo_chat_conversation WHERE id = ?', [$support->getId()]));
        self::assertSame(2, (int) $sql->fetchOne('SELECT COUNT(*) FROM test_sti_member'), 'no host user written again');
        self::assertTrue($host->contains($gina) && $host->contains($agent));
        $stored = $this->stiService(ChatMessageRepository::class)->find($message->getId());
        assert($stored instanceof ChatMessage);
        self::assertInstanceOf(TestStiGuest::class, $stored->getAuthor(), 'the private manager reads the subclass');
        self::assertNotSame($gina, $stored->getAuthor(), 'compare users from chat rows by id');
    }

    public function testAnStiUserTheChatCannotSeeOrThatIsUnsavedFailsClearly(): void
    {
        $host = $this->stiHost();
        $gone = new TestStiGuest('Gone');
        $host->persist($gone);
        $host->flush();
        // The row disappears behind the application's back (another request deleted it); the application still holds it.
        $host->getConnection()->executeStatement('DELETE FROM test_sti_member WHERE id = ?', [$gone->getId()]);
        $unsaved = new TestStiGuest('Unsaved');
        $host->persist($unsaved);
        $chat = $this->stiService(ChatServiceInterface::class);

        foreach (['not visible' => [$gone, 'is visible to its entity manager'], 'not saved yet' => [$unsaved, 'is not saved yet']] as $case => [$user, $expected]) {
            try {
                $chat->openSupport($user);
                self::fail($case . ': refused');
            } catch (LogicException $failure) {
                self::assertStringContainsString($expected, $failure->getMessage(), $case);
                self::assertStringContainsString($user::class, $failure->getMessage(), $case);
            }
            self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation'), $case . ': nothing written');
            self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant'), $case);
            self::assertTrue($host->isOpen(), $case);
        }
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($unsaved), 'the host\'s scheduled insert is kept');
    }

    private function stiHost(): EntityManagerInterface
    {
        $manager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function stiService(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $service = $container->get($id);
        assert($service instanceof $id);

        return $service;
    }
}
