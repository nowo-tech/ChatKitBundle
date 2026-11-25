<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatClientRequest;

/**
 * 2.4.1 (client API v1, `POST <prefix>/chat/v1/logout`): revokes the one Bearer token a client signs out with. Optional
 * (`client_api.token_revoker`): ChatKit issues and stores no token, so only the host can revoke one (Beacon: TimeTrack's
 * `ClientFamilyTokenAuthenticator::revoke('chat', …)`). Without it, logout only tells the client to forget its token
 * and the token stays valid until it expires or is revoked in the host's UI (`me.features.serverLogout` is false).
 *
 * Called once per logout, after ChatClientPrincipalResolverInterface::resolve() accepted the same token in the same
 * request, with the token exactly as sent. The host:
 *
 * - revokes this token only: never the person's other tokens, devices or sessions, and never a token of another client
 *   family (a `time` token is not revoked by the chat API, even if resolve() was lenient);
 * - is idempotent: a token that is already revoked, expired or unknown is a no-op, never an error (two devices, a retry
 *   after a timeout, or two concurrent calls may revoke the same token);
 * - commits the revocation in its own write unit before it returns (REQ-RUNTIME-003), so the next request with the
 *   token is refused; ChatClientLoggedOutEvent is dispatched after it returns;
 * - lets a transient failure (database down, lock conflict) propagate: the client gets an error and retries; never
 *   swallow it, the token would stay valid while the client believes it is signed out;
 * - keeps nothing of the call (FrankenPHP worker mode, kernel reset false).
 *
 * A token the resolver refuses is never revoked by logout (the answer is the same 204). So the resolver should refuse
 * only for reasons of the token itself (unknown, expired, revoked, another client family), or the host revokes a
 * person's client tokens when it disables the account or removes the membership it refuses for; otherwise a token
 * refused for an account, IP or context reason survives a logout and works again once that reason is gone (audit
 * ZCB-I2).
 */
interface ChatClientTokenRevokerInterface
{
    /**
     * Revokes one Bearer token.
     *
     * @param string $bearer The token as sent after `Bearer ` (trimmed, never empty, at most 4096 bytes)
     * @param ChatClientRequest $request The request (no header but the context header, no cookie, no body)
     */
    public function revoke(string $bearer, ChatClientRequest $request): void;
}
