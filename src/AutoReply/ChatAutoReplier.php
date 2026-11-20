<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\AutoReply;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

/**
 * Automatic replies (1.11): after a message, the other person of a direct conversation — and, in channels, the
 * people it @mentions who chose so — answer with their automatic reply when it applies (away / busy / do not disturb,
 * or their out-of-office range), at most once per conversation per interval. Never in support tickets, never to an
 * automatic or assistant message (no loops), never on behalf of an unreachable account. The reply is a message of its
 * author (kind `auto`), published live without Web Push, e-mail digest or sound.
 *
 * 1.30 (Beacon spec 239): each reply is one unit of work run through TransactionRunnerInterface after the message's own
 * commit (the conversation row locked first, as for every new line), published once it committed.
 */
final readonly class ChatAutoReplier
{
    private TransactionRunnerInterface $transactions;

    /**
     * Builds the replier.
     *
     * @param int $intervalHours At most one automatic reply per conversation and person in this many hours
     * @param ClockInterface $clock Clock
     * @param ChatConversationRepository|null $conversations 1.30: locks the conversation row first; null skips the lock
     * @param TransactionRunnerInterface|null $transactions 1.30: runs each reply; null uses ChatKit's default runner
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
        private ?ChatConversationRepository $conversations = null,
        ?TransactionRunnerInterface $transactions = null,
    ) {
        $this->transactions = $transactions ?? new ChatKitTransactionRunner($entityManager);
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
            if (ChatUserReferences::same($candidate, $author) || !$this->access->isReachable($candidate)) {
                continue;
            }
            $shown = $this->presence->statusOf([$candidate])[$candidate->getChatPublicId()]['status'] ?? 'offline';
            $text  = $this->settings->forUser($candidate)->autoReplyFor($now, $shown, !$conversation->isDirect());
            if ($text === null) {
                continue;
            }
            $participant = $this->participants->findOneFor($conversation, $candidate);
            if (!$participant instanceof ChatParticipant) {
                try {
                    $this->participants->assertInsertable($candidate);
                } catch (ChatUserLookAlikeException) {
                    // 1.28: a folding user column holds a look-alike's row, so this insert would clash and close the
                    // entity manager. This runs after ChatService::send() committed the message: skip the automatic
                    // reply (the guard already warned) instead of answering 500 to whoever wrote it. Only this refusal
                    // is swallowed (audit PV-I1); any other failure still surfaces.
                    continue;
                }
            }
            $last = $participant?->getLastAutoReplyAt();
            if ($last instanceof DateTimeImmutable && $last > $since) {
                continue;
            }
            // 1.30 (audit QD-I2): rendered before the unit of work, not under the conversation's row lock.
            $html  = $this->markdown->render($text);
            $reply = ChatKitWrites::runRecovering($this->transactions, $this->entityManager, fn (): ChatMessage => $this->reply($conversation, $candidate, $text, $html, $now));
            $this->notifier->botMessage($reply);
            $sent[] = $reply;
        }

        return $sent;
    }

    /**
     * One automatic reply, inside its unit of work: the conversation row locked first, the replier's participant row
     * (claimed: written now when there is none), the reply and the replier's read cursor on it.
     */
    private function reply(ChatConversation $conversation, ChatUserInterface $candidate, string $text, string $html, DateTimeImmutable $now): ChatMessage
    {
        $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
        $stored       = $this->conversations?->lockActivity($conversation);
        $participant  = $this->participants->claim($conversation, $candidate, $now);
        $reply        = ChatMessage::autoReply($conversation, $candidate, $text, $html, $now);
        $this->entityManager->persist($reply);
        $participant->markAutoReplied($now);
        if (!$stored instanceof DateTimeImmutable || $now > $stored) {
            $conversation->touch($now);
        }
        ChatKitWrites::beforeFlush($this->transactions, $this->entityManager);
        $this->entityManager->flush();
        // The replier has read up to their own automatic reply.
        $participant->markReadUpTo((int) $reply->getId());

        return $reply;
    }
}
