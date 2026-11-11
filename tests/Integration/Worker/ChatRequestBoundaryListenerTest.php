<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Worker;

use LogicException;
use Nowo\ChatKitBundle\Avatar\ChatAvatars;
use Nowo\ChatKitBundle\Doctrine\UserReferenceListener;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\CountingAvatarResolver;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;
use ReflectionProperty;
use stdClass;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use WeakMap;

use function count;
use function is_array;

/**
 * 1.26.3 (REQ-RUNTIME-002): the bundle registers its own boundary listener on the main `kernel.request`,
 * `kernel.terminate`, `console.terminate` and the Messenger worker events, and each of them empties the reference,
 * avatar and settings memos (which `kernel.reset` alone did before). A sub-request keeps its main request's memos.
 */
final class ChatRequestBoundaryListenerTest extends DatabaseTestCase
{
    public function testTheListenerIsRegisteredOnEveryBoundary(): void
    {
        $dispatcher = $this->get(EventDispatcherInterface::class);
        foreach ([KernelEvents::REQUEST, KernelEvents::TERMINATE, ConsoleEvents::TERMINATE, WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $event) {
            $found = false;
            foreach ($dispatcher->getListeners($event) as $listener) {
                if (is_array($listener) && $listener[0] instanceof ChatRequestBoundaryListener) {
                    $found = true;
                }
            }
            self::assertTrue($found, $event . ' clears the chat memos');
        }
    }

    public function testEveryBoundaryEmptiesTheMemos(): void
    {
        $listener = $this->get(ChatRequestBoundaryListener::class);
        foreach (['request', 'clear'] as $boundary) {
            [$references, $avatars, $settings, $resolver, $user, $first] = $this->fillMemos();
            if ($boundary === 'request') {
                $listener->onKernelRequest(new RequestEvent($this->kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
            } else {
                $listener->clear();
            }
            self::assertSame(0, $this->resolvedCount($references), $boundary . ': the reference cards are gone');
            $calls = count($resolver->calls);
            $avatars->urlOf($user);
            self::assertCount($calls + 1, $resolver->calls, $boundary . ': the avatar is asked again');
            self::assertNotSame($first, $settings->forUser($user), $boundary . ': the settings are read again');
        }
    }

    public function testASubRequestKeepsTheMainRequestsMemos(): void
    {
        [$references, , , $resolver, $user] = $this->fillMemos();
        $this->get(ChatRequestBoundaryListener::class)->onKernelRequest(new RequestEvent($this->kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $this->resolvedCount($references));
        $calls = count($resolver->calls);
        $this->get(ChatAvatars::class)->urlOf($user);
        self::assertCount($calls, $resolver->calls);
    }

    public function testTheIdModeListenerIsClearedToo(): void
    {
        $userReferences = new UserReferenceListener(static fn () => throw new LogicException('not used'), 'REDACTED-TEST');
        $entity         = new stdClass();
        /** @var WeakMap<object, true> $resolved */
        $resolved          = (new ReflectionProperty(UserReferenceListener::class, 'resolved'))->getValue($userReferences);
        $resolved[$entity] = true;
        $listener          = new ChatRequestBoundaryListener($this->get(ChatReferences::class), $this->get(ChatAvatars::class), $this->get(ChatUserSettingsRepository::class), $userReferences);

        $listener->clear();

        self::assertFalse($userReferences->isResolved($entity));
    }

    /**
     * @return array{ChatReferences, ChatAvatars, ChatUserSettingsRepository, CountingAvatarResolver, TestUser, object}
     */
    private function fillMemos(): array
    {
        $user       = $this->user('Ana ' . count($this->get(CountingAvatarResolver::class)->calls));
        $chat       = $this->get(ChatService::class);
        $channel    = $chat->openChannel($user, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $message    = $chat->send($user, $channel, 'see #[x](task:1)');
        $references = $this->get(ChatReferences::class);
        $references->prime($user, [$message]);
        self::assertSame(1, $this->resolvedCount($references));
        $avatars  = $this->get(ChatAvatars::class);
        $resolver = $this->get(CountingAvatarResolver::class);
        $avatars->urlOf($user);
        $calls = count($resolver->calls);
        $avatars->urlOf($user);
        self::assertCount($calls, $resolver->calls, 'memoised');
        $settings = $this->get(ChatUserSettingsRepository::class);
        $first    = $settings->forUser($user);
        self::assertSame($first, $settings->forUser($user), 'memoised');

        return [$references, $avatars, $settings, $resolver, $user, $first];
    }

    private function resolvedCount(ChatReferences $references): int
    {
        $resolved = (new ReflectionProperty(ChatReferences::class, 'resolved'))->getValue($references);
        self::assertIsArray($resolved);

        return count($resolved);
    }
}
