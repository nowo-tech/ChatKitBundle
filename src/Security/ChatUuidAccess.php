<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Security;

use Closure;
use Nowo\ChatKitBundle\Contract\ChatAccessInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_slice;
use function array_unique;
use function array_values;

/**
 * 2.2: default ChatAccessInterface (aliased to it): loads the conversation by its public uuid through ChatKit's own
 * entity manager, compared in SQL and again byte for byte in PHP (house rule LQ), resolves the user (ChatActorResolver)
 * and answers with the JSON API's rules (ChatVoter::decide(), ChatAccess). Only booleans and uuids leave it; the loaded
 * rows stay in ChatKit's manager. Stateless (FrankenPHP worker mode, kernel reset false).
 */
final readonly class ChatUuidAccess implements ChatAccessInterface
{
    /** Most uuids viewableConversationUuids() checks in one call. */
    public const int MAX_BATCH = 500;

    /**
     * @param ChatConversationRepository $conversations Conversations by uuid (ChatKit's manager)
     * @param ChatVoter $voter The API's rules
     * @param ChatAccess $access Pins and links
     * @param ChatActorResolver $actors Users by public id
     * @param bool $linkChannelMembers `links.channel_managers: members` (every channel member manages the links)
     * @param LoggerInterface|null $logger Receives the class of a failure answered as a refusal
     */
    public function __construct(
        private ChatConversationRepository $conversations,
        private ChatVoter $voter,
        private ChatAccess $access,
        private ChatActorResolver $actors,
        private bool $linkChannelMembers = true,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function canView(ChatUserInterface|string $user, string $conversationUuid): bool
    {
        return $this->check($user, $conversationUuid, fn (ChatConversation $conversation, ChatUserInterface $actor): bool => $this->voter->decide(ChatVoter::VIEW, $conversation, $actor));
    }

    /**
     * {@inheritDoc}
     */
    public function canPost(ChatUserInterface|string $user, string $conversationUuid): bool
    {
        return $this->check($user, $conversationUuid, fn (ChatConversation $conversation, ChatUserInterface $actor): bool => $this->voter->decide(ChatVoter::SEND, $conversation, $actor));
    }

    /**
     * {@inheritDoc}
     */
    public function canPin(ChatUserInterface|string $user, string $conversationUuid): bool
    {
        return $this->check($user, $conversationUuid, fn (ChatConversation $conversation, ChatUserInterface $actor): bool => $this->access->isReachable($actor) && $this->access->canPin($conversation, $actor));
    }

    /**
     * {@inheritDoc}
     */
    public function canManageLinks(ChatUserInterface|string $user, string $conversationUuid): bool
    {
        // The Links panel's rule (ChatLinksController::mayManage()): write access, then the managers' rule.
        return $this->check($user, $conversationUuid, fn (ChatConversation $conversation, ChatUserInterface $actor): bool => $this->voter->decide(ChatVoter::SEND, $conversation, $actor)
            && $this->access->canManageLinks($conversation, $actor, $this->linkChannelMembers));
    }

    /**
     * {@inheritDoc}
     */
    public function canViewTicket(ChatUserInterface|string $user, string $ticketUuid): bool
    {
        return $this->check($user, $ticketUuid, fn (ChatConversation $conversation, ChatUserInterface $actor): bool => $conversation->isTicket() && $this->voter->decide(ChatVoter::VIEW, $conversation, $actor));
    }

    /**
     * {@inheritDoc}
     */
    public function canHandleTicket(ChatUserInterface|string $user, string $ticketUuid): bool
    {
        return $this->check($user, $ticketUuid, fn (ChatConversation $conversation, ChatUserInterface $actor): bool => $conversation->isTicket() && $this->voter->decide(ChatVoter::RESOLVE, $conversation, $actor)
            && $this->voter->decide(ChatVoter::VIEW, $conversation, $actor));
    }

    /**
     * {@inheritDoc}
     */
    public function viewableConversationUuids(ChatUserInterface|string $user, array $conversationUuids): array
    {
        $asked = array_slice(array_values(array_unique($conversationUuids)), 0, self::MAX_BATCH);
        if ($asked === []) {
            return [];
        }
        try {
            $actor = $this->actors->resolve($user);
            if (!$actor instanceof ChatUserInterface) {
                return [];
            }
            $found = $this->conversations->findByUuids($asked);
            $kept  = [];
            foreach ($asked as $uuid) {
                $conversation = $found[$uuid] ?? null;
                // The SQL IN may match a look-alike on a folding collation: only a row whose uuid is exactly the one asked.
                if ($conversation instanceof ChatConversation && $conversation->getUuid() === $uuid
                    && $this->voter->decide(ChatVoter::VIEW, $conversation, $actor)) {
                    $kept[] = $uuid;
                }
            }

            return $kept;
        } catch (Throwable $failure) {
            $this->refused($failure);

            return [];
        }
    }

    /**
     * Runs a rule on the conversation of exactly this uuid and the resolved user; unknown, look-alike, unusable and
     * failing are the same `false`.
     *
     * @param Closure(ChatConversation, ChatUserInterface): bool $rule
     */
    private function check(ChatUserInterface|string $user, string $uuid, Closure $rule): bool
    {
        try {
            $actor = $this->actors->resolve($user);
            if (!$actor instanceof ChatUserInterface || $uuid === '') {
                return false;
            }
            $conversation = $this->conversations->findOneByUuid($uuid);

            return $conversation instanceof ChatConversation && $conversation->getUuid() === $uuid && $rule($conversation, $actor);
        } catch (Throwable $failure) {
            $this->refused($failure);

            return false;
        }
    }

    private function refused(Throwable $failure): void
    {
        // The class only: a message could carry a uuid, a key or a reference.
        $this->logger?->warning('ChatKit access check failed and was answered as a refusal ({class}).', ['class' => $failure::class]);
    }
}
