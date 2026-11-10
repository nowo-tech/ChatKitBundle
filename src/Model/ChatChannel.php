<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * A group conversation defined by the application (e.g. one per project): a stable key and a label.
 *
 * 1.24: optionally read only (archived rooms: members read, nobody writes, edits, reacts, pins or changes links), a
 * subtitle (a topic, shown under the title instead of "Channel") and an icon (a short text such as an emoji, shown in
 * the avatar bubble instead of `#`). The dock writes both through `textContent`; the API trims them, drops control
 * and bidirectional-override characters and cuts them to SUBTITLE_MAX_LENGTH / ICON_MAX_LENGTH characters.
 */
final readonly class ChatChannel
{
    /** Longest subtitle sent to the dock (characters). */
    public const int SUBTITLE_MAX_LENGTH = 160;

    /** Longest icon sent to the dock (characters: an emoji with modifiers, or a short label). */
    public const int ICON_MAX_LENGTH = 8;

    /**
     * Builds a channel from its stable key and its display label.
     *
     * @param string $key Stable, unique key such as `project:<uuid>`
     * @param string $label Human-readable channel name
     * @param bool $readOnly 1.24: true when nobody may write in it now (still readable by its members)
     * @param string|null $subtitle 1.24: shown under the title (e.g. the channel topic); null shows "Channel"
     * @param string|null $icon 1.24: short text shown in the avatar bubble (e.g. an emoji); null shows `#`
     */
    public function __construct(
        /** Stable, unique key such as `project:<uuid>` (max. 120 characters). */
        public string $key,
        public string $label,
        public bool $readOnly = false,
        public ?string $subtitle = null,
        public ?string $icon = null,
    ) {
    }
}
