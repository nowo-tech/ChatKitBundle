<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;

use function in_array;
use function is_string;
use function preg_match;

/**
 * 2.4 (client API v1): who calls the client API, as the host's ChatClientPrincipalResolverInterface answers for one
 * Bearer token: the chat participant, the chat scopes the token holds, a stable id of the token (never the secret: it
 * keys the per-token rate limit and the refusal events) and, for a token bound to one tenant at approval, its context
 * key. Built per request and never stored (FrankenPHP worker mode, kernel reset false).
 */
final readonly class ChatClientPrincipal
{
    /** Read conversations, messages, read marks (Q-BD-8) and the realtime subscriber token. */
    public const string SCOPE_READ = 'chat:read';

    /** Reserved for the write routes of a later version (send, edit, mute). No v1 route needs it. */
    public const string SCOPE_WRITE = 'chat:write';

    /** A token id or a context key: 1–128 visible ASCII characters (letters, digits, `.`, `_`, `:`, `-`), starting with a letter or digit. */
    public const string KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D';

    /** @var list<string> */
    public array $scopes;

    /**
     * @param ChatUserInterface $user The chat participant the token belongs to
     * @param array<array-key, mixed> $scopes Scopes the token holds (non-string values are dropped)
     * @param string $tokenId A stable, non-secret id of the token or its credential ({@see self::KEY_PATTERN})
     * @param string|null $contextKey The tenant the token is bound to; null when the token is not bound to one
     *
     * @throws InvalidArgumentException When the token id or the context key does not match {@see self::KEY_PATTERN}
     */
    public function __construct(
        public ChatUserInterface $user,
        array $scopes,
        public string $tokenId,
        public ?string $contextKey = null,
    ) {
        if (preg_match(self::KEY_PATTERN, $tokenId) !== 1) {
            throw new InvalidArgumentException('A chat client token id must match ' . self::KEY_PATTERN . '.');
        }
        if ($contextKey !== null && preg_match(self::KEY_PATTERN, $contextKey) !== 1) {
            throw new InvalidArgumentException('A chat client context key must match ' . self::KEY_PATTERN . '.');
        }
        $kept = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && !in_array($scope, $kept, true)) {
                $kept[] = $scope;
            }
        }
        $this->scopes = $kept;
    }

    /**
     * Whether the token holds the scope (exact, case-sensitive).
     *
     * @param string $scope A scope such as {@see self::SCOPE_READ}
     *
     * @return bool True when held
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
