<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * @extends ServiceEntityRepository<ChatUserSettings>
 */
class ChatUserSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatUserSettings::class);
    }

    /** Stored settings, or unsaved defaults (persisted only when the user changes something). */
    public function forUser(ChatUserInterface $user): ChatUserSettings
    {
        return $this->findOneBy(['user' => $user]) ?? new ChatUserSettings($user);
    }

    /**
     * Settings of several users in one query, defaults for the rest.
     *
     * @param list<ChatUserInterface> $users
     *
     * @return array<int, ChatUserSettings> user id => settings
     */
    public function forUsers(array $users): array
    {
        $byId = [];
        foreach ($users as $user) {
            $byId[(int) $user->getId()] = new ChatUserSettings($user);
        }
        if ($byId === []) {
            return [];
        }
        /** @var list<ChatUserSettings> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where('IDENTITY(s.user) IN (:ids)')
            ->setParameter('ids', array_keys($byId))
            ->getQuery()
            ->getResult();
        foreach ($rows as $settings) {
            $byId[(int) $settings->getUser()->getId()] = $settings;
        }

        return $byId;
    }
}
