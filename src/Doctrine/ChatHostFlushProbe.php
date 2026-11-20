<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.39 (REQ-ARCH-001 rule 6 deprecation layer): ChatKit's side of nowo-tech/transaction-doctrine's host-flush probe.
 * Without `doctrine.private_entity_manager: true` (host mode) every chat write flushes the application's entity
 * manager, and with it the application's own pending changes. Right before each of those flushes this probe lists the
 * entity classes outside `Nowo\ChatKitBundle\Entity` with pending work and triggers one `E_USER_DEPRECATED` per class
 * and request ("Your pending changes to App\X were flushed by NowoChatKitBundle; 2.0 no longer does."): in 2.0 the
 * chat writes only through its private manager and those changes are no longer written by it.
 *
 * Read-only: it never flushes, clears, closes, detaches or resets anything. With the private manager (or on
 * {@see ChatKitEntityManager}) it does nothing.
 *
 * State: the classes already reported in the current request, message or command, held by the
 * {@see HostFlushProbe} (transaction-doctrine's shared service when its bundle is registered, else an own instance).
 * Emptied by {@see self::reset()} at every boundary (`ChatRequestBoundaryListener`, kernel reset off included) and at
 * `kernel.reset`.
 *
 * @internal
 */
final class ChatHostFlushProbe implements ResetInterface
{
    /** The bundle name the probe's message shows. */
    public const string BUNDLE_NAME = 'NowoChatKitBundle';

    private readonly HostFlushProbe $probe;

    /**
     * @param HostFlushProbe|null $probe transaction-doctrine's shared probe (`nowo_transaction_doctrine.host_flush_probe`); null creates an own one
     * @param bool $privateManager Whether `doctrine.private_entity_manager` is true (the probe is then off)
     */
    public function __construct(
        ?HostFlushProbe $probe = null,
        private readonly bool $privateManager = false,
    ) {
        $this->probe = $probe ?? new HostFlushProbe();
    }

    /**
     * Reports the application's pending changes the next flush of the manager would write on ChatKit's behalf. Call it
     * right before ChatKit flushes that manager; it changes nothing.
     *
     * @param EntityManagerInterface $entityManager The manager ChatKit is about to flush
     *
     * @return list<class-string> The foreign entity classes with pending work (empty with the private manager)
     */
    public function beforeFlush(EntityManagerInterface $entityManager): array
    {
        if ($this->privateManager || $entityManager instanceof ChatKitEntityManager) {
            return [];
        }

        return $this->probe->beforeHostFlush($entityManager, self::BUNDLE_NAME, [ChatKitManagerRegistry::ENTITY_NAMESPACE]);
    }

    /** Forgets what was reported: a new request, message or command starts. */
    public function reset(): void
    {
        $this->probe->reset();
    }
}
