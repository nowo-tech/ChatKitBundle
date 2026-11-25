<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatClientTokenRevokerInterface;
use Nowo\ChatKitBundle\Model\ChatClientRequest;
use RuntimeException;

/**
 * 2.4.1: the host's token revocation of the logout tests: removes exactly the presented token from the test resolver's
 * token table (idempotent). Records the calls; `failNext` simulates a transient failure of the host's write.
 */
final class TestClientTokenRevoker implements ChatClientTokenRevokerInterface
{
    /** @var list<array{bearer: string, request: ChatClientRequest}> */
    public array $calls = [];

    public bool $failNext = false;

    public function __construct(private readonly TestClientPrincipalResolver $tokens)
    {
    }

    public function revoke(string $bearer, ChatClientRequest $request): void
    {
        $this->calls[] = ['bearer' => $bearer, 'request' => $request];
        if ($this->failNext) {
            $this->failNext = false;

            throw new RuntimeException('The token store is unavailable.');
        }
        $this->tokens->revoke($bearer);
    }
}
