<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Closure;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatIdempotencyKey;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatIdempotencyKeyRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use function array_filter;
use function array_map;
use function array_values;
use function hash;
use function in_array;
use function is_string;
use function json_encode;
use function preg_match;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * 1.36 (REQ-RUNTIME-003 rule 7): `Idempotency-Key` for writes a client may send again after a lost answer (forward,
 * thread reply; optional on send). The key is claimed in the write's own unit of work, before anything else: a second
 * request with the same key waits for the first one and, once it committed, writes nothing and answers what the first
 * one created; the same key with another request is refused (`idempotency_mismatch`). Keys are kept a day
 * (`nowo:chat:purge`). Stateless.
 */
final readonly class ChatIdempotency
{
    /** The request header. */
    public const string HEADER = 'Idempotency-Key';

    /** Hours a key is kept. */
    public const int TTL_HOURS = 24;

    /**
     * @param ChatIdempotencyKeyRepository $keys The keys
     * @param ChatMessageRepository $messages What a replay answers
     * @param ClockInterface $clock Claim times
     */
    public function __construct(
        private ChatIdempotencyKeyRepository $keys,
        private ChatMessageRepository $messages,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * Whether a header value is a usable key: 1 to 80 characters among letters, digits and `_ . : -`.
     *
     * @param mixed $key The header value
     */
    public static function isValidKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[A-Za-z0-9_.:-]{1,' . ChatIdempotencyKey::MAX_KEY_LENGTH . '}$/', $key) === 1;
    }

    /**
     * The hash of a request (what the key promises not to repeat).
     *
     * @param array<array-key, mixed> $request Route, ids and body of the request
     */
    public static function hash(array $request): string
    {
        return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
    }

    /**
     * Runs a write under a key. `$write` gets the claim (pass it as the unit's first statement) and the completion (its
     * last statement, with the new messages) and returns them.
     *
     * @param ChatUserInterface $user The user who sent the key
     * @param string $key A valid key ({@see self::isValidKey()})
     * @param string $requestHash {@see self::hash()} of the request
     * @param Closure(Closure(): bool, Closure(list<ChatMessage>): void): list<ChatMessage> $write The write
     *
     * @throws InvalidArgumentException `idempotency_mismatch` when the key was used for another request
     *
     * @return array{list<ChatMessage>, bool} The messages, and whether they were created by an earlier request
     */
    public function run(ChatUserInterface $user, string $key, string $requestHash, Closure $write): array
    {
        $replay = $this->replay($user, $key, $requestHash);
        if ($replay !== null) {
            return [$replay, true];
        }
        try {
            return [$write(
                fn (): bool => $this->keys->claim($user, $key, $requestHash, $this->clock->now()),
                fn (array $messages) => $this->keys->complete($user, $key, array_values(array_map(static fn (ChatMessage $message): string => $message->getUuid(), $messages))),
            ), false];
        } catch (ChatIdempotencyConflict $conflict) {
            // The other request committed: its answer (read now, after this unit was rolled back).
            return [$this->replay($user, $key, $requestHash, true) ?? throw $conflict, true];
        }
    }

    /**
     * What an earlier request with the key created.
     *
     * @throws InvalidArgumentException `idempotency_mismatch` when the key was used for another request
     *
     * @return list<ChatMessage>|null The messages that still exist (in the order created); null when the key is new
     */
    private function replay(ChatUserInterface $user, string $key, string $requestHash, bool $lock = false): ?array
    {
        $row = $this->keys->findOneFor($user, $key, $lock);
        if (!$row instanceof ChatIdempotencyKey) {
            return null;
        }
        if ($row->getRequestHash() !== $requestHash) {
            throw new InvalidArgumentException('idempotency_mismatch');
        }
        $uuids = $row->getResult() ?? [];
        if ($uuids === []) {
            return [];
        }
        /** @var list<ChatMessage> $found */
        $found = $this->messages->findBy(['uuid' => $uuids]);
        $found = array_values(array_filter($found, static fn (ChatMessage $message): bool => in_array($message->getUuid(), $uuids, true)));
        usort($found, static fn (ChatMessage $a, ChatMessage $b): int => $a->getId() <=> $b->getId());

        return $found;
    }
}
