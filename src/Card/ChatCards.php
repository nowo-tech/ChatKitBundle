<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Card;

use LogicException;
use Nowo\ChatKitBundle\Contract\ChatCardProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatComposerActionProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatMessageMenuSlotProviderInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatCard;
use Nowo\ChatKitBundle\Model\ChatCardScope;
use Nowo\ChatKitBundle\Model\ChatCardText;
use Nowo\ChatKitBundle\Model\ChatComposerAction;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatMenuSlot;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;
use UnexpectedValueException;

use function array_chunk;
use function array_keys;
use function array_slice;
use function count;
use function get_debug_type;
use function in_array;
use function is_countable;
use function preg_match;
use function sprintf;
use function usort;

/**
 * 2.6 (spec 259): typed cards, composer action slots and message menu slots, through the tagged host providers.
 *
 * - Every provider call is isolated: a provider that throws or answers something else is logged (class and message
 *   only, never ids or texts) and treated as "deny" (canShare false, the card hidden, no action, no slot).
 * - Keys are checked once: an invalid or repeated card type is a configuration error (LogicException). Composer
 *   actions and slots with a repeated key keep the first; a slash alias that is a reference or card type, or repeated,
 *   is dropped (the action stays as a button).
 *
 * Stateless apart from the provider map built once from the container's immutable tagged services (worker mode).
 */
final class ChatCards
{
    /** Card ids per render() call at most. */
    public const int RENDER_BATCH = 50;

    /** Composer actions offered at most. */
    public const int MAX_ACTIONS = 8;

    /** Message menu slots offered at most. */
    public const int MAX_SLOTS = 8;

    /** @var array<string, ChatCardProviderInterface>|null */
    private ?array $byKey = null;

    /**
     * @param iterable<ChatCardProviderInterface> $cardProviders The application's card providers
     * @param iterable<ChatComposerActionProviderInterface> $actionProviders The application's composer action providers
     * @param iterable<ChatMessageMenuSlotProviderInterface> $slotProviders The application's message menu slot providers
     * @param LoggerInterface|null $logger Where a failing provider is reported
     */
    public function __construct(
        #[AutowireIterator(ChatCardProviderInterface::TAG)]
        private readonly iterable $cardProviders = [],
        #[AutowireIterator(ChatComposerActionProviderInterface::TAG)]
        private readonly iterable $actionProviders = [],
        #[AutowireIterator(ChatMessageMenuSlotProviderInterface::TAG)]
        private readonly iterable $slotProviders = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether any card provider is registered (`me.features.cards`, the `cards` write field).
     */
    public function isEnabled(): bool
    {
        return $this->providers() !== [];
    }

    /**
     * Whether the dock needs its slot dialog: any card, composer action or message menu slot provider.
     */
    public function hasSlots(): bool
    {
        return $this->isEnabled() || $this->any($this->actionProviders) || $this->any($this->slotProviders);
    }

    /**
     * Whether any message menu slot provider is registered.
     */
    public function hasMenuSlots(): bool
    {
        return $this->any($this->slotProviders);
    }

    /**
     * Whether a type is a card type.
     *
     * @param string $type The type key
     */
    public function isCardType(string $type): bool
    {
        return isset($this->providers()[$type]);
    }

    /**
     * Whether a card type is a secret (an unknown type counts as one: never show its title).
     *
     * @param string $type The type key
     */
    public function isSecret(string $type): bool
    {
        $provider = $this->providers()[$type] ?? null;
        if ($provider === null) {
            return true;
        }
        try {
            return $provider->isSecret();
        } catch (Throwable $exception) {
            $this->failed($provider, $exception);

            return true;
        }
    }

    /**
     * The card type keys (for the reference/card key uniqueness check).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers());
    }

    /**
     * The label and icon of a card type; null for an unknown type.
     *
     * @param string $type The type key
     *
     * @return array{label: string, icon: string, secret: bool}|null
     */
    public function typeView(string $type): ?array
    {
        $provider = $this->providers()[$type] ?? null;
        if ($provider === null) {
            return null;
        }
        try {
            return ['label' => ChatCardText::clip($provider->label(), 60), 'icon' => ChatCardText::icon($provider->icon(), 'key'), 'secret' => $provider->isSecret()];
        } catch (Throwable $exception) {
            $this->failed($provider, $exception);

            return null;
        }
    }

    /**
     * Every card type with its label and icon (the dock's composer: chips and the insert check).
     *
     * @return list<array{key: string, label: string, icon: string, secret: bool}>
     */
    public function types(): array
    {
        $types = [];
        foreach ($this->keys() as $key) {
            $view = $this->typeView($key);
            if ($view !== null) {
                $types[] = ['key' => $key] + $view;
            }
        }

        return $types;
    }

    /**
     * The card scope of a person in a conversation.
     *
     * @param ChatUserInterface $user The author or reader
     * @param ChatConversation $conversation The conversation
     * @param string|null $contextKey The client API context (null on the web)
     * @param bool $api Whether the request comes through the client API
     */
    public static function scope(ChatUserInterface $user, ChatConversation $conversation, ?string $contextKey = null, bool $api = false): ChatCardScope
    {
        return new ChatCardScope($user->getChatPublicId(), $conversation->getUuid(), $conversation->getKind()->value, $conversation->isChannel() ? $conversation->getChannelKey() : null, $contextKey, $api);
    }

    /**
     * The composer / menu scope of a person in a conversation.
     *
     * @param ChatUserInterface $user The person
     * @param ChatConversation $conversation The conversation
     * @param bool $canSend Whether the person may write in it
     * @param string|null $contextKey The client API context (null on the web)
     */
    public static function composerScope(ChatUserInterface $user, ChatConversation $conversation, bool $canSend, ?string $contextKey = null): ChatComposerScope
    {
        return new ChatComposerScope($user->getChatPublicId(), $conversation->getUuid(), $conversation->getKind()->value, $conversation->isChannel() ? $conversation->getChannelKey() : null, $contextKey, $canSend);
    }

    /**
     * Whether the author may share the card here (deny on any failure).
     *
     * @param ChatCardScope $scope The author's scope
     * @param string $type The card type
     * @param string $id The card id
     */
    public function canShare(ChatCardScope $scope, string $type, string $id): bool
    {
        $provider = $this->providers()[$type] ?? null;
        if ($provider === null || preg_match(ChatCardText::ID_PATTERN, $id) !== 1) {
            return false;
        }
        try {
            return $provider->canShare($scope, $id) === true;
        } catch (Throwable $exception) {
            $this->failed($provider, $exception);

            return false;
        }
    }

    /**
     * The cards of one type the reader may see, batched by RENDER_BATCH; ids the provider did not answer (or answered
     * with something else) are left out.
     *
     * @param ChatCardScope $scope The reader's scope
     * @param string $type The card type
     * @param list<string> $ids The card ids
     *
     * @return array<string, ChatCard> id => card
     */
    public function render(ChatCardScope $scope, string $type, array $ids): array
    {
        $provider = $this->providers()[$type] ?? null;
        if ($provider === null || $ids === []) {
            return [];
        }
        $cards = [];
        foreach (array_chunk($ids, self::RENDER_BATCH) as $chunk) {
            try {
                $found = $provider->render($scope, $chunk);
            } catch (Throwable $exception) {
                $this->failed($provider, $exception);

                continue;
            }
            foreach ($chunk as $id) {
                // Only what was asked, matched byte for byte (numeric ids may come back as int keys).
                $card = $found[$id] ?? null;
                if ($card instanceof ChatCard) {
                    $cards[$id] = $card;
                }
            }
        }

        return $cards;
    }

    /**
     * The composer actions offered to the writer, by order then key.
     *
     * @param ChatComposerScope $scope The writer and the conversation
     * @param list<string> $reserved Keys a slash alias may not take (reference and card types)
     *
     * @return list<array{key: string, label: string, icon: string, slash: string|null, dialogUrl: string}>
     */
    public function composerActions(ChatComposerScope $scope, array $reserved = []): array
    {
        if (!$scope->canSend) {
            return [];
        }
        /** @var list<ChatComposerAction> $actions */
        $actions = [];
        $keys    = [];
        foreach ($this->actionProviders as $provider) {
            foreach ($this->collect($provider, static fn (): iterable => $provider->actions($scope), ChatComposerAction::class) as $action) {
                if (!isset($keys[$action->key])) {
                    $keys[$action->key] = true;
                    $actions[]          = $action;
                }
            }
        }
        usort($actions, static fn (ChatComposerAction $a, ChatComposerAction $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        $slashes = [];
        $view    = [];
        foreach (array_slice($actions, 0, self::MAX_ACTIONS) as $action) {
            $slash = $action->slash;
            if ($slash !== null && (in_array($slash, $reserved, true) || isset($slashes[$slash]))) {
                $this->logger?->warning('Chat composer action {key}: the slash alias collides with a reference or card type or another action; it is ignored.', ['key' => $action->key]);
                $slash = null;
            }
            if ($slash !== null) {
                $slashes[$slash] = true;
            }
            $view[] = ['key' => $action->key, 'label' => $action->label, 'icon' => $action->icon, 'slash' => $slash, 'dialogUrl' => $action->dialogUrl];
        }

        return $view;
    }

    /**
     * The message menu slots offered to the reader.
     *
     * @param ChatComposerScope $scope The reader and the conversation
     *
     * @return list<array{key: string, label: string, icon: string, target: string, cardTypes: list<string>, ownOnly: bool, dialogUrl: string}>
     */
    public function menuSlots(ChatComposerScope $scope): array
    {
        $view = [];
        $keys = [];
        foreach ($this->slotProviders as $provider) {
            foreach ($this->collect($provider, static fn (): iterable => $provider->slots($scope), ChatMenuSlot::class) as $slot) {
                if (isset($keys[$slot->key]) || count($view) >= self::MAX_SLOTS) {
                    continue;
                }
                $keys[$slot->key] = true;
                $view[]           = ['key' => $slot->key, 'label' => $slot->label, 'icon' => $slot->icon, 'target' => $slot->target->value, 'cardTypes' => $slot->cardTypes, 'ownOnly' => $slot->ownOnly, 'dialogUrl' => $slot->dialogUrl];
            }
        }

        return $view;
    }

    /**
     * What one provider yields, all of the expected class or nothing (a wrong item or an exception fails that provider
     * only).
     *
     * @template T of object
     *
     * @param callable(): iterable<mixed> $call
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function collect(object $provider, callable $call, string $class): array
    {
        $items = [];
        try {
            foreach ($call() as $item) {
                if (!$item instanceof $class) {
                    throw new UnexpectedValueException(sprintf('Expected %s, got %s.', $class, get_debug_type($item)));
                }
                $items[] = $item;
            }
        } catch (Throwable $exception) {
            $this->failed($provider, $exception);

            return [];
        }

        return $items;
    }

    /**
     * Providers by key; an invalid or repeated key fails loudly.
     *
     * @return array<string, ChatCardProviderInterface>
     */
    private function providers(): array
    {
        if ($this->byKey === null) {
            $byKey = [];
            foreach ($this->cardProviders as $provider) {
                $key = $provider->key();
                if (preg_match(ChatCardText::KEY_PATTERN, $key) !== 1 || isset($byKey[$key])) {
                    throw new LogicException(sprintf('Chat card provider %s: the key "%s" is invalid or already taken.', $provider::class, $key));
                }
                $byKey[$key] = $provider;
            }
            // @igor-ignore - built once from the container's immutable provider list, not per-request state.
            $this->byKey = $byKey;
        }

        return $this->byKey;
    }

    /**
     * Whether a tagged iterator holds a service (counted without creating them when possible).
     *
     * @param iterable<mixed> $services
     */
    private function any(iterable $services): bool
    {
        if (is_countable($services)) {
            return count($services) > 0;
        }
        foreach ($services as $service) {
            return true;
        }

        return false;
    }

    private function failed(object $provider, Throwable $exception): void
    {
        // Class names only: a provider's message could hold ids or texts.
        $this->logger?->error('Chat card or slot provider {provider} failed ({exception}).', ['provider' => $provider::class, 'exception' => $exception::class]);
    }
}
