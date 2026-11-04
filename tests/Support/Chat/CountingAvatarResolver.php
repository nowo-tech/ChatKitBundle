<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use LogicException;
use Nowo\ChatKitBundle\Contract\ChatAvatarUrlResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use RuntimeException;

use function array_map;

/**
 * Test double of the host's picture resolver (1.16): records every urlsFor() batch (the public ids it was given and
 * the size) and fails on urlFor(), which the bundle must never call. Answers from `$urls` (public id => URL); with no
 * entry it behaves like the null default. `$fail` makes urlsFor() throw.
 */
final class CountingAvatarResolver implements ChatAvatarUrlResolverInterface
{
    /** @var array<string, string|null> public id => URL */
    public array $urls = [];

    public bool $fail = false;

    /** @var list<array{ids: list<string>, size: int}> */
    public array $calls = [];

    public function urlFor(ChatUserInterface $user, int $size): ?string
    {
        throw new LogicException('ChatKit must batch through urlsFor().');
    }

    public function urlsFor(array $users, int $size): array
    {
        $ids           = array_map(static fn (ChatUserInterface $user): string => $user->getChatPublicId(), $users);
        $this->calls[] = ['ids' => $ids, 'size' => $size];
        if ($this->fail) {
            throw new RuntimeException('picture service down');
        }
        $urls = [];
        foreach ($ids as $id) {
            if (isset($this->urls[$id])) {
                $urls[$id] = $this->urls[$id];
            }
        }
        // Unknown keys are ignored by the bundle.
        $urls['not-asked'] = 'https://cdn.example.com/not-asked.webp';

        return $urls;
    }

    /**
     * Every public id the resolver was asked about, in order.
     *
     * @return list<string>
     */
    public function askedIds(): array
    {
        $ids = [];
        foreach ($this->calls as $call) {
            $ids = [...$ids, ...$call['ids']];
        }

        return $ids;
    }
}
