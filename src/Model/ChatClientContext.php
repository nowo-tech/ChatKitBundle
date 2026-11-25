<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;

use function mb_substr;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * 2.4 (client API v1): a tenant a person may use the client API in (`GET me`), as the host's
 * ChatClientContextPolicyInterface lists it: a key the client sends back in the context header and a plain-text label.
 */
final readonly class ChatClientContext
{
    /** Longest label kept (characters). */
    public const int LABEL_MAX_LENGTH = 120;

    /** The label without control or bidirectional characters, at most {@see self::LABEL_MAX_LENGTH} characters. */
    public string $label;

    /**
     * @param string $key The context key ({@see ChatClientPrincipal::KEY_PATTERN}), e.g. `personal` or `org:<uuid>`
     * @param string $label Plain text shown in the client's context switcher
     *
     * @throws InvalidArgumentException When the key does not match {@see ChatClientPrincipal::KEY_PATTERN}
     */
    public function __construct(
        public string $key,
        string $label,
    ) {
        if (preg_match(ChatClientPrincipal::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('A chat client context key must match ' . ChatClientPrincipal::KEY_PATTERN . '.');
        }
        $this->label = mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', ' ', $label)), 0, self::LABEL_MAX_LENGTH);
    }

    /**
     * The JSON shape of `GET me`.
     *
     * @return array{key: string, label: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label];
    }
}
