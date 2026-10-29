<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * A support ticket topic (incident, billing …): its key, label and automatic first reply, an optional icon, emoji and
 * default assignee. `label` and `reply` are translation keys in `domain`, or plain text when `domain` is null
 * (topics an application manages as data).
 */
final readonly class ChatSupportTopic
{
    /**
     * Builds a topic.
     *
     * @param string $key Stable key stored on the ticket (1–64 characters: a-z, 0-9, `_`, `-`)
     * @param string $label Label (translation key in `domain`, or plain text)
     * @param string|null $reply Automatic reply posted when a ticket opens with this topic; null for none
     * @param string|null $icon Icon name for the application's templates (e.g. `tabler:bug`); null for none
     * @param string|null $assignee Public id of the agent new tickets of this topic are assigned to; null for none
     * @param string|null $domain Translation domain of `label` and `reply`; null when they are plain text
     * @param string|null $emoji Decorative emoji shown before the label (`aria-hidden`); null for none
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $reply = null,
        public ?string $icon = null,
        public ?string $assignee = null,
        public ?string $domain = null,
        public ?string $emoji = null,
    ) {
    }
}
