<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use function in_array;

/**
 * An item of the application a message refers to (`#[Title](type:id)`): an issue, a task, a project … as the viewer
 * may see it. Plain text only; the URL must be root-relative or http(s) (anything else is rendered without a link).
 */
final readonly class ChatReference
{
    /** Badge colours a provider may use; anything else is shown as `neutral`. */
    public const array TONES = ['neutral', 'info', 'success', 'warning', 'danger'];

    /**
     * Builds a reference card.
     *
     * @param string $type Type key of its provider (`task`)
     * @param string $id Identifier inside the type (letters, digits, `_`, `-`; at most 64)
     * @param string $title Title shown on the card
     * @param string $url Where the card links to (root-relative or http(s))
     * @param string $subtitle Secondary line (project, column …); empty hides it
     * @param string $badge Short status label (`Open`, `Down`); empty hides it
     * @param string $tone Badge colour: neutral, info, success, warning or danger
     * @param ChatCard|null $card 2.6 (spec 259): set by ChatKit for a typed card (state, facts, actions); providers
     *                            of references leave it null
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $title,
        public string $url,
        public string $subtitle = '',
        public string $badge = '',
        public string $tone = 'neutral',
        public ?ChatCard $card = null,
    ) {
    }

    /**
     * The `type:id` key of the reference.
     *
     * @return string The key
     */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }

    /**
     * Serializes the card for the JSON API.
     *
     * @return array{type: string, id: string, title: string, url: string, subtitle: string, badge: string, tone: string, card?: array{state: string, facts: list<array{label: string, value: string}>, actions: list<array{key: string, label: string, kind: string, url: string, dialog: bool}>}} The card fields (2.6: `card` for a typed card only)
     */
    public function toArray(): array
    {
        $card = $this->card instanceof ChatCard ? ['card' => $this->card->toArray()] : [];

        return [
            'type'     => $this->type,
            'id'       => $this->id,
            'title'    => $this->title,
            'url'      => $this->url,
            'subtitle' => $this->subtitle,
            'badge'    => $this->badge,
            'tone'     => in_array($this->tone, self::TONES, true) ? $this->tone : 'neutral',
        ] + $card;
    }
}
