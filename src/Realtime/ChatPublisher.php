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
    /**
     * Builds the publisher from the realtime transport and a logger for failed publishes.
     *
     * @param ChatRealtimeInterface $realtime The transport (Mercure or null)
     * @param LoggerInterface $logger Receives a warning when a publish fails
     */
    public function __construct(
        private ChatRealtimeInterface $realtime,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Publishes the event to the recipients' private topics; failures are logged, never thrown.
     *
     * @param string $type Event type (`message`, `read`, `typing` …)
     * @param array<string, mixed> $payload Viewer-neutral event data
     * @param list<ChatUserInterface> $recipients Users who receive the event
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
