<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Closure;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatForwardPolicyInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Event\ChatMessageForwardedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageForwardingEvent;
use Nowo\ChatKitBundle\Model\ChatForwardRequest;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function preg_replace;
use function trim;

/**
 * 1.36 (Beacon, D-20261118-02): "Forward" (`message_menu.forward`) — copies a text message to up to `max_targets`
 * conversations (or new direct conversations), all or nothing: every target is checked first (SEND, tickets only for
 * agents, ChatMessageForwardingEvent, ChatForwardPolicyInterface) and one refusal refuses the whole forward. The copy
 * mentions nobody (mention tokens become plain names, `@channel` / `@here` plain words), keeps its #reference tokens
 * (each reader sees what they may open) and remembers its source, which a reader sees named only when they may read it.
 * One unit of work for every target, under an `Idempotency-Key`. Access to the source (ChatVoter::FORWARD) is the
 * caller's job. Stateless.
 */
final readonly class ChatForwarder
{
    /**
     * @param ChatForwardPolicyInterface $policy The application's veto (the default allows every forward)
     * @param EventDispatcherInterface|null $events ChatMessageForwardingEvent (before) and ChatMessageForwardedEvent (after the commit)
     */
    public function __construct(
        private ChatService $chat,
        private ChatMenuOptions $options,
        private ChatAccess $access,
        private ChatVoter $voter,
        private ChatConversationRepository $conversations,
        private ChatDirectoryInterface $directory,
        private ChatIdempotency $idempotency,
        private ChatForwardPolicyInterface $policy,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * Whether "Forward" is offered.
     */
    public function enabled(): bool
    {
        return $this->options->forwardEnabled();
    }

    /**
     * Whether the user may forward into a conversation: SEND there; into a ticket, support agents only.
     *
     * @param ChatUserInterface $user The user
     * @param ChatConversation $target The conversation
     */
    public function mayForwardInto(ChatUserInterface $user, ChatConversation $target): bool
    {
        return $this->voter->decide(ChatVoter::VIEW, $target, $user) && $this->voter->decide(ChatVoter::SEND, $target, $user)
            && (!$target->isSupport() || $this->access->isSupportAgent($user));
    }

    /**
     * Whether the user may start a direct conversation with someone to forward to them.
     *
     * @param ChatUserInterface $user The user
     * @param ChatUserInterface $other The other person
     */
    public function mayForwardTo(ChatUserInterface $user, ChatUserInterface $other): bool
    {
        return $this->access->canOpenDirect($user, $other) && $this->access->canWriteDirect($user, $other);
    }

    /**
     * Forwards the message.
     *
     * @param ChatUserInterface $actor Who forwards (ChatVoter::FORWARD on the source)
     * @param ChatMessage $source The message
     * @param list<mixed> $targets `{conversationUuid}` or `{contactUuid}` each
     * @param string|null $comment Posted first in each target as the actor's own message
     * @param string $key The `Idempotency-Key`
     *
     * @throws InvalidArgumentException `invalid_targets` (none, too many, malformed), `not_found` (an unknown target),
     *                                  `empty`, `too_long`, `invalid_link`, `idempotency_mismatch`
     * @throws ChatForwardRefused When a target is refused (nothing is written)
     *
     * @return array{list<ChatMessage>, bool} The forwarded copies (one per target, in order), and whether an earlier
     *                                        request with the key created them
     */
    public function forward(ChatUserInterface $actor, ChatMessage $source, array $targets, ?string $comment, string $key): array
    {
        if ($targets === [] || count($targets) > $this->options->maxForwardTargets()) {
            throw new InvalidArgumentException('invalid_targets');
        }
        $comment = $comment === null || trim($comment) === '' ? null : $comment;
        // Each target: an open conversation, or a person (a direct conversation opened once every check passed).
        $resolved = [];
        $keys     = [];
        foreach (array_values($targets) as $index => $target) {
            [$conversation, $person] = $this->resolve($actor, $target, $index);
            $id                      = $conversation instanceof ChatConversation ? 'c' . $conversation->getUuid() : 'u' . $person?->getChatPublicId();
            if (isset($keys[$id])) {
                continue;
            }
            $keys[$id] = true;
            $this->veto($actor, $source, $conversation, $person, $index);
            $resolved[] = [$conversation, $person];
        }
        // The request as sent (a person whose direct conversation the first forward opened is still the same request).
        $hash = ChatIdempotency::hash(['forward', $source->getUuid(), array_values($targets), $comment]);

        // @igor-ignore - The closure runs once, now, inside run(), and is kept nowhere (stateless, per call).
        [$messages, $replayed] = $this->idempotency->run($actor, $key, $hash, function (Closure $before, Closure $after) use ($actor, $source, $resolved, $comment): array {
            $drafts = [];
            $body   = self::plainText($source->getBody());
            foreach ($resolved as [$conversation, $person]) {
                $conversation ??= $this->chat->openDirect($actor, $person ?? throw new InvalidArgumentException('not_found'));
                if ($comment !== null) {
                    $drafts[] = new ChatDraft($conversation, $comment);
                }
                $drafts[] = new ChatDraft($conversation, $body, null, $source);
            }

            return $this->chat->post($actor, $drafts, $before, $after);
        });
        $copies = array_values(array_filter($messages, static fn (ChatMessage $message): bool => $message->isForwarded()));
        if (!$replayed) {
            foreach ($copies as $copy) {
                $this->events?->dispatch(new ChatMessageForwardedEvent($source, $copy, $actor));
            }
        }

        return [$copies, $replayed];
    }

    /**
     * The text of a forward: mention tokens as plain names, so nobody is mentioned again (`@channel` and `@here` stay
     * words: a forward never mentions a group).
     *
     * @param string $body The source's Markdown
     */
    public static function plainText(string $body): string
    {
        return (string) preg_replace(ChatMentions::TOKEN, '@$1', $body);
    }

    /**
     * @return array{ChatConversation|null, ChatUserInterface|null}
     */
    private function resolve(ChatUserInterface $actor, mixed $target, int $index): array
    {
        $conversationUuid = is_array($target) ? ($target['conversationUuid'] ?? null) : null;
        $contactUuid      = is_array($target) ? ($target['contactUuid'] ?? null) : null;
        if (is_string($conversationUuid) && $contactUuid === null) {
            $conversation = $this->conversations->findOneByUuid($conversationUuid);
            if (!$conversation instanceof ChatConversation || $conversation->getUuid() !== $conversationUuid || !$this->voter->decide(ChatVoter::VIEW, $conversation, $actor)) {
                throw new InvalidArgumentException('not_found');
            }
            if (!$this->mayForwardInto($actor, $conversation)) {
                throw new ChatForwardRefused($index);
            }

            return [$conversation, null];
        }
        if (is_string($contactUuid) && $conversationUuid === null) {
            $other = $this->directory->findByPublicId($contactUuid);
            if (!$other instanceof ChatUserInterface || $other->getChatPublicId() !== $contactUuid) {
                throw new InvalidArgumentException('not_found');
            }
            if (!$this->mayForwardTo($actor, $other)) {
                throw new ChatForwardRefused($index);
            }
            $direct = $this->conversations->findDirect($actor, $other);

            return [$direct, $direct instanceof ChatConversation ? null : $other];
        }

        throw new InvalidArgumentException('invalid_targets');
    }

    private function veto(ChatUserInterface $actor, ChatMessage $source, ?ChatConversation $target, ?ChatUserInterface $person, int $index): void
    {
        $event = new ChatMessageForwardingEvent($source, $actor, $target, $person);
        $this->events?->dispatch($event);
        if ($event->isDenied()) {
            throw new ChatForwardRefused($index, $event->reason());
        }
        $from = $source->getConversation();
        if (!$this->policy->mayForward(new ChatForwardRequest(
            $actor->getChatPublicId(),
            $source->getUuid(),
            $from->getUuid(),
            $from->getKind()->value,
            $from->getChannelKey(),
            $target?->getUuid(),
            $target instanceof ChatConversation ? $target->getKind()->value : 'direct',
            $target?->getChannelKey(),
            $target instanceof ChatConversation ? $this->access->directPeer($target, $actor)?->getChatPublicId() : $person?->getChatPublicId(),
        ))) {
            throw new ChatForwardRefused($index);
        }
    }
}
