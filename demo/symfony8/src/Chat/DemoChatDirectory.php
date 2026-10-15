<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\User;
use App\Repository\UserRepository;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Model\ChatContact;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Everyone in the demo is a contact of everyone else ("Demo team" in the people picker).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DemoChatDirectory implements ChatDirectoryInterface
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function findByPublicId(string $publicId): ?ChatUserInterface
    {
        return $this->users->findOneBy(['publicId' => $publicId]);
    }

    public function findByIds(array $ids): array
    {
        return [] === $ids ? [] : array_values($this->users->findBy(['id' => $ids], ['id' => 'ASC']));
    }

    public function contactsFor(ChatUserInterface $actor, ?string $query, int $limit): array
    {
        $contacts = [];
        foreach ($this->users->findBy([], ['displayName' => 'ASC']) as $user) {
            if ($user->getId() === $actor->getId() || (null !== $query && false === mb_stripos($user->getChatDisplayName(), $query))) {
                continue;
            }
            $contacts[] = new ChatContact($user, 'group', 'Demo team');
        }

        return \array_slice($contacts, 0, $limit);
    }

    public function supportAgents(): array
    {
        return array_values(array_filter($this->users->findAll(), static fn (User $user): bool => $user->isSupportAgent()));
    }
}
