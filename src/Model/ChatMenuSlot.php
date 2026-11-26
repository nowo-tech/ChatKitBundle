<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Enum\ChatMenuSlotTarget;

use function array_values;
use function is_string;

/**
 * 2.6 (spec 259): a host item of the message menu (Beacon: "Save to vault"). It opens a host page in the dock's UiKit
 * dialog with `?conversation=<uuid>&message=<uuid>` (and `&card=<type>:<id>` on a card). Its place is
 * `message_menu.order` as `slot:<key>`; a slot not listed there comes last.
 */
final readonly class ChatMenuSlot
{
    /** Longest label (characters). */
    public const int LABEL_MAX = 60;

    public string $key;

    public string $label;

    public string $icon;

    public string $dialogUrl;

    /** @var list<string> */
    public array $cardTypes;

    /**
     * @param string $key Unique key across providers (reference key grammar)
     * @param string $label Translated label (plain text)
     * @param string $icon Dock icon name (`key` when not a valid name)
     * @param ChatMenuSlotTarget $target Message, selection or card
     * @param string $dialogUrl Same-origin path of the host dialog page
     * @param iterable<string> $cardTypes With ChatMenuSlotTarget::Card: the card types it applies to (at least one)
     * @param bool $ownOnly Offered on the viewer's own messages only
     *
     * @throws InvalidArgumentException When the key, the label, the URL or the card types are not valid
     */
    public function __construct(
        string $key,
        string $label,
        string $icon,
        public ChatMenuSlotTarget $target,
        string $dialogUrl,
        iterable $cardTypes = [],
        public bool $ownOnly = false,
    ) {
        $this->key       = ChatCardText::key($key, 'A message menu slot');
        $this->label     = ChatCardText::required($label, self::LABEL_MAX, 'A message menu slot');
        $this->icon      = ChatCardText::icon($icon, 'key');
        $this->dialogUrl = ChatCardText::path($dialogUrl, 'A message menu slot');
        $types           = [];
        foreach ($cardTypes as $type) {
            $types[ChatCardText::key(is_string($type) ? $type : '', 'A message menu slot card type')] = $type;
        }
        if ($target === ChatMenuSlotTarget::Card && $types === []) {
            throw new InvalidArgumentException('A message menu slot on cards needs at least one card type.');
        }
        $this->cardTypes = $target === ChatMenuSlotTarget::Card ? array_values($types) : [];
    }
}
