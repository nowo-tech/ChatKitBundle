<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Controller\ChatApiController;

use function max;
use function preg_match;
use function trim;

/**
 * 1.25: one row of a host sidebar section — a conversation (by its uuid, e.g. a room channel) or a link.
 *
 * With `conversationUuid` the chat fills unread, mentions and muted itself and keeps them live (the values given here
 * are ignored), checks that the viewer may read the conversation (else the row is left out) and opens it in the chat.
 * With `url` the row is a link (a path or an absolute http(s) URL, like `web_ui.channels_url`; any other value leaves
 * the row out) and the given counts are shown as they are.
 *
 * `icon`: `channel` (#), `private` (a lock), any dock icon name (the built-in set, or a name the host maps to a UX
 * Icons name under `nowo_chat_kit.icons`), or a UX Icons name such as `tabler:speakerphone` (rendered on the server
 * when symfony/ux-icons can; `channel` otherwise).
 */
final readonly class ChatSidebarItem
{
    /** Pattern of an item key (same as a section key). */
    public const string KEY_PATTERN = ChatSidebarSection::KEY_PATTERN;

    public int $unread;

    public int $mentions;

    /**
     * Builds an item.
     *
     * @param string $key Stable key within the section (KEY_PATTERN)
     * @param string $label Row text (e.g. the channel name, without `#`), already translated
     * @param string $icon `channel`, `private`, a dock icon name or a UX Icons name
     * @param string|null $conversationUuid The conversation the row opens; null with `url`
     * @param string|null $url Link of the row; null with `conversationUuid`
     * @param int $unread Unread count of a link row (ignored with `conversationUuid`)
     * @param int $mentions Unread mentions of a link row (ignored with `conversationUuid`)
     * @param bool $muted Muted look of a link row (ignored with `conversationUuid`)
     * @param string|null $subtitle Second text (tooltip in the compact list), e.g. the topic
     *
     * @throws InvalidArgumentException When the key is invalid, the label empty, or not exactly one of conversationUuid / url is given
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon = 'channel',
        public ?string $conversationUuid = null,
        public ?string $url = null,
        int $unread = 0,
        int $mentions = 0,
        public bool $muted = false,
        public ?string $subtitle = null,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('A sidebar item key must match ' . self::KEY_PATTERN . ', got "' . $key . '".');
        }
        if (trim($label) === '') {
            throw new InvalidArgumentException('A sidebar item needs a label.');
        }
        if (($conversationUuid === null) === ($url === null)) {
            throw new InvalidArgumentException('A sidebar item needs exactly one of conversationUuid and url.');
        }
        if ($conversationUuid !== null && preg_match('/^' . ChatApiController::UUID . '$/', $conversationUuid) !== 1) {
            throw new InvalidArgumentException('A sidebar item conversationUuid must be a lowercase uuid, got "' . $conversationUuid . '".');
        }
        $this->unread   = max(0, $unread);
        $this->mentions = max(0, $mentions);
    }
}
