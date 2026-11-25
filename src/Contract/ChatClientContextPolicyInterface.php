<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatClientContext;
use Nowo\ChatKitBundle\Model\ChatClientConversationRef;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;

/**
 * 2.4 (client API v1, optional): the tenant of a client API request, named explicitly by the client in the context
 * header (`client_api.context_header`) and checked on every request. The bundle never stores it in a service and never
 * writes it anywhere: the person's web context (a "current organization" kept on the server) is never changed by the
 * client API. Without this port the context is the one bound into the token (ChatClientPrincipal::$contextKey), and a
 * request that names another one is refused.
 *
 * Every method is called per request: answer from current data (membership revoked a second ago → refused now).
 */
interface ChatClientContextPolicyInterface
{
    /**
     * The contexts the person may use now (the client's context switcher, `GET me`).
     *
     * @param ChatClientPrincipal $principal The caller
     *
     * @return list<ChatClientContext> The contexts, in display order
     */
    public function contextsFor(ChatClientPrincipal $principal): array;

    /**
     * Whether the person may use the context now (membership re-checked on every request). The key is compared byte
     * for byte by the bundle afterwards: answer for this exact key only.
     *
     * @param ChatClientPrincipal $principal The caller
     * @param string $contextKey The key the client sent (already matched against ChatClientPrincipal::KEY_PATTERN)
     *
     * @return bool False answers 403 `context_forbidden` (the same answer as a context that does not exist)
     */
    public function allows(ChatClientPrincipal $principal, string $contextKey): bool;

    /**
     * Whether a conversation the person may read belongs to the context: lists leave the others out and a direct
     * request for one answers 404. Return true for conversations that belong to every context (direct messages, the
     * support inbox) when that is the host's rule.
     *
     * @param ChatClientPrincipal $principal The caller
     * @param string $contextKey The context of the request
     * @param ChatClientConversationRef $conversation The conversation (the caller may read it)
     *
     * @return bool True when it is shown in this context
     */
    public function includes(ChatClientPrincipal $principal, string $contextKey, ChatClientConversationRef $conversation): bool;
}
