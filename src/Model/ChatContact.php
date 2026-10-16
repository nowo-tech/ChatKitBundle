<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * A person the actor may message, with why ("Backend" group, "Acme" project) for the people picker.
 */
final readonly class ChatContact
{
    /**
     * Builds a picker contact from the user, the source group key and the shared-context label.
     *
     * @param ChatUserInterface $user The person the actor may message
     * @param string $source Free-form source key used to group the picker
     * @param string $via Label of the shared context; empty when not applicable
     */
    public function __construct(
        public ChatUserInterface $user,
        /** Free-form source key used to group the picker (e.g. `group`, `project`, `search`). */
        public string $source,
        /** Human label of the shared context, e.g. the group or project name; empty when not applicable. */
        public string $via = '',
    ) {
    }
}
