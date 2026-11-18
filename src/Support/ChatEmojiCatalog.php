<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use function array_merge;
use function array_unique;
use function array_values;
use function in_array;

/**
 * 1.35: the curated emoji set of the dock's picker (`emoji.ts`, same order), so the "More…" item of the message menu can
 * react with any of them (`message_menu.quick_reactions.picker`). A parity test keeps both lists equal. Nothing else is
 * accepted as a reaction: no arbitrary string, so no invisible or look-alike "emoji" is ever stored.
 *
 * Stateless (constants only).
 */
final class ChatEmojiCatalog
{
    /** Every emoji of the picker, by category (smileys, gestures, objects, symbols). */
    public const array ALL = [
        // smileys
        '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '😊', '🙂', '😉', '😍', '😘', '😎', '🤩', '🤔', '🤨', '😐', '🙄', '😏', '😴', '😌', '😜', '🤪', '😬', '😮', '😯', '😲', '😳', '🥺', '😢', '😭', '😤', '😠', '😡', '🤯', '😱', '🥳', '🤗', '🤫', '🤭', '🙃', '😇', '🤓', '🧐', '😷', '🤒', '🤕', '🥵', '🥶',
        // gestures
        '👍', '👎', '👌', '✌️', '🤞', '🤝', '👏', '🙌', '👐', '🙏', '💪', '👋', '🤙', '👀', '🫡', '🤷', '🤦', '🙋', '🙆', '🙅', '💯', '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '💔', '✨',
        // objects
        '🔥', '🚀', '🎉', '🎯', '🏆', '⚡', '💡', '🐛', '🔧', '🛠️', '⚙️', '🧪', '📦', '📈', '📉', '📊', '🗂️', '📌', '📎', '🔒', '🔑', '💻', '🖥️', '📱', '☕', '🍕', '🍺', '⏰', '📅', '📝',
        // symbols
        '✅', '❌', '⚠️', '❗', '❓', '➕', '➖', '🔁', '🔄', '⏳', '🆗', '🆕', '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '⭐', '💬',
    ];

    /**
     * Whether the emoji is one of the picker's.
     *
     * @param string $emoji The emoji
     *
     * @return bool True when the catalog has it
     */
    public static function contains(string $emoji): bool
    {
        return in_array($emoji, self::ALL, true);
    }

    /**
     * The reactions the server accepts: the configured ones, plus the catalog when the picker is offered.
     *
     * @param list<string> $configured `reactions`
     * @param bool $picker `message_menu.quick_reactions.picker`
     *
     * @return list<string> Every accepted emoji, configured ones first, no duplicate
     */
    public static function accepted(array $configured, bool $picker): array
    {
        return array_values(array_unique($picker ? array_merge($configured, self::ALL) : $configured));
    }
}
