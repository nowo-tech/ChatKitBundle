<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Model\ChatContact;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;

/**
 * Directory over the test user table: everyone is a contact; names starting with "Agent" answer support.
 */
final readonly class TestDirectory implements ChatDirectoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPublicId(string $publicId): ?ChatUserInterface
    {
        return $this->entityManager->getRepository(TestUser::class)->findOneBy(['publicId' => $publicId]);
    }

    public function findByIds(array $ids): array
    {
        return [] === $ids ? [] : array_values($this->entityManager->getRepository(TestUser::class)->findBy(['id' => $ids], ['id' => 'ASC']));
    }

    public function contactsFor(ChatUserInterface $actor, ?string $query, int $limit): array
    {
        $contacts = [];
        foreach ($this->entityManager->getRepository(TestUser::class)->findBy([], ['name' => 'ASC']) as $user) {
            if ($user->getId() !== $actor->getId() && (null === $query || false !== stripos($user->getChatDisplayName(), $query))) {
                $contacts[] = new ChatContact($user, 'group', 'Team');
            }
        }

        return \array_slice($contacts, 0, $limit);
    }

    public function supportAgents(): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(TestUser::class)->findAll(),
            static fn (TestUser $user): bool => str_starts_with($user->getChatDisplayName(), 'Agent'),
        ));
    }
}
