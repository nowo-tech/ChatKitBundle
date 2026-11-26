<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Enum\ChatCardActionKind;

/**
 * 2.6 (spec 259): one action of a card: a same-origin link of the host, or (`dialog`) a host page the dock opens in its
 * UiKit dialog. The URL is a reference to the host's own page; it must never carry a secret or a key (the host checks
 * the viewer again when the page is opened).
 */
final readonly class ChatCardAction
{
    /** Longest label (characters). */
    public const int LABEL_MAX = 40;

    public string $key;

    public string $label;

    public string $url;

    /**
     * @param string $key Action key (reference key grammar)
     * @param string $label Translated label
     * @param ChatCardActionKind $kind What it does
     * @param string $url Same-origin path of the host page
     * @param bool $dialog Open the page in the dock's dialog instead of following the link
     *
     * @throws InvalidArgumentException When the key, the label or the URL is not valid
     */
    public function __construct(string $key, string $label, public ChatCardActionKind $kind, string $url, public bool $dialog = false)
    {
        $this->key   = ChatCardText::key($key, 'A card action');
        $this->label = ChatCardText::required($label, self::LABEL_MAX, 'A card action');
        $this->url   = ChatCardText::path($url, 'A card action');
    }

    /**
     * @return array{key: string, label: string, kind: string, url: string, dialog: bool}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'kind' => $this->kind->value, 'url' => $this->url, 'dialog' => $this->dialog];
    }
}
