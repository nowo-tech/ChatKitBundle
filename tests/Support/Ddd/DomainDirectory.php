<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Ddd;

use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Model\ChatContact;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * 1.24: a hexagonal host's adapter — its own participants (DomainChatUser, ids from 1000) behind ChatDirectoryInterface,
 * and CurrentChatUserResolverInterface mapping the security user (a TestUser) to its participant by public id.
 */
final class DomainDirectory implements ChatDirectoryInterface, CurrentChatUserResolverInterface
{
    /** @var array<int, DomainChatUser> */
    private array $users = [];

    /** How many findByIds() calls were made. */
    public int $lookups = 0;

    public function add(TestUser $account): DomainChatUser
    {
        $user = new DomainChatUser(1000 + (int) $account->getId(), 'dom-' . $account->getChatPublicId(), $account->getChatDisplayName());

        return $this->users[$user->getId()] = $user;
    }

    public function forget(DomainChatUser $user): void
    {
        unset($this->users[$user->getId()]);
    }

    public function resolve(?UserInterface $user): ?ChatUserInterface
    {
        return $user instanceof TestUser ? $this->findByPublicId('dom-' . $user->getChatPublicId()) : null;
    }

    public function findByPublicId(string $publicId): ?ChatUserInterface
    {
        foreach ($this->users as $user) {
            if ($user->getChatPublicId() === $publicId) {
                return $user;
            }
        }

        return null;
    }

    public function findByIds(array $ids): array
    {
        ++$this->lookups;

        return array_values(array_filter($this->users, static fn (DomainChatUser $user): bool => in_array($user->getId(), $ids, true)));
    }

    public function contactsFor(ChatUserInterface $actor, ?string $query, int $limit): array
    {
        return array_values(array_map(
            static fn (DomainChatUser $user): ChatContact => new ChatContact($user, 'team'),
            array_filter($this->users, static fn (DomainChatUser $user): bool => $user->getId() !== $actor->getId()),
        ));
    }

    public function supportAgents(): array
    {
        return [];
    }
}
