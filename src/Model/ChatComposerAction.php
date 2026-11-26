<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;

/**
 * 2.6 (spec 259): a host action of the composer (Beacon: "Share a vault item", "One-time secret"): a button, an
 * optional slash alias, and a host page the dock opens in its UiKit dialog with `?conversation=<uuid>`. The dialog never
 * receives the draft; it inserts a card by dispatching `nowo-chat:insert-card` `{type, id}`.
 */
final readonly class ChatComposerAction
{
    /** Longest label (characters). */
    public const int LABEL_MAX = 60;

    public string $key;

    public string $label;

    public string $icon;

    public ?string $slash;

    public string $dialogUrl;

    /**
     * @param string $key Unique key across providers (reference key grammar)
     * @param string $label Translated label (plain text)
     * @param string $icon Dock icon name (`key` when not a valid name)
     * @param string $dialogUrl Same-origin path of the host dialog page (never carries draft text)
     * @param string|null $slash Slash alias without `/` (e.g. `secret`); refused when it is a reference or card type
     * @param int $order Position among the actions, lowest first
     *
     * @throws InvalidArgumentException When the key, the label, the slash or the URL is not valid
     */
    public function __construct(string $key, string $label, string $icon, string $dialogUrl, ?string $slash = null, public int $order = 0)
    {
        $this->key       = ChatCardText::key($key, 'A composer action');
        $this->label     = ChatCardText::required($label, self::LABEL_MAX, 'A composer action');
        $this->icon      = ChatCardText::icon($icon, 'key');
        $this->dialogUrl = ChatCardText::path($dialogUrl, 'A composer action');
        $this->slash     = $slash === null ? null : ChatCardText::key($slash, 'A composer action slash');
    }
}
