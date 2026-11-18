<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

/**
 * 1.36: which of the message menu's data items are offered — forward, threads, edit history — and their limits
 * (`message_menu.forward`, `message_menu.threads`, `message_menu.edit_history`). Configuration only (no state between
 * requests): shared by the voter and the services without a dependency between them.
 */
final readonly class ChatMenuOptions
{
    /**
     * @param array{enabled?: bool, max_targets?: int, from_support?: bool} $forward `message_menu.forward`
     * @param array{enabled?: bool} $threads `message_menu.threads`
     * @param array{enabled?: bool, max_revisions?: int} $editHistory `message_menu.edit_history`
     */
    public function __construct(
        private array $forward = [],
        private array $threads = [],
        private array $editHistory = [],
    ) {
    }

    /**
     * Whether "Forward" is offered.
     */
    public function forwardEnabled(): bool
    {
        return ($this->forward['enabled'] ?? false) === true;
    }

    /**
     * Conversations one forward may go to.
     */
    public function maxForwardTargets(): int
    {
        return $this->forward['max_targets'] ?? 5;
    }

    /**
     * Whether messages of support conversations may be forwarded out.
     */
    public function forwardFromSupport(): bool
    {
        return ($this->forward['from_support'] ?? false) === true;
    }

    /**
     * Whether threads are offered.
     */
    public function threadsEnabled(): bool
    {
        return ($this->threads['enabled'] ?? false) === true;
    }

    /**
     * Whether previous texts of edited messages are kept.
     */
    public function historyEnabled(): bool
    {
        return ($this->editHistory['enabled'] ?? false) === true;
    }

    /**
     * Previous texts kept per message.
     */
    public function maxRevisions(): int
    {
        return $this->editHistory['max_revisions'] ?? 20;
    }
}
