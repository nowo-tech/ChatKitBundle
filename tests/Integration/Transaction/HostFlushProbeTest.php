<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Transaction;

use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatHostFlushProbe;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Deprecation\DeprecationRecorder;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;

use function sprintf;

/**
 * 1.39 (REQ-ARCH-001 rule 6 deprecation layer): without `doctrine.private_entity_manager` every chat write flushes the
 * application's manager. The host-flush probe reports each application entity class with pending changes in that flush,
 * once per class and request, and changes nothing: the 1.38 behaviour (the chat write flushes them) stays.
 */
final class HostFlushProbeTest extends DatabaseTestCase
{
    private const string MARKER = 'flushed by NowoChatKitBundle';

    public function testAChatWriteReportsTheApplicationsPendingChangesOncePerClassAndRequest(): void
    {
        $this->sharedManagerOnly();
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatServiceInterface::class);
        $dm   = $chat->openDirect($ana, $luis);
        $ana->setChatLocale('es');
        $this->em->persist(new TestUser('Pending', 'pending@example.test'));

        [, $first] = DeprecationRecorder::record(self::MARKER, static fn (): mixed => $chat->send($ana, $dm, 'hello'));
        $luis->setChatLocale('fr');
        [, $second] = DeprecationRecorder::record(self::MARKER, static fn (): mixed => $chat->send($ana, $dm, 'again'));

        self::assertSame([sprintf('Your pending changes to %s were flushed by NowoChatKitBundle; 2.0 no longer does.', TestUser::class)], $first);
        self::assertSame([], $second, 'once per class and request');
        // Read-only: the chat write still flushed the application's changes, as in 1.38.
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM test_user'));
        self::assertSame('es', $this->em->getConnection()->fetchOne('SELECT locale FROM test_user WHERE id = ?', [$ana->getId()]));
        self::assertSame('fr', $this->em->getConnection()->fetchOne('SELECT locale FROM test_user WHERE id = ?', [$luis->getId()]));
        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->em->contains($ana), 'nothing detached');
    }

    public function testTheBoundaryForgetsWhatWasReported(): void
    {
        $this->sharedManagerOnly();
        $ana  = $this->user('Ana');
        $chat = $this->get(ChatServiceInterface::class);
        $dm   = $chat->openDirect($ana, $this->user('Luis'));

        $reports = [];
        for ($request = 0; $request < 3; ++$request) {
            $ana->setChatLocale('l' . $request);
            [, $reports[]] = DeprecationRecorder::record(self::MARKER, static fn (): mixed => $chat->send($ana, $dm, 'message ' . $request));
            $this->get(ChatRequestBoundaryListener::class)->clear();
        }

        self::assertSame([1, 1, 1], array_map(count(...), $reports), 'every request reports again');
    }

    public function testNothingPendingReportsNothing(): void
    {
        $this->sharedManagerOnly();
        $ana  = $this->user('Ana');
        $chat = $this->get(ChatServiceInterface::class);

        [, $messages] = DeprecationRecorder::record(self::MARKER, function () use ($chat, $ana): void {
            $dm = $chat->openDirect($ana, $this->user('Luis'));
            $this->em->flush(); // the test's own user insert above is the application's, flushed by the application
            $chat->send($ana, $dm, 'hello');
            $message = $chat->send($ana, $dm, 'world');
            $chat->toggleReaction($ana, $message, '👍');
            $this->get(ChatLinks::class)->add($ana, $dm, 'https://example.test/a', 'A');
        });

        self::assertSame([], $messages, 'ChatKit\'s own rows and the users it only reads are never reported');
    }

    public function testTheProbeIsOffWithThePrivateManager(): void
    {
        $probe = new ChatHostFlushProbe(null, true);
        $ana   = $this->user('Ana');
        $ana->setChatLocale('es');

        [$classes, $messages] = DeprecationRecorder::record(self::MARKER, fn (): array => $probe->beforeFlush($this->em));

        self::assertSame([], $classes);
        self::assertSame([], $messages);
        self::assertTrue($this->em->getUnitOfWork()->isEntityScheduled($ana) || $this->em->contains($ana));
    }

    private function sharedManagerOnly(): void
    {
        if ($this->em instanceof ChatKitEntityManager) {
            self::markTestSkipped('The application\'s manager is ChatKit\'s here only without the private manager.');
        }
    }
}
