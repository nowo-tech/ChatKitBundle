<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Ddd;

use LogicException;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatUserReferenceDirectoryInterface;
use Nowo\ChatKitBundle\Model\ChatContact;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function in_array;
use function mb_strtolower;

/**
 * 1.27: a DDD host's directory of RefChatUser (string references) and its framework-free current-user resolver (the
 * security user's identifier is the account key). `$folding` makes it answer like a case-folding database would, to
 * prove the bundle re-checks every answer byte for byte.
 */
final class RefDirectory implements ChatDirectoryInterface, ChatUserReferenceDirectoryInterface, ChatParticipantResolverInterface
{
    /** @var array<string, RefChatUser> reference => user */
    private array $users = [];

    /** @var array<string, string> security identifier => reference */
    private array $accounts = [];

    public bool $folding = false;

    /** @var list<list<string>> */
    public array $lookups = [];

    public function add(RefChatUser $user, ?string $identifier = null): RefChatUser
    {
        $this->users[$user->getChatUserReference()] = $user;
        if ($identifier !== null) {
            $this->accounts[$identifier] = $user->getChatUserReference();
        }

        return $user;
    }

    public function forget(RefChatUser $user): void
    {
        unset($this->users[$user->getChatUserReference()]);
    }

    public function resolve(?ChatSecurityUser $user): ?ChatUserInterface
    {
        $reference = $user === null ? null : ($this->accounts[$user->identifier] ?? null);

        return $reference === null ? null : ($this->users[$reference] ?? null);
    }

    public function findByReferences(array $references): array
    {
        $this->lookups[] = $references;
        $found           = [];
        foreach ($this->users as $reference => $user) {
            $hit = $this->folding
                ? in_array(mb_strtolower((string) $reference), array_map(mb_strtolower(...), $references), true)
                : in_array((string) $reference, $references, true);
            if ($hit) {
                $found[] = $user;
            }
        }

        return $found;
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
        throw new LogicException('String references are loaded through findByReferences().');
    }

    public function contactsFor(ChatUserInterface $actor, ?string $query, int $limit): array
    {
        $contacts = [];
        foreach ($this->users as $user) {
            if ($user->getChatPublicId() !== $actor->getChatPublicId()) {
                $contacts[] = new ChatContact($user, 'team');
            }
        }

        return $contacts;
    }

    public function supportAgents(): array
    {
        return [];
    }
}
