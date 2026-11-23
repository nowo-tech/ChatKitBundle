<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReadMark;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;

/**
 * 1.35 (audit TK-2): turning "Share read times" off deletes the user's stored read times in the same flush (and so the
 * same transaction), whatever wrote the setting: the settings endpoint, the host's `ChatPreferencesType`, a DDD host
 * writing `ChatUserSettings`. Only exactly this user's participant rows are used (REQ-SEC-006 item 2: a folding user
 * column may return a look-alike's).
 *
 * Stateless (REQ-RUNTIME-002).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class ChatReadTimesOptOutListener
{
    /**
     * Creates the ChatReadTimesOptOutListener with its collaborators.
     */
    public function __construct(
        private ChatUserReferences $refs = new ChatUserReferences(),
    ) {
    }

    /**
     * Deletes the read times of every user whose `shareReadTimes` goes from true to false in this flush (or whose
     * settings are first saved with it off).
     *
     * @param OnFlushEventArgs $event The flush
     */
    public function onFlush(OnFlushEventArgs $event): void
    {
        $manager = $event->getObjectManager();
        $work    = $manager->getUnitOfWork();
        // A first save with sharing off counts too: the unsaved defaults shared read times.
        foreach (array_merge($work->getScheduledEntityUpdates(), $work->getScheduledEntityInsertions()) as $entity) {
            if (!$entity instanceof ChatUserSettings || $entity->isShareReadTimes()) {
                continue;
            }
            $change = $work->getEntityChangeSet($entity)['shareReadTimes'] ?? null;
            if (!$work->isScheduledForInsert($entity) && ($change === null || $change[0] !== true)) {
                continue;
            }
            $user = $entity->getUser();
            /** @var list<ChatParticipant> $rows */
            $rows = $manager->createQueryBuilder()->select('p')->from(ChatParticipant::class, 'p')
                ->where($this->refs->field('p', 'user') . ' = :user')
                ->setParameter('user', $this->refs->value($user))
                ->getQuery()
                ->getResult();
            $ids = array_values(array_map(
                static fn (ChatParticipant $row): int => (int) $row->getId(),
                array_filter($rows, static fn (ChatParticipant $row): bool => ChatUserReferences::same($row->getUser(), $user)),
            ));
            if ($ids !== []) {
                $manager->createQueryBuilder()->delete(ChatReadMark::class, 'r')
                    ->where('IDENTITY(r.participant) IN (:ids)')
                    ->setParameter('ids', $ids)
                    ->getQuery()
                    ->execute();
                // 2.0: read times the manager held are forgotten (the DQL delete bypassed it).
                ChatKitManagedRows::forgetGone($manager, [ChatReadMark::class]);
            }
        }
    }
}
