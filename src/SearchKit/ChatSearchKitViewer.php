<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\SearchKit;

use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.38: the viewer of a SearchKit search, for both chat groups (ChatMessageSearchDefinition and
 * ChatConversationSearchDefinition): the chat participant of the signed-in user, and only when that user is the one the
 * search runs for (a search made by identifier for someone else gets nothing: ChatKit cannot map an identifier to a
 * participant without the session).
 *
 * ChatKit has no tenants: what a viewer may read is decided per person (direct conversations, own tickets, channels
 * of the channel provider). SearchKit's tenant is therefore the viewer's own participant key
 * (ChatUserReferences::key()), and every hook resolves the viewer again from the request-scoped security token and checks
 * that key: nothing about a viewer is kept in a service (FrankenPHP worker mode, kernel reset off).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatSearchKitViewer
{
    /**
     * @param Security $security The signed-in user
     * @param ChatAccess $access Reachability
     * @param CurrentChatUserResolverInterface|null $currentUser The participant of the signed-in user (null: the security user itself)
     */
    public function __construct(
        private Security $security,
        private ChatAccess $access,
        private ?CurrentChatUserResolverInterface $currentUser = null,
    ) {
    }

    /**
     * The SearchKit tenant of a search: the participant key of the signed-in user, when that user is the one the search
     * runs for and may use the chat; null otherwise (no results).
     *
     * @param string $userIdentifier The search's user identifier
     *
     * @return string|null The viewer's key
     */
    public function tenant(string $userIdentifier): ?string
    {
        $viewer = $this->viewer($userIdentifier);

        return $viewer instanceof ChatUserInterface ? ChatUserReferences::key($viewer) : null;
    }

    /**
     * The viewer of a tenant tenant() gave in this search: resolved again, and null unless it is still the same
     * participant and still reachable.
     *
     * @param int|string $tenant The tenant tenant() returned
     * @param string $userIdentifier The search's user identifier
     *
     * @return ChatUserInterface|null The viewer
     */
    public function forTenant(int|string $tenant, string $userIdentifier): ?ChatUserInterface
    {
        $viewer = $this->viewer($userIdentifier);

        return $viewer instanceof ChatUserInterface && ChatUserReferences::key($viewer) === (string) $tenant ? $viewer : null;
    }

    /**
     * The participant of the signed-in user, only when it is the user the search runs for and may use the chat.
     *
     * @param string $userIdentifier The search's user identifier
     *
     * @return ChatUserInterface|null The viewer
     */
    public function viewer(string $userIdentifier): ?ChatUserInterface
    {
        $user = $this->security->getUser();
        if (!$user instanceof UserInterface || $user->getUserIdentifier() !== $userIdentifier) {
            return null;
        }
        $viewer = $this->currentUser instanceof CurrentChatUserResolverInterface ? $this->currentUser->resolve($user) : $user;

        return $viewer instanceof ChatUserInterface && $this->access->isReachable($viewer) ? $viewer : null;
    }
}
