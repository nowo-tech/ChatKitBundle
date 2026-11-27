<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\ClientApi\ChatModuleForbiddenException;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Model\ChatClientRequest;

/**
 * 2.4 (client API v1): who a Bearer token belongs to. ChatKit issues and stores no token: the host plugs in its own
 * token system (device-flow client tokens, personal access tokens, an OAuth introspection …) and the client API is off
 * until it does (`client_api.principal_resolver`).
 *
 * Called once per request, before any chat data is read, with the token exactly as sent (at most 4096 bytes). Resolve
 * it on every call: never cache a principal across requests (FrankenPHP worker mode, kernel reset false), so a revoked
 * token, a disabled account or a lost membership is refused on the next request. Compare the token in constant time
 * (store a hash, look it up by hash). Return null for an unknown, expired or revoked token, and for a token of another
 * client family (a `time` token on the chat API): the API answers 401 `invalid_token`. A token without
 * {@see ChatClientPrincipal::SCOPE_READ} answers 403 `insufficient_scope`. Return null as well for a disabled, anonymised
 * or deleted account (or leave it to ChatAccessPolicyInterface::isReachable(): both answer 401, the client signs out).
 *
 * 2.9 (audit ZDF-M1): a permission lost after pairing — the token is valid and the account active, but the person may not
 * use the chat now (a plan without the chat, a capabilities provider that denies the `chat` module) — is not null:
 * throw {@see ChatModuleForbiddenException} (with the token id, and the person's chat public id when known). The API
 * answers `403 module_forbidden` (`module: chat`, no `WWW-Authenticate`), so the client hides the chat and keeps its token
 * (it is not signed out), and audits it with reason `module_forbidden`. Throw it only after the token validated, never
 * for an unknown, expired or revoked token (that would be an oracle). Logout signs such a token out (revoked, 204).
 *
 * 2.4.1 (logout, audit ZCB-I2): `POST logout` asks this resolver too, and only a token it accepts reaches
 * ChatClientTokenRevokerInterface. Refuse only for reasons of the token itself, or revoke a person's client tokens when
 * you disable the account; a token refused for an account, IP or context reason is not revoked by logout.
 *
 * The context: return the principal with `contextKey` set when the token was bound to one tenant at approval. A host
 * whose clients choose the tenant per request (a header) implements ChatClientContextPolicyInterface as well.
 */
interface ChatClientPrincipalResolverInterface
{
    /**
     * Resolves a Bearer token.
     *
     * @param string $bearer The token as sent after `Bearer ` (trimmed, never empty)
     * @param ChatClientRequest $request The request (no header but the context header, no cookie, no body)
     *
     * @throws ChatModuleForbiddenException 2.9: the token is valid, but the chat is closed to its person now (403 `module_forbidden`)
     *
     * @return ChatClientPrincipal|null The principal; null when the token is not a valid chat token now
     */
    public function resolve(string $bearer, ChatClientRequest $request): ?ChatClientPrincipal;
}
