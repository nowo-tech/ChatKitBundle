<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatReferenceProviderInterface;
use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Model\ChatReferenceScope;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function array_slice;
use function in_array;

/**
 * `/task`: four tasks. Strangers and names starting with "NoTasks" may open none (a member without the permission);
 * task 2 is Ana's only; task 3 belongs to another project (never offered nor kept in the Acme channel); task 4 links
 * to a `javascript:` URL with an unknown tone. Search also returns an item of another type, which the bundle drops.
 */
final class TestReferenceProvider implements ChatReferenceProviderInterface
{
    /** @var array<int, array{string, string, string|null, string, string}> id => [title, project, only for, url, tone] */
    public const array ITEMS = [
        1 => ['Deploy the API', 'acme', null, '/tasks/1', 'success'],
        2 => ['Secret plan', 'acme', 'Ana', '/tasks/2', 'danger'],
        3 => ['Other project task', 'other', null, '/tasks/3', 'info'],
        4 => ['Odd link', 'acme', null, 'javascript:alert(1)', 'pink'],
    ];

    public function key(): string
    {
        return 'task';
    }

    public function label(): string
    {
        return 'Task';
    }

    public function icon(): string
    {
        return 'task';
    }

    public function isAvailable(ChatReferenceScope $scope): bool
    {
        return $this->matching($scope, null, '') !== [];
    }

    public function search(ChatReferenceScope $scope, string $query, int $limit): array
    {
        $found   = array_values($this->matching($scope, null, $query));
        $found[] = new ChatReference('issue', '9', 'Not mine', '/issues/9');

        return array_slice($found, 0, $limit);
    }

    public function resolve(ChatReferenceScope $scope, array $ids): array
    {
        return $this->matching($scope, $ids, '');
    }

    /**
     * @param list<string>|null $ids
     *
     * @return array<array-key, ChatReference>
     */
    private function matching(ChatReferenceScope $scope, ?array $ids, string $query): array
    {
        $found = [];
        foreach (self::ITEMS as $id => [$title, $project, $onlyFor, $url, $tone]) {
            $id = (string) $id;
            if (($ids !== null && !in_array($id, $ids, true)) || ($query !== '' && stripos($title, $query) === false)) {
                continue;
            }
            if ($scope->channelKey() !== null && ($scope->channelKey() !== TestChannelProvider::KEY || $project !== 'acme')) {
                continue;
            }
            foreach ($scope->audience as $user) {
                if (!$this->canOpen($user, $onlyFor)) {
                    continue 2;
                }
            }
            $found[$id] = new ChatReference('task', $id, $title, $url, 'Acme · Doing', 'High', $tone);
        }

        return $found;
    }

    private function canOpen(ChatUserInterface $user, ?string $onlyFor): bool
    {
        $name = $user->getChatDisplayName();

        return !str_starts_with($name, 'Stranger') && !str_starts_with($name, 'NoTasks') && ($onlyFor === null || $name === $onlyFor);
    }
}
