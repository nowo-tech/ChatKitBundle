<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\AutoReply;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

/**
 * Automatic replies (1.11): after a message, the other person of a direct conversation — and, in channels, the
 * people it @mentions who chose so — answer with their automatic reply when it applies (away / busy / do not disturb,
 * or their out-of-office range), at most once per conversation per interval. Never in support tickets, never to an
 * automatic or assistant message (no loops), never on behalf of an unreachable account. The reply is a message of its
 * author (kind `auto`), published live without Web Push, e-mail digest or sound.
 */
final readonly class ChatAutoReplier
{
    /**
     * Builds the replier.
     *
     * @param int $intervalHours At most one automatic reply per conversation and person in this many hours
     * @param ClockInterface $clock Clock
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatParticipantRepository $participants,
        private ChatUserSettingsRepository $settings,
        private ChatPresence $presence,
        private ChatAccess $access,
        private ChatMarkdownRenderer $markdown,
        private ChatNotifier $notifier,
        private int $intervalHours = 4,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * Sends the automatic replies a new message calls for.
     *
     * @param ChatMessage $message The message just sent
     * @param list<ChatUserInterface> $mentioned The people it @mentions (channels)
     *
     * @return list<ChatMessage> The automatic replies sent
     */
    public function afterSend(ChatMessage $message, array $mentioned = []): array
    {
        $conversation = $message->getConversation();
        $author       = $message->getAuthor();
        if (!$author instanceof ChatUserInterface || !$message->isPinnable() || $conversation->isSupport()) {
            return [];
        }
        $peer       = $conversation->isDirect() ? $this->access->directPeer($conversation, $author) : null;
        $candidates = $peer instanceof ChatUserInterface ? [$peer] : ($conversation->isChannel() ? $mentioned : []);
        $now        = $this->clock->now();
        $since      = $now->sub(new DateInterval('PT' . $this->intervalHours . 'H'));
        $sent       = [];
        foreach ($candidates as $candidate) {
            if ($candidate->getId() === $author->getId() || !$this->access->isReachable($candidate)) {
                continue;
            }
            $shown = $this->presence->statusOf([$candidate])[$candidate->getChatPublicId()]['status'] ?? 'offline';
            $text  = $this->settings->forUser($candidate)->autoReplyFor($now, $shown, !$conversation->isDirect());
            if ($text === null) {
                continue;
            }
            $participant = $this->participants->findOneBy(['conversation' => $conversation, 'user' => $candidate]);
            if (!$participant instanceof ChatParticipant) {
                $participant = new ChatParticipant($conversation, $candidate, $now);
                $this->entityManager->persist($participant);
            }
            $last = $participant->getLastAutoReplyAt();
            if ($last instanceof DateTimeImmutable && $last > $since) {
                continue;
            }
            $reply = ChatMessage::autoReply($conversation, $candidate, $text, $this->markdown->render($text), $now);
            $this->entityManager->persist($reply);
            $participant->markAutoReplied($now);
            $conversation->touch($now);
            $this->entityManager->flush();
            // The replier has read up to their own automatic reply.
            $participant->markReadUpTo((int) $reply->getId());
            $this->entityManager->flush();
            $this->notifier->botMessage($reply);
            $sent[] = $reply;
        }

        return $sent;
    }
}
