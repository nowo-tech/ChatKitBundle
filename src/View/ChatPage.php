<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\View;

use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;

/**
 * UiKit view data of the full-page chat (REQ-UI-003 unique page `page.html.twig`): the page header rendered through
 * the UiKit `page_header` theme partial, and the bundle name of the theme exceptions
 * (`nowo_ui_kit.theme.bundles.NowoChatKitBundle`).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatPage
{
    public const string BUNDLE = 'NowoChatKitBundle';

    /**
     * Header of the chat page: the `chat.page_title` title (the open conversation has its own header in the thread).
     *
     * @return PageHeader The page header view model
     */
    public static function header(): PageHeader
    {
        return new PageHeader(new Label('chat.page_title', self::BUNDLE));
    }
}
