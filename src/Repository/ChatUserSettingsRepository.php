<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
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
            // 1.28: unsaved defaults and a warning, never an exception — this runs from ChatDockExtension::config()
            // on every page of the host and inside the GDPR erasure. save() is where the write is refused.
            $this->refs->reportLookAlike($this->userColumn(), $user);
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
        $returned = [];
        $foreign  = false;
        foreach ($rows as $settings) {
            $key        = (string) ChatUserReferences::key($settings->getUser());
            $returned[] = $key;
            // REQ-SEC-006 item 2: only rows of the asked users, byte for byte.
            if (isset($byId[$key]) && ChatUserReferences::same($byId[$key]->getUser(), $settings->getUser())) {
                $byId[$key] = $settings;

                continue;
            }
            $foreign = true;
        }
        $this->reportAskedLookAlikes($byId, $returned, $foreign);

        return $byId;
    }

    /**
     * Stores the settings (the first change of a user persists their defaults row).
     *
     * @param ChatUserSettings $settings The changed settings
     *
     * @throws ChatUserLookAlikeException 1.28, `string` mode: when a look-alike's row was read for this user in this table in this
     *                                    request
     */
    public function save(ChatUserSettings $settings): void
    {
        $manager = $this->getEntityManager();
        if ($settings->getId() === null) {
            // 1.28: the first save of a user whose look-alike this request already found would clash with that row's
            // unique user key inside the flush and close the entity manager.
            $this->refs->assertNoLookAlike($this->userColumn(), $settings->getUser());
        }
        $manager->persist($settings);
        $manager->flush();
    }

    /**
     * 1.28 (audit PV-L1): an asked user left without a row of their own while the query returned a row whose key may
     * fold onto theirs (`bob`'s row for `Bob`, whether or not `bob` was asked too) — the column folds. The list path
     * says so like the single-row path and keeps the defaults; what it flags is that ASKED user (whose first save would
     * clash with the row), never the row's owner. When a row belongs to none of the asked users but no key folds onto
     * it by that test (a collation that folds further), every asked user left without a row is flagged, which only
     * refuses their first save of settings in this request.
     *
     * @param array<int|string, ChatUserSettings> $byId Asked user key => their settings (a row, or unsaved defaults)
     * @param list<string> $returned Keys of the rows the query returned
     * @param bool $foreign Whether one of those rows belongs to none of the asked users
     */
    private function reportAskedLookAlikes(array $byId, array $returned, bool $foreign): void
    {
        $unmatched = array_filter($byId, static fn (ChatUserSettings $settings): bool => $settings->getId() === null);
        $asked     = array_filter($unmatched, static function (ChatUserSettings $settings) use ($returned): bool {
            foreach ($returned as $key) {
                if (ChatUserReferences::mayFold($key, (string) ChatUserReferences::key($settings->getUser()))) {
                    return true;
                }
            }

            return false;
        });
        foreach ($asked === [] && $foreign ? $unmatched : $asked as $settings) {
            $this->refs->reportLookAlike($this->userColumn(), $settings->getUser());
        }
    }

    /**
     * `table.column` of the user column of this entity, for the look-alike warning (1.28).
     */
    private function userColumn(): string
    {
        $metadata = $this->getClassMetadata();

        return $metadata->getTableName() . '.' . ($this->refs->isIdMode() ? $metadata->getColumnName('userRef') : 'user_id');
    }
}
