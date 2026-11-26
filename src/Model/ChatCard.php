<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Enum\ChatCardState;

use function array_slice;
use function array_values;
use function get_debug_type;
use function in_array;

/**
 * 2.6 (spec 259): a typed card as one viewer sees it (ChatCardProviderInterface::render()): every string is plain text,
 * every URL a same-origin path. A card never carries a secret value, a key or a link that holds one.
 */
final readonly class ChatCard
{
    /** Facts shown at most. */
    public const int MAX_FACTS = 4;

    /** Actions shown at most. */
    public const int MAX_ACTIONS = 3;

    /** Longest title (characters). */
    public const int TITLE_MAX = 120;

    /** Longest subtitle and badge (characters). */
    public const int TEXT_MAX = 160;

    public string $title;

    public string $subtitle;

    public string $badge;

    public string $tone;

    /** @var list<ChatCardFact> */
    public array $facts;

    /** @var list<ChatCardAction> */
    public array $actions;

    /**
     * @param ChatCardState $state Open or Locked (a hidden card is left out of the provider's answer)
     * @param string $title Title (plain text)
     * @param string $subtitle Second line (plain text)
     * @param string $badge Short status (plain text)
     * @param string $tone One of ChatReference::TONES (anything else is `neutral`)
     * @param iterable<ChatCardFact> $facts At most MAX_FACTS are kept
     * @param iterable<ChatCardAction> $actions At most MAX_ACTIONS are kept
     *
     * @throws InvalidArgumentException When the title is empty, or a fact / action has the wrong type
     */
    public function __construct(
        public ChatCardState $state,
        string $title,
        string $subtitle = '',
        string $badge = '',
        string $tone = 'neutral',
        iterable $facts = [],
        iterable $actions = [],
    ) {
        $this->title    = ChatCardText::required($title, self::TITLE_MAX, 'A card');
        $this->subtitle = ChatCardText::clip($subtitle, self::TEXT_MAX);
        $this->badge    = ChatCardText::clip($badge, self::TEXT_MAX);
        $this->tone     = in_array($tone, ChatReference::TONES, true) ? $tone : 'neutral';
        $list           = [];
        foreach ($facts as $fact) {
            if (!$fact instanceof ChatCardFact) {
                throw new InvalidArgumentException('Card facts must be ' . ChatCardFact::class . ' instances, got ' . get_debug_type($fact) . '.');
            }
            $list[] = $fact;
        }
        $this->facts = array_values(array_slice($list, 0, self::MAX_FACTS));
        $list        = [];
        foreach ($actions as $action) {
            if (!$action instanceof ChatCardAction) {
                throw new InvalidArgumentException('Card actions must be ' . ChatCardAction::class . ' instances, got ' . get_debug_type($action) . '.');
            }
            $list[] = $action;
        }
        $this->actions = array_values(array_slice($list, 0, self::MAX_ACTIONS));
    }

    /**
     * The `card` object of the API and the dock: state, facts and actions (title, subtitle, badge and tone travel on the
     * reference itself).
     *
     * @return array{state: string, facts: list<array{label: string, value: string}>, actions: list<array{key: string, label: string, kind: string, url: string, dialog: bool}>}
     */
    public function toArray(): array
    {
        return [
            'state'   => $this->state->value,
            'facts'   => array_map(static fn (ChatCardFact $fact): array => $fact->toArray(), $this->facts),
            'actions' => array_map(static fn (ChatCardAction $action): array => $action->toArray(), $this->actions),
        ];
    }
}
