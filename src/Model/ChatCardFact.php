<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;

/**
 * 2.6 (spec 259): one fact of a card, plain text ("Vault: Project X", "Views left: 1"). Never a secret value.
 */
final readonly class ChatCardFact
{
    /** Longest label (characters). */
    public const int LABEL_MAX = 40;

    /** Longest value (characters). */
    public const int VALUE_MAX = 120;

    public string $label;

    public string $value;

    /**
     * @param string $label Translated label
     * @param string $value Plain-text value
     *
     * @throws InvalidArgumentException When the label is empty
     */
    public function __construct(string $label, string $value)
    {
        $this->label = ChatCardText::required($label, self::LABEL_MAX, 'A card fact');
        $this->value = ChatCardText::clip($value, self::VALUE_MAX);
    }

    /**
     * @return array{label: string, value: string}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => $this->value];
    }
}
