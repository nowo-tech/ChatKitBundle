<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Worker;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.26.3 (REQ-RUNTIME-002): clears the bundle's per-request memos at every request boundary the bundle can see, so a
 * long-lived kernel where the services resetter does not run (a host that removes or bypasses `services_resetter` or
 * drives `http_kernel` directly, `messenger:consume --no-reset`, long-running console commands) never serves one
 * request, message or command with state of the previous one: the access-filtered reference cards of ChatReferences (a
 * revoked item kept its card), the avatar URLs of ChatAvatars, the managed settings rows of ChatUserSettingsRepository
 * and the resolved-entity map of UserReferenceListener (id mode).
 *
 * Boundaries: the main `kernel.request` (before the firewall), `kernel.terminate`, `console.terminate`, and Messenger
 * `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` for consumers. The memos keep `ResetInterface`
 * (`kernel.reset`) as well; this listener is what makes them correct without it. Sub-requests keep the memo of their
 * main request.
 *
 * @internal
 */
final class ChatRequestBoundaryListener
{
    /**
     * @param iterable<ResetInterface> $memos The memos to empty. The bundle wires a lazy iterator of references that
     *                                        skip services not built yet (`IGNORE_ON_UNINITIALIZED_REFERENCE`), so a
     *                                        request that never touched the chat builds none of them: only memos
     *                                        that exist can hold state, and those are emptied.
     */
    public function __construct(
        private readonly iterable $memos,
    ) {
    }

    /**
     * Clears the memos at the start of a main request (a sub-request shares its main request's memos).
     *
     * Priority 2048: before the firewall (8) and every bundle listener, so the request starts from empty memos.
     *
     * @param RequestEvent $event The request event
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 2048)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->clear();
        }
    }

    /**
     * Clears every memo (end of a request, a command or a Messenger message).
     */
    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -2048)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -2048)]
    #[AsEventListener(event: WorkerMessageHandledEvent::class, priority: -2048)]
    #[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -2048)]
    public function clear(): void
    {
        foreach ($this->memos as $memo) {
            $memo->reset();
        }
    }
}
