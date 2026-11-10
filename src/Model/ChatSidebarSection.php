<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;

use function preg_match;
use function trim;

/**
 * 1.25: one group of the chat sidebar, given by a ChatSidebarSectionProviderInterface: a stable key, a label, its
 * place among the other sections, whether the viewer may fold it, its items and its actions.
 *
 * The bundle's own sections sit at POSITION_RECENT ("Recent" / "Unread") and POSITION_CONVERSATIONS ("Direct
 * messages and groups"); a host section with the default position 0 comes between them. Sections with the same
 * position keep the order the providers gave them.
 */
final readonly class ChatSidebarSection
{
    /** Pattern of a section key: lowercase letters, digits, `_`, `-`, `.` or `:`, starting with a letter or a digit. */
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9_.:-]{0,63}$/';

    /** Position of the bundle's "Recent" / "Unread" section. */
    public const int POSITION_RECENT = -1000;

    /** Position of the bundle's "Direct messages and groups" section. */
    public const int POSITION_CONVERSATIONS = 1000;

    /** Keys of the bundle's own sections (a host section with one of them is left out). */
    public const array RESERVED_KEYS = ['recent', 'conversations'];

    /** @var list<ChatSidebarItem> */
    public array $items;

    /** @var list<ChatSidebarAction> */
    public array $actions;

    /**
     * Builds a section.
     *
     * @param string $key Stable key (KEY_PATTERN), also the key of the folded state the browser remembers
     * @param string $label Heading, already translated
     * @param int $position Place among the sections, lowest first (POSITION_RECENT, 0, POSITION_CONVERSATIONS)
     * @param bool $collapsible Whether the viewer may fold it
     * @param bool $collapsedByDefault Folded until the viewer opens it (when collapsible)
     * @param iterable<ChatSidebarItem> $items Rows: conversations (by uuid) or links
     * @param iterable<ChatSidebarAction> $actions Links or dialogs under the rows (e.g. "Browse channels")
     *
     * @throws InvalidArgumentException When the key does not match KEY_PATTERN or the label is empty
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $position = 0,
        public bool $collapsible = true,
        public bool $collapsedByDefault = false,
        iterable $items = [],
        iterable $actions = [],
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('A sidebar section key must match ' . self::KEY_PATTERN . ', got "' . $key . '".');
        }
        if (trim($label) === '') {
            throw new InvalidArgumentException('A sidebar section needs a label.');
        }
        $list = [];
        foreach ($items as $item) {
            $list[] = $item;
        }
        $this->items = $list;
        $list        = [];
        foreach ($actions as $action) {
            $list[] = $action;
        }
        $this->actions = $list;
    }
}
