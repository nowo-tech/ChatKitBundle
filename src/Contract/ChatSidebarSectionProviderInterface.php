<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatSidebarSection;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.25: host sections of the chat sidebar (the grouped, collapsible list of the full-page chat and, with
 * `web_ui.dock.sidebar_sections`, of the dock), e.g. "Channels" with the rooms the viewer belongs to and the actions
 * "Browse channels" / "Create channel". Tag the service `nowo_chat_kit.sidebar_section_provider` (autoconfigured);
 * providers run in tag priority order (highest first), and the sections are then ordered by their position.
 *
 * The host alone decides what each viewer sees (deny by default): list only the conversations, pages and actions the
 * viewer may open. The bundle never widens that list, and it checks again on its own that the viewer may read every
 * conversationUuid it renders (an item the viewer may not read is dropped silently). Unread, mention and mute
 * counts of those items come from the chat, never from the provider.
 */
interface ChatSidebarSectionProviderInterface
{
    /** Service tag of sidebar section providers. */
    public const string TAG = 'nowo_chat_kit.sidebar_section_provider';

    /**
     * The sections this provider shows to the viewer (called for each sidebar load, so keep it to one or two queries).
     *
     * @param ChatUserInterface $viewer The signed-in chat user
     *
     * @return iterable<ChatSidebarSection> The sections (an empty one is still shown when it has actions)
     */
    public function sections(ChatUserInterface $viewer): iterable;
}
