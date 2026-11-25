<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\ClientApi;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatClientContextPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatClientPrincipalResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatClientTokenRevokerInterface;
use Nowo\ChatKitBundle\Event\ChatClientApiDeniedEvent;
use Nowo\ChatKitBundle\Event\ChatClientLoggedOutEvent;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Model\ChatClientRequest;
use Nowo\ChatKitBundle\Port\ChatRequestContexts;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function filter_var;
use function hash;
use function max;
use function preg_match;
use function sprintf;
use function str_contains;
use function strlen;
use function strncasecmp;
use function substr;
use function trim;

use const FILTER_FLAG_IPV4;
use const FILTER_VALIDATE_IP;

/**
 * 2.4 (client API v1): the stateless checks of every client API request, in this order (as TimeTrack's client API):
 *
 * 1. the API is on (a principal resolver is configured; otherwise 404, as if the routes did not exist);
 * 2. the per-IP budget (`client_api.limits.per_ip_per_minute`; IPv6 by /64) — 429 `rate_limited` + Retry-After;
 * 3. a Bearer token (at most 4096 bytes) the host's resolver accepts — 401 `invalid_token` + `WWW-Authenticate: Bearer`;
 * 4. the token's person can use the chat now (ChatAccessPolicyInterface::isReachable()) — 401 `invalid_token`;
 * 5. the per-token budget (`per_token_per_minute`, keyed by the principal's token id) — 429;
 * 6. the person's own chat budget, shared with the web UI (reads, or actions for read marks) — 429;
 * 7. the scope (`chat:read`) — 403 `insufficient_scope`;
 * 8. the context: the context header, or the token's own context, checked by ChatClientContextPolicyInterface on every
 *    request (`allows()`; a token bound to a context with no policy is refused: only the policy filters conversations) —
 *    400 `invalid_context` / `context_required`, 403 `context_forbidden`.
 *
 * Never reads or writes the session, never trusts a cookie, never changes the person's web context. Every refusal
 * after step 1 dispatches ChatClientApiDeniedEvent (ids and codes only). Stateless: nothing of a request is kept.
 *
 * 2.4.1: logout ({@see self::logout()}) runs steps 1–3 and 5 only, and answers the same way for an unknown, expired or
 * revoked token as for a revoked one (not an oracle).
 */
final readonly class ChatClientApiGuard
{
    /** Longest Bearer token passed to the resolver (bytes). */
    public const int MAX_TOKEN_LENGTH = 4096;

    /** Longest context header read (bytes); a longer value is `invalid_context`. */
    public const int MAX_CONTEXT_LENGTH = 128;

    /** The budget of a request: reads, or (read marks) the person's action budget. */
    public const string BUDGET_READ   = 'read';
    public const string BUDGET_ACTION = 'action';

    /**
     * @param ChatAccess $access Reachability of the token's person
     * @param RateLimiterFactoryInterface $ipLimiter Per-IP budget of the client API
     * @param RateLimiterFactoryInterface $tokenLimiter Per-token budget of the client API
     * @param RateLimiterFactoryInterface $readLimiter The person's read budget (shared with the web UI)
     * @param RateLimiterFactoryInterface $actionLimiter The person's action budget (shared with the web UI)
     * @param string $contextHeader Name of the context header (`client_api.context_header`)
     * @param ChatClientPrincipalResolverInterface|null $resolver The host's token resolver; null: the API is off
     * @param ChatClientContextPolicyInterface|null $contexts The host's context policy; null: the token's own context only
     * @param EventDispatcherInterface|null $events Receives ChatClientApiDeniedEvent
     * @param ClockInterface $clock Clock of the Retry-After seconds
     * @param ChatClientTokenRevokerInterface|null $revoker 2.4.1: the host's token revocation (logout); null: logout only
     *                                                      tells the client to forget its token
     */
    public function __construct(
        private ChatAccess $access,
        private RateLimiterFactoryInterface $ipLimiter,
        private RateLimiterFactoryInterface $tokenLimiter,
        private RateLimiterFactoryInterface $readLimiter,
        private RateLimiterFactoryInterface $actionLimiter,
        private string $contextHeader = 'X-Chat-Context',
        private ?ChatClientPrincipalResolverInterface $resolver = null,
        private ?ChatClientContextPolicyInterface $contexts = null,
        private ?EventDispatcherInterface $events = null,
        private ClockInterface $clock = new Clock(),
        private ?ChatClientTokenRevokerInterface $revoker = null,
    ) {
    }

    /**
     * 2.4.1: whether logout revokes the token on the server (a revoker is configured).
     *
     * @return bool True when a revoker is configured
     */
    public function revokes(): bool
    {
        return $this->revoker instanceof ChatClientTokenRevokerInterface;
    }

    /**
     * Whether the API is on (a resolver is configured).
     *
     * @return bool True when on
     */
    public function enabled(): bool
    {
        return $this->resolver instanceof ChatClientPrincipalResolverInterface;
    }

    /**
     * The context policy, when the host has one.
     *
     * @return ChatClientContextPolicyInterface|null The policy
     */
    public function contexts(): ?ChatClientContextPolicyInterface
    {
        return $this->contexts;
    }

    /**
     * Runs every check and stores the call in the request attributes.
     *
     * @param Request $request The request
     * @param string $scope The scope the route needs
     * @param string $budget {@see self::BUDGET_READ} or {@see self::BUDGET_ACTION}
     *
     * @throws ChatApiException The refusal (status, code and headers)
     *
     * @return ChatClientCall The call
     */
    public function authenticate(Request $request, string $scope = ChatClientPrincipal::SCOPE_READ, string $budget = self::BUDGET_READ): ChatClientCall
    {
        $resolver = $this->resolver;
        if (!$resolver instanceof ChatClientPrincipalResolverInterface) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }
        $ip       = $request->getClientIp();
        $ipPrefix = self::ipPrefix($ip);
        $this->consume($this->ipLimiter, 'nowo_chat_client_ip_' . hash('sha256', $this->ipKey($ip)), null, $ipPrefix);

        $bearer = self::bearer($request);
        $header = $request->headers->get($this->contextHeader);
        if ($bearer === null) {
            $this->deny('invalid_token', Response::HTTP_UNAUTHORIZED, null, $ipPrefix, ['WWW-Authenticate' => 'Bearer']);
        }
        $principal = $resolver->resolve($bearer, $this->clientRequest($request, $ip, $header));
        if (!$principal instanceof ChatClientPrincipal) {
            $this->deny('invalid_token', Response::HTTP_UNAUTHORIZED, null, $ipPrefix, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        if (!$this->access->isReachable($principal->user)) {
            $this->deny('invalid_token', Response::HTTP_UNAUTHORIZED, $principal, $ipPrefix, ['WWW-Authenticate' => 'Bearer error="invalid_token"'], 'account_inactive');
        }
        $this->consume($this->tokenLimiter, 'nowo_chat_client_token_' . hash('sha256', $principal->tokenId), $principal, $ipPrefix);
        $own = $budget === self::BUDGET_ACTION
            ? [$this->actionLimiter, 'nowo_chat_action_']
            : [$this->readLimiter, 'nowo_chat_read_'];
        $this->consume($own[0], $own[1] . $principal->user->getChatPublicId(), $principal, $ipPrefix);
        if (!$principal->hasScope($scope)) {
            $this->deny('insufficient_scope', Response::HTTP_FORBIDDEN, $principal, $ipPrefix, ['WWW-Authenticate' => sprintf('Bearer error="insufficient_scope", scope="%s"', $scope)]);
        }

        $call = new ChatClientCall($principal, $this->context($principal, $header, $ipPrefix));
        $request->attributes->set(ChatClientCall::ATTRIBUTE, $call);

        return $call;
    }

    /**
     * 2.4.1: `POST logout`. Revokes the presented token only, through the host's revoker, and dispatches
     * ChatClientLoggedOutEvent. Runs the per-IP budget, the Bearer check and the per-token budget (429 + Retry-After);
     * an absent or malformed Authorization header answers 401 as on every route. A token the resolver does not accept
     * now (unknown, expired, revoked, another client family) returns silently: the caller answers the same 204 (no
     * oracle), nothing is revoked and no event is dispatched. Reachability, scope and context are not checked: a
     * person whose account is inactive, whose token lacks `chat:read` or whose membership is gone may always give
     * up their own token. A failure of the revoker propagates (never reported as a success).
     *
     * @param Request $request The request
     *
     * @throws ChatApiException The refusal (404 API off, 401 no Bearer token, 429 budget spent)
     */
    public function logout(Request $request): void
    {
        $resolver = $this->resolver;
        if (!$resolver instanceof ChatClientPrincipalResolverInterface) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }
        $ip       = $request->getClientIp();
        $ipPrefix = self::ipPrefix($ip);
        $this->consume($this->ipLimiter, 'nowo_chat_client_ip_' . hash('sha256', $this->ipKey($ip)), null, $ipPrefix);
        $bearer = self::bearer($request);
        if ($bearer === null) {
            $this->deny('invalid_token', Response::HTTP_UNAUTHORIZED, null, $ipPrefix, ['WWW-Authenticate' => 'Bearer']);
        }
        $clientRequest = $this->clientRequest($request, $ip, $request->headers->get($this->contextHeader));
        $principal     = $resolver->resolve($bearer, $clientRequest);
        if (!$principal instanceof ChatClientPrincipal) {
            return;
        }
        $this->consume($this->tokenLimiter, 'nowo_chat_client_token_' . hash('sha256', $principal->tokenId), $principal, $ipPrefix);
        $this->revoker?->revoke($bearer, $clientRequest);
        $this->events?->dispatch(new ChatClientLoggedOutEvent($principal->tokenId, $principal->user->getChatPublicId(), $this->revokes(), $ipPrefix));
    }

    /**
     * Audit ZBW-I2: a 404 of an authenticated call (unknown, foreign or out-of-context uuid) is audited like the other
     * refusals (reason `not_found`, ids only), so probing leaves a trace.
     *
     * @param Request $request The request
     * @param ChatClientCall $call The call
     *
     * @throws ChatApiException Always: 404 `not_found`
     */
    public function notFound(Request $request, ChatClientCall $call): never
    {
        $this->deny('not_found', Response::HTTP_NOT_FOUND, $call->principal, self::ipPrefix($request->getClientIp()));
    }

    /**
     * The plain Bearer token of the request: null when absent, blank or longer than {@see self::MAX_TOKEN_LENGTH}.
     *
     * @param Request $request The request
     *
     * @return string|null The token
     */
    public static function bearer(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (strncasecmp($header, 'Bearer ', 7) !== 0) {
            return null;
        }
        $token = trim(substr($header, 7));

        return $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH ? null : $token;
    }

    /**
     * The network of an IP for the refusal events: IPv4 /24, IPv6 /64; null when unknown.
     *
     * @param string|null $ip The client IP
     *
     * @return string|null The prefix, e.g. `203.0.113.0/24`
     */
    public static function ipPrefix(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return str_contains($ip, ':') ? IpUtils::anonymize($ip, 0, 8) . '/64' : IpUtils::anonymize($ip, 1) . '/24';
    }

    /**
     * The request as the host's ports see it (no Authorization header, cookie or body).
     */
    private function clientRequest(Request $request, ?string $ip, ?string $header): ChatClientRequest
    {
        return new ChatClientRequest(
            ChatRequestContexts::fromRequest($request),
            $ip,
            $header === null ? null : substr($header, 0, self::MAX_CONTEXT_LENGTH),
        );
    }

    /**
     * The rate-limit key of an IP: the address for IPv4, its /64 for IPv6 (one host holds a whole /64).
     */
    private function ipKey(?string $ip): string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : IpUtils::anonymize($ip, 0, 8);
    }

    /**
     * The context of the request: the header checked by the policy, else the token's own context.
     */
    private function context(ChatClientPrincipal $principal, ?string $header, ?string $ipPrefix): ?string
    {
        if ($header !== null && (strlen($header) > self::MAX_CONTEXT_LENGTH || preg_match(ChatClientPrincipal::KEY_PATTERN, $header) !== 1)) {
            $this->deny('invalid_context', Response::HTTP_BAD_REQUEST, $principal, $ipPrefix);
        }
        $policy = $this->contexts;
        $bound  = $principal->contextKey;
        if ($bound !== null) {
            // Audit ZBW-L1: only the policy filters conversations by context; a bound token without one is refused
            // (fail closed) instead of being served every context. ZBW-L3: membership is re-checked for bound tokens too.
            if (!$policy instanceof ChatClientContextPolicyInterface) {
                $this->deny('context_forbidden', Response::HTTP_FORBIDDEN, $principal, $ipPrefix, [], 'context_unverifiable');
            }
            // A token bound to a context never opens another one.
            if (($header !== null && $header !== $bound) || !$policy->allows($principal, $bound)) {
                $this->deny('context_forbidden', Response::HTTP_FORBIDDEN, $principal, $ipPrefix);
            }

            return $bound;
        }
        if ($header === null) {
            if ($policy instanceof ChatClientContextPolicyInterface) {
                $this->deny('context_required', Response::HTTP_BAD_REQUEST, $principal, $ipPrefix);
            }

            return null;
        }
        // Without a policy, nothing can check the context (fail closed).
        if (!$policy instanceof ChatClientContextPolicyInterface || !$policy->allows($principal, $header)) {
            $this->deny('context_forbidden', Response::HTTP_FORBIDDEN, $principal, $ipPrefix);
        }

        return $header;
    }

    /**
     * Consumes one token of a budget; 429 + Retry-After when it is spent.
     */
    private function consume(RateLimiterFactoryInterface $factory, string $key, ?ChatClientPrincipal $principal, ?string $ipPrefix): void
    {
        $limit = $factory->create($key)->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - $this->now()->getTimestamp());
            $this->deny('rate_limited', Response::HTTP_TOO_MANY_REQUESTS, $principal, $ipPrefix, ['Retry-After' => (string) $retry]);
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * Dispatches the refusal event and throws the refusal.
     *
     * @param array<string, string> $headers
     *
     * @throws ChatApiException Always
     */
    private function deny(string $error, int $status, ?ChatClientPrincipal $principal, ?string $ipPrefix, array $headers = [], ?string $reason = null): never
    {
        $this->events?->dispatch(new ChatClientApiDeniedEvent($reason ?? $error, $principal?->tokenId, $principal?->user->getChatPublicId(), $ipPrefix));

        throw new ChatApiException($error, $status, null, '', [], $headers);
    }
}
