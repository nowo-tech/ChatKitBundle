<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;

use function in_array;
use function preg_match;
use function trim;

/**
 * 1.25: an action under the rows of a host sidebar section, e.g. "Browse channels" or "Create channel".
 *
 * `url` is a plain link (a path or an absolute http(s) URL). `dialogUrl` is an HTML fragment of the host (same
 * origin only: a path, or an absolute URL on the page's own origin) shown in a UiKit remote form dialog — the
 * standard anatomy: header with ×, scrolling body, footer outside it, `md` or `wide`. Its forms post back in the dialog
 * (UiKit `RemoteDialogResponder`). With both, `url` is the page the link falls back to without the dialog script; with
 * `dialogUrl` alone the link falls back to `dialogUrl` itself (UiKit's "one URL, two modes"). A URL that fails these
 * rules is dropped on the server (an action left with neither is dropped too).
 */
final readonly class ChatSidebarAction
{
    /** Pattern of an action key (same as a section key). */
    public const string KEY_PATTERN = ChatSidebarSection::KEY_PATTERN;

    /** The two UiKit dialog sizes. */
    public const array DIALOG_SIZES = ['md', 'wide'];

    /**
     * Builds an action.
     *
     * @param string $key Stable key within the section (KEY_PATTERN)
     * @param string $label Text of the action and title of its dialog, already translated
     * @param string|null $icon Dock icon name or UX Icons name; null = no icon
     * @param string|null $url Link (or the no-script fallback of the dialog)
     * @param string|null $dialogUrl Same-origin HTML fragment shown in the dialog
     * @param string $dialogSize `md` (forms, choosers) or `wide` (lists, tables)
     *
     * @throws InvalidArgumentException When the key is invalid, the label empty, neither URL is given or the size is unknown
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $icon = null,
        public ?string $url = null,
        public ?string $dialogUrl = null,
        public string $dialogSize = 'md',
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('A sidebar action key must match ' . self::KEY_PATTERN . ', got "' . $key . '".');
        }
        if (trim($label) === '') {
            throw new InvalidArgumentException('A sidebar action needs a label.');
        }
        if ($url === null && $dialogUrl === null) {
            throw new InvalidArgumentException('A sidebar action needs a url or a dialogUrl.');
        }
        if (!in_array($dialogSize, self::DIALOG_SIZES, true)) {
            throw new InvalidArgumentException('A sidebar action dialogSize must be "md" or "wide", got "' . $dialogSize . '".');
        }
    }
}
