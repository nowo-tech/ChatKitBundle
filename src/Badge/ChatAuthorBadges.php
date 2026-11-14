<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Badge;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatAuthorBadgeProviderInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\UnresolvedChatUser;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatAuthorRef;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ErasedChatUser;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_key_exists;
use function array_values;
use function is_array;

/**
 * 1.29 (Beacon spec 234): the author badges of one request. {@see self::prime()} asks the host's provider once for the
 * authors of the messages a response shows ({@see ChatAuthorBadgeProviderInterface::badgesForMany()});
 * {@see self::of()} answers from the memo (a message that was not primed costs one call for its author). Anything but a
 * {@see ChatAuthorBadge} in an answer is dropped, keys that were not asked are ignored, a failing provider gives no badge
 * and a warning. With the default provider ({@see NoChatAuthorBadges}) nothing is asked or kept.
 *
 * Keyed by the exact stored user key (byte for byte): a badge never moves to a look-alike (`Bob` / `bob`). A message
 * whose loaded author does not carry the key stored on the row gets no badge. Bot and system lines, gone accounts
 * ({@see UnresolvedChatUser}) and erased authors have none.
 *
 * Emptied at every request, command and message boundary ({@see ChatRequestBoundaryListener}) and by `kernel.reset`:
 * no badge of one request is ever shown in another (FrankenPHP worker mode, kernel reset off).
 *
 * @internal
 */
final class ChatAuthorBadges implements ResetInterface
{
    /** @var array<string, list<ChatAuthorBadge>> user key => badges */
    private array $badges = [];

    /**
     * Builds the memo on the host provider.
     *
     * @param ChatAuthorBadgeProviderInterface $provider The application's provider (default: NoChatAuthorBadges)
     * @param LoggerInterface $logger Warning when the provider fails (the exception class only)
     */
    public function __construct(
        private readonly ChatAuthorBadgeProviderInterface $provider,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Whether the application gives badges (its own provider, not the default).
     *
     * @return bool False with the default provider
     */
    public function enabled(): bool
    {
        return !$this->provider instanceof NoChatAuthorBadges;
    }

    /**
     * Resolves every not-yet-known author of the messages in one badgesForMany() call (none when all are known).
     *
     * @param iterable<ChatMessage> $messages The messages of the response
     */
    public function prime(iterable $messages): void
    {
        if (!$this->enabled()) {
            return;
        }
        /** @var array<string, ChatAuthorRef> $missing */
        $missing = [];
        foreach ($messages as $message) {
            $ref = $this->refOf($message);
            if ($ref instanceof ChatAuthorRef && !array_key_exists($ref->reference, $this->badges)) {
                $missing[$ref->reference] = $ref;
            }
        }
        if ($missing === []) {
            return;
        }
        try {
            $answer = $this->provider->badgesForMany(array_values($missing));
        } catch (Throwable $exception) {
            $this->logger->warning('Chat author badge provider failed: {error}', ['error' => $exception::class]);
            $answer = [];
        }
        foreach ($missing as $ref) {
            $found = $answer[$ref->reference] ?? [];
            $list  = [];
            foreach (is_array($found) ? $found : [] as $badge) {
                if ($badge instanceof ChatAuthorBadge) {
                    $list[] = $badge;
                }
            }
            // @igor-ignore Request memo, emptied by reset() at every boundary (worker mode).
            $this->badges[$ref->reference] = $list;
        }
    }

    /**
     * The badges of a message's author.
     *
     * @param ChatMessage $message The message
     *
     * @return list<ChatAuthorBadge> The badges; empty for none
     */
    public function of(ChatMessage $message): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $ref = $this->refOf($message);
        if (!$ref instanceof ChatAuthorRef) {
            return [];
        }
        if (!array_key_exists($ref->reference, $this->badges)) {
            $this->prime([$message]);
        }

        return $this->badges[$ref->reference] ?? [];
    }

    /**
     * Forgets the badges of the finished request (worker mode).
     */
    public function reset(): void
    {
        // @igor-ignore Request memo, emptied between the requests of a worker.
        $this->badges = [];
    }

    /**
     * The author the provider is asked about; null for no badge (bot / system lines, no author, a gone or erased
     * account, a loaded author whose key is not the one stored on the row, a key the port cannot carry).
     */
    private function refOf(ChatMessage $message): ?ChatAuthorRef
    {
        $author = $message->getAuthor();
        if ($message->isBot() || $message->isSystem() || !$author instanceof ChatUserInterface || $author instanceof UnresolvedChatUser || $author instanceof ErasedChatUser) {
            return null;
        }
        try {
            $key = ChatUserReferences::key($author);
            if ($key === null || $key !== $message->getAuthorReference()) {
                return null;
            }

            return new ChatAuthorRef($key, $author->getChatPublicId());
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
