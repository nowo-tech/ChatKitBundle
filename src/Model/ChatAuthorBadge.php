<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatAuthorBadgeProviderInterface;
use Nowo\ChatKitBundle\Enum\ChatAuthorBadgeVariant;

use function is_string;
use function mb_strlen;
use function preg_match;
use function trim;

/**
 * 1.29 (Beacon spec 234): a pill shown next to a message author's name (e.g. "Agent" for a service account), as
 * {@see ChatAuthorBadgeProviderInterface} gives it. Plain text only: the page prints `label` and `title` escaped and the
 * dock sets them with `textContent`, so a host never hands the bundle HTML. `variant` is an allow-list
 * ({@see ChatAuthorBadgeVariant}).
 *
 * Refused (`InvalidArgumentException`): an empty label, a label over {@see self::LABEL_MAX} or a title over
 * {@see self::TITLE_MAX} characters, invalid UTF-8, control or format characters, an unknown variant.
 */
final readonly class ChatAuthorBadge
{
    /** Longest label, in characters. */
    public const int LABEL_MAX = 40;

    /** Longest title (tooltip), in characters. */
    public const int TITLE_MAX = 120;

    /** The text of the pill (trimmed). */
    public string $label;

    /** How it looks. */
    public ChatAuthorBadgeVariant $variant;

    /** Tooltip; null for none. */
    public ?string $title;

    /**
     * Builds a badge.
     *
     * @param string $label Plain text, 1 to 40 characters once trimmed
     * @param ChatAuthorBadgeVariant|string $variant `neutral`, `info`, `success`, `warning` or `agent`
     * @param string|null $title Plain-text tooltip of at most 120 characters; null or empty for none
     *
     * @throws InvalidArgumentException When a value breaks the rules above
     */
    public function __construct(string $label, ChatAuthorBadgeVariant|string $variant = ChatAuthorBadgeVariant::Neutral, ?string $title = null)
    {
        $label = trim($label);
        if (!$this->isPlain($label) || $label === '' || mb_strlen($label) > self::LABEL_MAX) {
            throw new InvalidArgumentException('A chat author badge label is plain text of 1 to 40 characters.');
        }
        $resolved = is_string($variant) ? ChatAuthorBadgeVariant::tryFrom($variant) : $variant;
        if (!$resolved instanceof ChatAuthorBadgeVariant) {
            throw new InvalidArgumentException('A chat author badge variant is one of neutral, info, success, warning, agent.');
        }
        $title = $title === null ? null : trim($title);
        if ($title !== null && (!$this->isPlain($title) || mb_strlen($title) > self::TITLE_MAX)) {
            throw new InvalidArgumentException('A chat author badge title is plain text of at most 120 characters.');
        }
        $this->label   = $label;
        $this->variant = $resolved;
        $this->title   = $title === '' ? null : $title;
    }

    /**
     * The JSON shape of the badge (message API, realtime payloads, the page's template).
     *
     * @return array{label: string, variant: string, title: string|null} The shape
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'variant' => $this->variant->value, 'title' => $this->title];
    }

    /** Valid UTF-8 without control, format, line or paragraph separator characters. */
    private function isPlain(string $text): bool
    {
        return preg_match('/^[^\p{Cc}\p{Cf}\p{Zl}\p{Zp}]*$/u', $text) === 1;
    }
}
