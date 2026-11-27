<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\ClientApi;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatClientContextPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatClientPrincipalResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatClientTokenRevokerInterface;
use Nowo\ChatKitBundle\Event\ChatClientApiDeniedEvent;
use Nowo\ChatKitBundle\Event\ChatClientApiWriteEvent;
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
use function implode;
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
 *    2.9 (audit ZDF-M1): a resolver that validated the token but knows the chat is closed to its person (a permission
 *    removed after pairing) throws ChatModuleForbiddenException instead: the token's per-token budget is spent (when the
 *    exception names the token) and the API answers 403 `module_forbidden` (`message`, `module: chat`, no
 *    `WWW-Authenticate`; the client keeps its token), audit reason `module_forbidden`;
 * 4. the per-token budget (`per_token_per_minute`, keyed by the principal's token id) — 429 (2.9, audit ZDF-L1: before
 *    the account check, as TimeTrack, so a client retrying a refusal is bounded by its token's budget);
 * 5. the token's person can use the chat now (ChatAccessPolicyInterface::isReachable(): a disabled or anonymised
 *    account) — 401 `invalid_token`, audit reason `account_inactive` (the client signs out);
 * 6. the person's own chat budget, shared with the web UI (reads, or actions for read marks) — 429;
 * 7. the scope (`chat:read`) — 403 `insufficient_scope`;
 * 8. the context: the context header, or the token's own context, checked by ChatClientContextPolicyInterface on every
 *    request (`allows()`; a token bound to a context with no policy is refused: only the policy filters conversations) —
 *    400 `invalid_context` / `context_required`, 403 `context_forbidden`.
 *
 * Never reads or writes the session, never trusts a cookie, never changes the person's web context. Every refusal
 * after step 1 dispatches ChatClientApiDeniedEvent (ids and codes only). Stateless: nothing of a request is kept.
 *
 * 2.4.1: logout ({@see self::logout()}) runs steps 1–3 and a per-token budget of its own only, and answers the same way for an unknown, expired or
 * revoked token as for a revoked one (not an oracle).
 *
 * 2.5: the write routes (scope `chat:write`) answer 404 while `client_api.writes` is off (as if they did not exist), and
 * otherwise run the same steps, with the scopes checked right after the account check (audit ZCG-L2: before any write,
 * action or send budget is spent), then the token's write budget (`writes_per_token_per_minute`, own key)
 * and, at step 6, the person's action budget (plus the send budget for new messages, as the web UI). A write token must
 * hold `chat:read` as well (it reads back what it wrote).
 */
final readonly class ChatClientApiGuard
{
    /** 2.9: the module name of a `module_forbidden` refusal. */
    public const string MODULE = 'chat';

    /** 2.9: the error code of a permission lost after pairing ({@see ChatModuleForbiddenException}). */
    public const string MODULE_FORBIDDEN = 'module_forbidden';

    /** 2.9: the text of a `module_forbidden` refusal. */
    public const string MODULE_FORBIDDEN_MESSAGE = 'Chat is not available for this account.';

    /** Longest Bearer token passed to the resolver (bytes). */
    public const int MAX_TOKEN_LENGTH = 4096;

    /** Longest context header read (bytes); a longer value is `invalid_context`. */
    public const int MAX_CONTEXT_LENGTH = 128;

    /** The budget of a request: reads, or (read marks) the person's action budget. */
    public const string BUDGET_READ   = 'read';
    public const string BUDGET_ACTION = 'action';

    /** 2.5: a new message (send, thread reply): the person's action and send budgets, as the web UI. */
    public const string BUDGET_SEND = 'send';

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
     * @param bool $writes 2.5: whether the write routes are on (`client_api.writes`)
     * @param RateLimiterFactoryInterface|null $writeLimiter 2.5: per-token write budget; null: the per-token budget only
     * @param RateLimiterFactoryInterface|null $sendLimiter 2.5: the person's send budget (shared with the web UI)
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
        private bool $writes = false,
        private ?RateLimiterFactoryInterface $writeLimiter = null,
        private ?RateLimiterFactoryInterface $sendLimiter = null,
    ) {
    }

    /**
     * 2.5: whether the write routes are on (`client_api.writes`, with the API on).
     *
     * @return bool True when on
     */
    public function writes(): bool
    {
        return $this->writes && $this->enabled();
    }

    /**
     * 2.5: dispatches ChatClientApiWriteEvent after a write committed (ids only, never the text or the token).
     *
     * @param Request $request The request
     * @param ChatClientCall $call The call
     * @param string $action {@see ChatClientApiWriteEvent} action
     * @param string $conversationUuid The conversation
     * @param string|null $messageUuid The message written or changed
     * @param bool $replayed Whether an earlier request with the same Idempotency-Key wrote it
     */
    public function written(Request $request, ChatClientCall $call, string $action, string $conversationUuid, ?string $messageUuid, bool $replayed): void
    {
        $this->events?->dispatch(new ChatClientApiWriteEvent(
            $action,
            $call->principal->tokenId,
            $call->principal->user->getChatPublicId(),
            $conversationUuid,
            $messageUuid,
            $replayed,
            $call->contextKey,
            self::ipPrefix($request->getClientIp()),
        ));
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
     * @param string $scope The scope the route needs ({@see ChatClientPrincipal::SCOPE_WRITE}: `chat:read` too)
     * @param string $budget {@see self::BUDGET_READ}, {@see self::BUDGET_ACTION} or {@see self::BUDGET_SEND}
     *
     * @throws ChatApiException The refusal (status, code and headers)
     *
     * @return ChatClientCall The call
     */
    public function authenticate(Request $request, string $scope = ChatClientPrincipal::SCOPE_READ, string $budget = self::BUDGET_READ): ChatClientCall
    {
        $resolver = $this->resolver;
        $write    = $scope === ChatClientPrincipal::SCOPE_WRITE;
        if (!$resolver instanceof ChatClientPrincipalResolverInterface || ($write && !$this->writes)) {
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
        try {
            $principal = $resolver->resolve($bearer, $this->clientRequest($request, $ip, $header));
        } catch (ChatModuleForbiddenException $forbidden) {
            // 2.9 (audit ZDF-M1): the host validated the token, only the chat is closed to its person: 403, never 401
            // (a 401 signs the client out). The token's budget first (ZDF-L1): a client retrying the 403 is throttled.
            if ($forbidden->tokenId !== null) {
                $this->consume($this->tokenLimiter, $this->tokenKey($forbidden->tokenId), $forbidden, $ipPrefix);
            }
            $this->deny(self::MODULE_FORBIDDEN, Response::HTTP_FORBIDDEN, $forbidden, $ipPrefix, [], null, self::MODULE_FORBIDDEN_MESSAGE, ['module' => self::MODULE]);
        }
        if (!$principal instanceof ChatClientPrincipal) {
            $this->deny('invalid_token', Response::HTTP_UNAUTHORIZED, null, $ipPrefix, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        // Audit ZDF-L1: the token's budget before the account check (as TimeTrack's client API).
        $this->consume($this->tokenLimiter, $this->tokenKey($principal->tokenId), $principal, $ipPrefix);
        if (!$this->access->isReachable($principal->user)) {
            // A disabled or anonymised account: 401, the client signs out (unchanged since 2.4; audit ZDF-M1).
            $this->deny('invalid_token', Response::HTTP_UNAUTHORIZED, $principal, $ipPrefix, ['WWW-Authenticate' => 'Bearer error="invalid_token"'], 'account_inactive');
        }
        if ($write) {
            // Audit ZCG-L2: a token without the write scopes is refused before it spends any write, action or send
            // budget (the send budget is the person's dock budget). The IP and token budgets above still throttle probing.
            $this->requireScopes($principal, [ChatClientPrincipal::SCOPE_READ, ChatClientPrincipal::SCOPE_WRITE], $ipPrefix);
        }
        if ($write && $this->writeLimiter instanceof RateLimiterFactoryInterface) {
            // 2.5: writes have a per-token budget of their own on top of the token's request budget.
            $this->consume($this->writeLimiter, 'nowo_chat_client_write_' . hash('sha256', $principal->tokenId), $principal, $ipPrefix);
        }
        $person = $principal->user->getChatPublicId();
        if ($budget === self::BUDGET_READ) {
            $this->consume($this->readLimiter, 'nowo_chat_read_' . $person, $principal, $ipPrefix);
        } else {
            $this->consume($this->actionLimiter, 'nowo_chat_action_' . $person, $principal, $ipPrefix);
        }
        if ($budget === self::BUDGET_SEND && $this->sendLimiter instanceof RateLimiterFactoryInterface) {
            // The web UI's send budget (same key): a client cannot send faster than the person could in the dock.
            $this->consume($this->sendLimiter, 'nowo_chat_send_' . $person, $principal, $ipPrefix);
        }
        if (!$write) {
            $this->requireScopes($principal, [$scope], $ipPrefix);
        }

        $call = new ChatClientCall($principal, $this->context($principal, $header, $ipPrefix));
        $request->attributes->set(ChatClientCall::ATTRIBUTE, $call);

        return $call;
    }

    /**
     * 2.4.1: `POST logout`. Revokes the presented token only, through the host's revoker, and dispatches
     * ChatClientLoggedOutEvent. Runs the per-IP budget, the Bearer check and a per-token logout budget of its own
     * (`per_token_per_minute`, separate from the token's read budget: audit ZCB-L1; 429 + Retry-After); no usable Bearer
     * token (absent, blank or longer than 4096 bytes) answers 401 as on every route. A token the resolver does not accept
     * now (unknown, expired, revoked, another client family) returns silently: the caller answers the same 204 (no
     * oracle), nothing is revoked and no event is dispatched; 2.9: so does a token the resolver answers with
     * ChatModuleForbiddenException (logout never answers `module_forbidden`). Reachability, scope and context are not checked: a
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
        try {
            $principal = $resolver->resolve($bearer, $clientRequest);
        } catch (ChatModuleForbiddenException) {
            // 2.9: the token is valid but the chat is closed: the client forgets it; nothing revoked, no event (as a refused token).
            return;
        }
        if (!$principal instanceof ChatClientPrincipal) {
            return;
        }
        // Audit ZCB-L1: logout has its own per-token budget, so the token's read traffic can never starve its sign-out.
        $this->consume($this->tokenLimiter, 'nowo_chat_client_logout_' . hash('sha256', $principal->tokenId), $principal, $ipPrefix);
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
     * The per-token budget key of a token id (the id is hashed: the key never carries it).
     */
    private function tokenKey(string $tokenId): string
    {
        return 'nowo_chat_client_token_' . hash('sha256', $tokenId);
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
     * 403 `insufficient_scope` unless the token holds every scope (named together in `WWW-Authenticate`).
     *
     * @param list<string> $needed
     */
    private function requireScopes(ChatClientPrincipal $principal, array $needed, ?string $ipPrefix): void
    {
        foreach ($needed as $one) {
            if (!$principal->hasScope($one)) {
                $this->deny('insufficient_scope', Response::HTTP_FORBIDDEN, $principal, $ipPrefix, ['WWW-Authenticate' => sprintf('Bearer error="insufficient_scope", scope="%s"', implode(' ', $needed))]);
            }
        }
    }

    /**
     * Consumes one token of a budget; 429 + Retry-After when it is spent.
     */
    private function consume(RateLimiterFactoryInterface $factory, string $key, ChatClientPrincipal|ChatModuleForbiddenException|null $principal, ?string $ipPrefix): void
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
     * @param ChatClientPrincipal|ChatModuleForbiddenException|null $principal Who was refused (2.9: the ids a module refusal carries)
     * @param array<string, int|string> $details More fields of the error body (2.9: `module`)
     *
     * @throws ChatApiException Always
     */
    private function deny(string $error, int $status, ChatClientPrincipal|ChatModuleForbiddenException|null $principal, ?string $ipPrefix, array $headers = [], ?string $reason = null, string $message = '', array $details = []): never
    {
        $person = $principal instanceof ChatClientPrincipal ? $principal->user->getChatPublicId() : $principal?->userPublicId;
        $this->events?->dispatch(new ChatClientApiDeniedEvent($reason ?? $error, $principal?->tokenId, $person, $ipPrefix));

        throw new ChatApiException($error, $status, null, $message, $details, $headers);
    }
}
