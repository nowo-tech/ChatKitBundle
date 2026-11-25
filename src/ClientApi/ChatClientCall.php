<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\ClientApi;

use Nowo\ChatKitBundle\Model\ChatClientPrincipal;

/**
 * 2.4 (client API v1): one authenticated client API request: the principal and the context checked for it. Kept in the
 * request attributes ({@see self::ATTRIBUTE}) only, never in a service (FrankenPHP worker mode, kernel reset false).
 *
 * @internal
 */
final readonly class ChatClientCall
{
    /** Request attribute holding the call (host listeners may read it, e.g. for an audit of allowed requests). */
    public const string ATTRIBUTE = '_nowo_chat_client';

    /**
     * @param ChatClientPrincipal $principal The caller
     * @param string|null $contextKey The context of the request; null when the host has no tenants
     */
    public function __construct(
        public ChatClientPrincipal $principal,
        public ?string $contextKey,
    ) {
    }
}
