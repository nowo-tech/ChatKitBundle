<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatReferenceProviderInterface;
use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Model\ChatReferenceScope;

/**
 * A provider the bundle must refuse: an invalid key ("Bad Key") or a second provider of a key already taken ("task").
 * If it were used, it would offer and resolve everything.
 */
final readonly class TestBrokenReferenceProvider implements ChatReferenceProviderInterface
{
    public function __construct(
        private string $key,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Broken';
    }

    public function icon(): string
    {
        return 'link';
    }

    public function isAvailable(ChatReferenceScope $scope): bool
    {
        return true;
    }

    public function search(ChatReferenceScope $scope, string $query, int $limit): array
    {
        return [new ChatReference($this->key, '1', 'Leaked', '/leak')];
    }

    public function resolve(ChatReferenceScope $scope, array $ids): array
    {
        return ['1' => new ChatReference($this->key, '1', 'Leaked', '/leak'), '2' => new ChatReference($this->key, '2', 'Leaked', '/leak')];
    }
}
