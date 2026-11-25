<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 2.4 (client API v1): one client API request as the host's ports see it (REQ-ARCH-001 rule 3: no HttpFoundation
 * type): the request parts of ChatRequestContext, the client IP as the host's trusted-proxy setup reports it, and the
 * raw value of the context header (`client_api.context_header`), at most 128 characters, untrusted. Never the
 * Authorization header, a cookie or the body. Built per call and never stored.
 */
final readonly class ChatClientRequest
{
    /**
     * @param ChatRequestContext $request Method, URL parts, route and query of the request
     * @param string|null $clientIp The client IP (`Request::getClientIp()`); null when unknown
     * @param string|null $requestedContext The context header as sent (untrusted); null when absent
     */
    public function __construct(
        public ChatRequestContext $request,
        public ?string $clientIp = null,
        public ?string $requestedContext = null,
    ) {
    }
}
