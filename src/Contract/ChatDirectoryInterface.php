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
    public function findByPublicId(string $publicId): ?ChatUserInterface;

    /**
     * @param list<int> $ids
     *
     * @return list<ChatUserInterface>
     */
    public function findByIds(array $ids): array;

    /**
     * People the actor can start a direct conversation with, for the picker (capped by `$limit`).
     *
     * @return list<ChatContact>
     */
    public function contactsFor(ChatUserInterface $actor, ?string $query, int $limit): array;

    /**
     * Everyone who answers the shared support inbox.
     *
     * @return list<ChatUserInterface>
     */
    public function supportAgents(): array;
}
