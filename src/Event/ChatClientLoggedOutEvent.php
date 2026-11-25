<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * 2.4.1 (client API v1): a client signed out through `POST <prefix>/chat/v1/logout` with a token the host's resolver
 * accepted. For the host's audit log: ids only — never the token, the raw Authorization header, a full IP or chat data.
 * Dispatched after ChatClientTokenRevokerInterface::revoke() returned (after the host's commit). A logout with an
 * unknown, expired or revoked token answers the same 204 and dispatches nothing (there is no principal to name).
 */
final class ChatClientLoggedOutEvent extends Event
{
    /**
     * @param string $tokenId The token's id from the principal (never the secret)
     * @param string $userPublicId The public id of the token's person
     * @param bool $revoked True when the host's revoker revoked the token; false when no revoker is configured (the
     *                      token stays valid until it expires; only the client forgot it)
     * @param string|null $ipPrefix The client's network: an IPv4 /24 (`203.0.113.0/24`) or IPv6 /64; null when unknown
     */
    public function __construct(
        public readonly string $tokenId,
        public readonly string $userPublicId,
        public readonly bool $revoked,
        public readonly ?string $ipPrefix = null,
    ) {
    }
}
