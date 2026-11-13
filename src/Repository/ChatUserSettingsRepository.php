<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Loads per-user chat settings, falling back to unsaved defaults. `forUser()` is memoized per user id until
 * `reset()`, called at every request / command / message boundary by ChatRequestBoundaryListener (1.26.3) and on
 * `kernel.reset`, so a FrankenPHP worker with kernel reset false never carries it to the next request:
 * per-row checks such as the API's presence visibility read the table once per request, not once per row.
 *
 * @extends ServiceEntityRepository<ChatUserSettings>
 */
class ChatUserSettingsRepository extends ServiceEntityRepository implements ResetInterface
{
    /** @var array<int|string, ChatUserSettings> user key => settings loaded in this request */
    private array $memo = [];

    /**
     * Builds the repository for ChatUserSettings entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatUserSettings::class);
    }

    /**
     * Stored settings, or unsaved defaults (persisted only when the user changes something).
     *
     * @param ChatUserInterface $user The user
     *
     * @return ChatUserSettings The stored or default settings
     */
    public function forUser(ChatUserInterface $user): ChatUserSettings
    {
        $id = ChatUserReferences::key($user);
        if ($id === null) {
            return new ChatUserSettings($user);
        }

        $memo = $this->memo[$id] ?? null;
        // A stored row detached by EntityManager::clear() is read again (it would no longer be flushed).
        if ($memo instanceof ChatUserSettings && ($memo->getId() === null || $this->getEntityManager()->contains($memo))) {
            return $memo;
        }

        $found = $this->findOneBy($this->refs->criteria('user', $user));
        // REQ-SEC-006 item 2: the row of exactly this user (a folding collation may return a look-alike's row).
        if ($found instanceof ChatUserSettings && !ChatUserReferences::same($found->getUser(), $user)) {
            $found = null;
        }

        return $this->memo[$id] = $found ?? new ChatUserSettings($user);
    }

    /**
     * Forgets the settings memoized by `forUser()` (end of request / message).
     */
    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * Settings of several users in one query, defaults for the rest.
     *
     * @param list<ChatUserInterface> $users The users
     *
     * @return array<int|string, ChatUserSettings> user key (ChatUserReferences::key()) => settings
     */
    public function forUsers(array $users): array
    {
        $byId = [];
        foreach ($users as $user) {
            $byId[(string) ChatUserReferences::key($user)] = new ChatUserSettings($user);
        }
        if ($byId === []) {
            return [];
        }
        /** @var list<ChatUserSettings> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where($this->refs->identity('s', 'user') . ' IN (:ids)')
            ->setParameter('ids', $this->refs->ids(array_map(strval(...), array_keys($byId))))
            ->getQuery()
            ->getResult();
        foreach ($rows as $settings) {
            $key = (string) ChatUserReferences::key($settings->getUser());
            // REQ-SEC-006 item 2: only rows of the asked users, byte for byte.
            if (isset($byId[$key]) && ChatUserReferences::same($byId[$key]->getUser(), $settings->getUser())) {
                $byId[$key] = $settings;
            }
        }

        return $byId;
    }

    /**
     * Stores the settings (the first change of a user persists their defaults row).
     *
     * @param ChatUserSettings $settings The changed settings
     */
    public function save(ChatUserSettings $settings): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($settings);
        $manager->flush();
    }
}
