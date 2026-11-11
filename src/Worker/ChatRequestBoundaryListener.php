<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Worker;

use Nowo\ChatKitBundle\Avatar\ChatAvatars;
use Nowo\ChatKitBundle\Doctrine\UserReferenceListener;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.26.3 (REQ-RUNTIME-002): clears the bundle's per-request memos at every request boundary the bundle can see, so a
 * FrankenPHP worker with the kernel kept alive and NOT reset (`FRANKENPHP_RESET_KERNEL=false`, no `services_resetter`
 * between requests) never serves one request with state of the previous one: the access-filtered reference cards of
 * ChatReferences (a revoked item kept its card), the avatar URLs of ChatAvatars, the managed settings rows of
 * ChatUserSettingsRepository and the resolved-entity map of UserReferenceListener (id mode).
 *
 * Boundaries: the main `kernel.request` (before the firewall), `kernel.terminate`, `console.terminate`, and Messenger
 * `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` for consumers. The memos keep `ResetInterface`
 * (`kernel.reset`) as well; this listener is what makes them correct without it. Sub-requests keep the memo of their
 * main request.
 *
 * @internal
 */
final class ChatRequestBoundaryListener implements EventSubscriberInterface
{
    /** @var list<ResetInterface> */
    private readonly array $memos;

    /**
     * @param ChatReferences $references Access-filtered reference cards per viewer
     * @param ChatAvatars $avatars Avatar URLs per person
     * @param ChatUserSettingsRepository $settings Settings rows per user
     * @param UserReferenceListener|null $userReferences Resolved entities (registered in `doctrine.user_reference: id` only)
     */
    public function __construct(
        ChatReferences $references,
        ChatAvatars $avatars,
        ChatUserSettingsRepository $settings,
        ?UserReferenceListener $userReferences = null,
    ) {
        $memos = [$references, $avatars, $settings];
        if ($userReferences instanceof UserReferenceListener) {
            $memos[] = $userReferences;
        }
        $this->memos = $memos;
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Before the firewall (8) and every bundle listener: the request starts from empty memos.
            KernelEvents::REQUEST            => ['onKernelRequest', 2048],
            KernelEvents::TERMINATE          => ['clear', -2048],
            ConsoleEvents::TERMINATE         => ['clear', -2048],
            WorkerMessageHandledEvent::class => ['clear', -2048],
            WorkerMessageFailedEvent::class  => ['clear', -2048],
        ];
    }

    /**
     * Clears the memos at the start of a main request (a sub-request shares its main request's memos).
     *
     * @param RequestEvent $event The request event
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->clear();
        }
    }

    /**
     * Clears every memo (end of a request, a command or a Messenger message).
     */
    public function clear(): void
    {
        foreach ($this->memos as $memo) {
            $memo->reset();
        }
    }
}
