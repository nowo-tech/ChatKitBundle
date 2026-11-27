<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * 2.4 (client API v1): the client API refused a request. For the host's audit log (as TimeTrack's client API
 * refusals): ids and codes only — never the token, the context header as sent, a full IP or chat data. Dispatched
 * before the refusal is answered; a listener must not throw.
 */
final class ChatClientApiDeniedEvent extends Event
{
    /**
     * @param string $reason Why: `invalid_token`, `account_inactive` (401: a disabled or anonymised account), `module_forbidden` (2.9, 403: the host's resolver threw ChatModuleForbiddenException, a permission lost after pairing), `insufficient_scope`, `rate_limited`, `invalid_context`, `context_required`, `context_forbidden`, `context_unverifiable` (a token bound to a context with no context policy) or `not_found` (an unknown, foreign or out-of-context uuid)
     * @param string|null $tokenId The token's id from the principal (2.9: or from ChatModuleForbiddenException); null before the token was resolved
     * @param string|null $userPublicId The public id of the token's person; null before the token was resolved (or when a ChatModuleForbiddenException does not name the person)
     * @param string|null $ipPrefix The client's network: an IPv4 /24 (`203.0.113.0/24`) or IPv6 /64; null when unknown
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?string $tokenId = null,
        public readonly ?string $userPublicId = null,
        public readonly ?string $ipPrefix = null,
    ) {
    }
}
