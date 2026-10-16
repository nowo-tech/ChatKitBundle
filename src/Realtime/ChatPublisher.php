<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Realtime;

use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Pushes one chat event to the private topics of the given users. Publishing never breaks the request (the
 * clients' catch-up covers a lost event); payloads never carry viewer-specific data.
 */
final readonly class ChatPublisher
{
    public function __construct(
        private ChatRealtimeInterface $realtime,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<ChatUserInterface> $recipients
     * @param array<string, mixed> $payload
     */
    public function publish(string $type, array $payload, array $recipients): void
    {
        if ($recipients === [] || !$this->realtime->isEnabled()) {
            return;
        }
        $topics = array_values(array_unique(array_map(
            static fn (ChatUserInterface $user): string => ChatTopics::forUser($user->getChatPublicId()),
            $recipients,
        )));
        try {
            $this->realtime->publish($topics, ['type' => $type] + $payload);
        } catch (Throwable $exception) {
            $this->logger->warning('Realtime publish failed for a chat event.', ['type' => $type, 'exception' => $exception]);
        }
    }
}
