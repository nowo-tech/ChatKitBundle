<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Card;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatCardUpdatesInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatCardText;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function preg_match;

/**
 * 2.8 (Beacon spec 260): default ChatCardUpdatesInterface (aliased to it). Finds the messages of one conversation that
 * hold the card (ChatMessageRepository::findCardHolders(), then the exact token outside code) and sends each the
 * `message_updated` event through ChatNotifier, with its reactions (so a `full` client keeps them). Reads only: no
 * transaction, no lock. Stateless (FrankenPHP worker mode).
 */
final readonly class ChatCardUpdates implements ChatCardUpdatesInterface
{
    /** LIKE candidates read per call (bounded), cut to MAX_MESSAGES after the exact-token filter (audit ZCW-I1). */
    public const int CANDIDATES = self::MAX_MESSAGES * 4;

    /** Error code of a malformed type or id. */
    public const string INVALID_CARD = 'invalid_card';

    /**
     * @param ChatConversationRepository $conversations Conversations by uuid
     * @param ChatMessageRepository $messages The messages that hold a card
     * @param ChatReferences $references Card types and the exact tokens of a body
     * @param ChatNotifier $notifier Sends `message_updated`
     * @param ChatReactionRepository $reactions Reactions of the signalled messages (the `full` payload)
     * @param LoggerInterface|null $logger Where a failure is reported (class name only)
     */
    public function __construct(
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatReferences $references,
        private ChatNotifier $notifier,
        private ChatReactionRepository $reactions,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Sends `message_updated` for the messages of the conversation that hold the card (see the interface).
     *
     * @param string $type The card type key
     * @param string $id The opaque card id, matched byte for byte
     * @param string $conversationUuid Public uuid of the conversation, matched byte for byte
     *
     * @throws InvalidArgumentException `invalid_card` when the type or the id does not have the card token's shape
     *
     * @return int How many messages were signalled (0 to MAX_MESSAGES)
     */
    public function changed(string $type, string $id, string $conversationUuid): int
    {
        if (preg_match(ChatCardText::KEY_PATTERN, $type) !== 1 || preg_match(ChatCardText::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(self::INVALID_CARD);
        }
        if (!$this->references->isCardType($type)) {
            return 0;
        }
        try {
            $conversation = $this->conversations->findOneByUuid($conversationUuid);
            // REQ-SEC-006: byte for byte (a case-insensitive collation must not match another conversation).
            if (!$conversation instanceof ChatConversation || $conversation->getUuid() !== $conversationUuid) {
                return 0;
            }
            $card    = ['type' => $type, 'id' => $id];
            $holders = array_values(array_filter(
                $this->messages->findCardHolders($conversation, $type, $id, self::SCAN_WINDOW, self::CANDIDATES),
                fn (ChatMessage $message): bool => in_array($card, $this->references->cardTokens($message->getBody()), true),
            ));
            // Audit ZCW-I1: cut after the exact-token filter, so newer decoys (a token in code, another letter case on a
            // case-insensitive collation) cannot push the real holders out of the 20.
            $holders = array_slice($holders, 0, self::MAX_MESSAGES);
            if ($holders === []) {
                return 0;
            }
            $reactions = $this->reactions->summaryFor(array_map(static fn (ChatMessage $message): int => (int) $message->getId(), $holders));
            foreach ($holders as $message) {
                $this->notifier->messageUpdated($message, $reactions[(int) $message->getId()] ?? []);
            }

            return count($holders);
        } catch (Throwable $exception) {
            // Class names only: never the card or conversation ids.
            $this->logger?->error('Chat card update hint failed ({exception}).', ['exception' => $exception::class]);

            return 0;
        }
    }
}
