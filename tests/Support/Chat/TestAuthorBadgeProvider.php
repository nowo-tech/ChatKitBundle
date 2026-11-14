<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatAuthorBadgeProviderInterface;
use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatAuthorRef;
use RuntimeException;

use function array_key_exists;
use function array_map;

/**
 * 1.29: records the author batches and answers from `badges` (by exact reference); `extra` is added to every answer
 * as is (keys that were not asked, malformed values).
 */
final class TestAuthorBadgeProvider implements ChatAuthorBadgeProviderInterface
{
    /** @var array<array-key, list<mixed>> reference => badges (anything else than a badge too) */
    public array $badges = [];

    /** @var array<array-key, mixed> */
    public array $extra = [];

    public bool $fail = false;

    /** @var list<list<string>> the references of every call */
    public array $calls = [];

    /** @var list<list<string>> the public ids of every call */
    public array $publicIds = [];

    /** Forgets the recorded calls. */
    public function clear(): void
    {
        $this->calls     = [];
        $this->publicIds = [];
    }

    public function badgesForMany(array $authors): array
    {
        $this->calls[]     = array_map(static fn (ChatAuthorRef $author): string => $author->reference, $authors);
        $this->publicIds[] = array_map(static fn (ChatAuthorRef $author): string => $author->publicId, $authors);
        if ($this->fail) {
            throw new RuntimeException('directory down');
        }
        $answer = [];
        foreach ($authors as $author) {
            if (array_key_exists($author->reference, $this->badges)) {
                $answer[$author->reference] = $this->badges[$author->reference];
            }
        }

        /** @var array<string, list<ChatAuthorBadge>> $answer A wrong answer on purpose when a test asks for one */
        $answer += $this->extra;

        return $answer;
    }
}
