<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\View;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\EmptyState;
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

    /**
     * 1.35: header of the Saved page (`chat.saved_title`, intro `chat.saved_intro`).
     *
     * @return PageHeader The page header view model
     */
    public static function savedHeader(): PageHeader
    {
        return new PageHeader(new Label('chat.saved_title', self::BUNDLE), new Label('chat.saved_intro', self::BUNDLE));
    }

    /**
     * 2.1: empty state of the Saved page through UiKit's `empty` partial: one sentence (`chat.saved_empty`) and a link
     * to the conversations (`nowo_chat_page`, `chat.saved_back`).
     *
     * @return EmptyState The empty state view model
     */
    public static function savedEmpty(): EmptyState
    {
        return new EmptyState(
            message: new Label('chat.saved_empty', self::BUNDLE),
            action: new Action('view', new Label('chat.saved_back', self::BUNDLE), route: 'nowo_chat_page', variant: 'link', attributes: ['data-testid' => 'chat-saved-back']),
        );
    }

    /**
     * 1.37: header of the message search page (`chat_search.title`, intro `chat_search.intro`). 1.39.1: with the
     * page's back link (`chat_search.back`; the controller gives a same-origin path or the chat home).
     *
     * @param BackLink|null $back The back link above the title
     *
     * @return PageHeader The page header view model
     */
    public static function searchHeader(?BackLink $back = null): PageHeader
    {
        return new PageHeader(new Label('chat_search.title', self::BUNDLE), new Label('chat_search.intro', self::BUNDLE), back: $back);
    }
}
