<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\ClientApi\ChatModuleForbiddenException;
use Nowo\ChatKitBundle\Contract\ChatClientPrincipalResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Model\ChatClientRequest;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 2.4: the host's token lookup of the client API tests: tokens granted and revoked by the tests (in memory, like a
 * token table), resolved on every call through the directory. Records the requests it was asked about.
 */
final class TestClientPrincipalResolver implements ChatClientPrincipalResolverInterface
{
    /** @var array<string, array{user: string, scopes: list<string>, id: string, context: string|null}> */
    public array $tokens = [];

    /** @var list<ChatClientRequest> */
    public array $requests = [];

    /**
     * 2.9: tokens whose person lost the chat after pairing (the resolver throws ChatModuleForbiddenException): true with
     * the token and person ids, false without (as Beacon's TimeTrack mapping).
     *
     * @var array<string, bool>
     */
    public array $moduleForbidden = [];

    public function __construct(private readonly ChatDirectoryInterface $directory)
    {
    }

    /**
     * @param list<string> $scopes
     */
    public function grant(string $token, ChatUserInterface $user, array $scopes = [ChatClientPrincipal::SCOPE_READ], ?string $context = null, ?string $id = null): void
    {
        $this->tokens[$token] = ['user' => $user->getChatPublicId(), 'scopes' => $scopes, 'id' => $id ?? 'tok-' . $user->getChatPublicId(), 'context' => $context];
    }

    public function revoke(string $token): void
    {
        unset($this->tokens[$token]);
    }

    public function resolve(string $bearer, ChatClientRequest $request): ?ChatClientPrincipal
    {
        $this->requests[] = $request;
        $row              = $this->tokens[$bearer] ?? null;
        $user             = $row === null ? null : $this->directory->findByPublicId($row['user']);
        if ($row !== null && isset($this->moduleForbidden[$bearer])) {
            throw $this->moduleForbidden[$bearer] ? new ChatModuleForbiddenException($row['id'], $row['user']) : new ChatModuleForbiddenException();
        }

        return $row === null || $user === null ? null : new ChatClientPrincipal($user, $row['scopes'], $row['id'], $row['context']);
    }
}
