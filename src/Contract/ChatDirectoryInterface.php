<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatContact;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Users as the chat sees them. Implemented by the application (its user repository, groups, projects …).
 */
interface ChatDirectoryInterface
{
    /**
     * Looks a user up by their public chat identifier.
     *
     * @param string $publicId The value of getChatPublicId()
     *
     * @return ChatUserInterface|null The user, or null when unknown
     */
    public function findByPublicId(string $publicId): ?ChatUserInterface;

    /**
     * Loads users by their Doctrine ids; unknown ids are left out.
     *
     * @param list<int> $ids Doctrine identifiers
     *
     * @return list<ChatUserInterface> The users found
     */
    public function findByIds(array $ids): array;

    /**
     * People the actor can start a direct conversation with, for the picker (capped by `$limit`).
     *
     * @param ChatUserInterface $actor The user opening the picker
     * @param string|null $query Search text typed in the picker; null for the default list
     * @param int $limit Maximum number of contacts
     *
     * @return list<ChatContact> The contacts
     */
    public function contactsFor(ChatUserInterface $actor, ?string $query, int $limit): array;

    /**
     * Everyone who answers the shared support inbox.
     *
     * @return list<ChatUserInterface> The support agents
     */
    public function supportAgents(): array;
}
