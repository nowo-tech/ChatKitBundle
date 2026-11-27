<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\ClientApi;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatClientPrincipalResolverInterface;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use RuntimeException;

use function preg_match;

/**
 * 2.9 (client API v1, audit ZDF-M1): "this token is valid, but the chat is closed to its person now" — a permission
 * removed after pairing (a plan without the chat, a capabilities provider that denies the `chat` module). The host's
 * {@see ChatClientPrincipalResolverInterface::resolve()} throws it instead of returning
 * null, and only after it validated the token (never for an unknown, expired or revoked token: that stays null, 401,
 * so the 403 is no oracle).
 *
 * ChatClientApiGuard turns it into `403 {ok: false, error: "module_forbidden", message: "Chat is not available for this
 * account.", module: "chat"}` (no `WWW-Authenticate`, `Cache-Control: no-store, private`), so a client refreshes what
 * the person may use and hides the chat without dropping its token, and audits it (ChatClientApiDeniedEvent reason
 * `module_forbidden`). A disabled or anonymised account is not this: the resolver returns null, or
 * ChatAccessPolicyInterface::isReachable() is false, and the API answers 401 `invalid_token` (reason `account_inactive`).
 *
 * Logout ignores it: a person may always give up their own token, so the guard answers logout as for a refused token.
 *
 * Carries ids only (never the token): with `tokenId`, the guard spends the token's per-token budget first (429 once spent)
 * and names the token in the audit event.
 */
final class ChatModuleForbiddenException extends RuntimeException
{
    /**
     * @param string|null $tokenId The stable, non-secret id of the token ({@see ChatClientPrincipal::KEY_PATTERN}); null when unknown
     * @param string|null $userPublicId The chat public id of the token's person, for the audit event; null when unknown
     *
     * @throws InvalidArgumentException When the token id does not match {@see ChatClientPrincipal::KEY_PATTERN}
     */
    public function __construct(
        public readonly ?string $tokenId = null,
        public readonly ?string $userPublicId = null,
    ) {
        if ($tokenId !== null && preg_match(ChatClientPrincipal::KEY_PATTERN, $tokenId) !== 1) {
            throw new InvalidArgumentException('A chat client token id must match ' . ChatClientPrincipal::KEY_PATTERN . '.');
        }
        parent::__construct(ChatClientApiGuard::MODULE_FORBIDDEN);
    }
}
