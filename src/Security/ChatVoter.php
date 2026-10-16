<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Security;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function in_array;

/**
 * Every chat permission. Controllers ask this voter; they never decide inline.
 *
 * Conversation attributes: VIEW, SEND, MODERATE, RESOLVE. Message attributes: EDIT, DELETE, REACT.
 *
 * @extends Voter<string, ChatConversation|ChatMessage>
 */
final class ChatVoter extends Voter
{
    public const string VIEW     = 'nowo_chat.view';
    public const string SEND     = 'nowo_chat.send';
    public const string MODERATE = 'nowo_chat.moderate';
    public const string RESOLVE  = 'nowo_chat.resolve';
    public const string EDIT     = 'nowo_chat.message.edit';
    public const string DELETE   = 'nowo_chat.message.delete';
    public const string REACT    = 'nowo_chat.message.react';

    private const array CONVERSATION_ATTRIBUTES = [self::VIEW, self::SEND, self::MODERATE, self::RESOLVE];
    private const array MESSAGE_ATTRIBUTES      = [self::EDIT, self::DELETE, self::REACT];

    /**
     * Builds the voter from the access checks and a clock (edit window).
     *
     * @param ChatAccess $access Reachability, agent and channel checks
     * @param ClockInterface $clock Clock used for the edit / delete window
     */
    public function __construct(
        private readonly ChatAccess $access,
        private readonly ClockInterface $clock = new Clock(),
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return ($subject instanceof ChatConversation && in_array($attribute, self::CONVERSATION_ATTRIBUTES, true))
            || ($subject instanceof ChatMessage && in_array($attribute, self::MESSAGE_ATTRIBUTES, true));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof ChatUserInterface || !$this->access->isReachable($user)) {
            return false;
        }

        if ($subject instanceof ChatMessage) {
            return $this->voteOnMessage($attribute, $subject, $user);
        }

        return match ($attribute) {
            self::VIEW     => $this->canView($subject, $user),
            self::SEND     => $this->canSend($subject, $user),
            self::MODERATE => !$subject->isDirect() && $this->access->isSupportAgent($user) && $this->canView($subject, $user),
            default        => $subject->isSupport() && $this->access->isSupportAgent($user), // RESOLVE
        };
    }

    private function voteOnMessage(string $attribute, ChatMessage $message, ChatUserInterface $user): bool
    {
        $conversation = $message->getConversation();
        if ($message->isDeleted()) {
            return false;
        }
        $ownInWindow = $message->isAuthoredBy($user) && $message->isWithinEditWindow($this->clock->now());

        return match ($attribute) {
            self::EDIT => $ownInWindow && !$message->isBuzz() && $this->canSend($conversation, $user),
            // Only the author, within the edit window: nobody deletes someone else's message.
            self::DELETE => $ownInWindow && $this->canView($conversation, $user),
            default      => !$message->isBuzz() && $this->canSend($conversation, $user), // REACT
        };
    }

    private function canView(ChatConversation $conversation, ChatUserInterface $user): bool
    {
        return $this->access->canView($conversation, $user);
    }

    private function canSend(ChatConversation $conversation, ChatUserInterface $user): bool
    {
        if ($conversation->isDirect()) {
            $peer = $this->access->directPeer($conversation, $user);

            return $peer instanceof ChatUserInterface && $this->access->canWriteDirect($user, $peer);
        }

        return $this->canView($conversation, $user);
    }
}
