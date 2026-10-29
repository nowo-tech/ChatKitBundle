<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function sprintf;

/**
 * Ticket rules from `support.*`: the readable number (SUP-000123), when the user is asked "continue or new?"
 * (`resume_after_days` without messages, or resolved) and when a ticket is read-only (closed, or resolved more than
 * `close_after_days` ago).
 */
final readonly class ChatSupportPolicy
{
    /**
     * Builds the policy from the support settings.
     *
     * @param string $prefix Prefix of the readable number
     * @param int $digits Digits of the readable number
     * @param int $resumeAfterDays Idle days after which the user is asked "continue or new?"
     * @param int $closeAfterDays Days after which a resolved ticket is read-only (0: never)
     * @param ClockInterface $clock The clock
     */
    public function __construct(
        #[Autowire(param: 'nowo_chat_kit.support.ticket_prefix')]
        private string $prefix = 'SUP-',
        #[Autowire(param: 'nowo_chat_kit.support.ticket_digits')]
        private int $digits = 6,
        #[Autowire(param: 'nowo_chat_kit.support.resume_after_days')]
        private int $resumeAfterDays = 7,
        #[Autowire(param: 'nowo_chat_kit.support.close_after_days')]
        private int $closeAfterDays = 30,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * Readable ticket number.
     *
     * @param int|null $number The sequential number
     *
     * @return string|null `SUP-000123`; null for null
     */
    public function format(?int $number): ?string
    {
        return $number === null ? null : $this->prefix . sprintf('%0' . $this->digits . 'd', $number);
    }

    /**
     * Whether the ticket is read-only: closed, or resolved before the read-only cutoff.
     *
     * @param ChatConversation $ticket The support conversation
     *
     * @return bool True when nobody may write in it
     */
    public function isReadOnly(ChatConversation $ticket): bool
    {
        $status = $ticket->getSupportStatus();
        $cutoff = $this->closeCutoff();

        return $status === SupportStatus::Closed
            || ($status === SupportStatus::Resolved && $cutoff instanceof DateTimeImmutable && $ticket->getResolvedAt() < $cutoff);
    }

    /**
     * Whether opening support should ask "continue this ticket or open a new one?" instead of continuing it: the
     * ticket is resolved, or has had no message for `resume_after_days`.
     *
     * @param ChatConversation $ticket The user's latest ticket
     *
     * @return bool True when the user is asked
     */
    public function needsResumeQuestion(ChatConversation $ticket): bool
    {
        return !($ticket->getSupportStatus()?->isActive() ?? false)
            || $ticket->getLastMessageAt() < $this->clock->now()->modify('-' . $this->resumeAfterDays . ' days');
    }

    /**
     * Resolved tickets older than this are read-only.
     *
     * @return DateTimeImmutable|null The cutoff; null when resolved tickets never turn read-only
     */
    public function closeCutoff(): ?DateTimeImmutable
    {
        return $this->closeAfterDays === 0 ? null : $this->clock->now()->modify('-' . $this->closeAfterDays . ' days');
    }

    /**
     * Idle days after which the user is asked "continue or new?".
     *
     * @return int The threshold in days
     */
    public function resumeAfterDays(): int
    {
        return $this->resumeAfterDays;
    }
}
