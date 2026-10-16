<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;

use function array_slice;
use function in_array;

/**
 * The support agents' inbox: support threads by status (`open`, `resolved`, `mine`, all), searchable by the
 * owner's name or email, with unread counts and the last message — for the API and the application's admin page.
 */
final readonly class ChatSupportInbox
{
    public const array FILTERS = ['open', 'resolved', 'mine', 'all'];
    private const int SCAN     = 200;

    public function __construct(
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatPresenter $presenter,
    ) {
    }

    /**
     * @return list<array<string, mixed>> conversation summaries (as the agent sees them) plus `owner`
     */
    public function list(ChatUserInterface $agent, string $filter, ?string $query, int $limit = 50): array
    {
        $filter  = in_array($filter, self::FILTERS, true) ? $filter : 'open';
        $query   = $query === null ? '' : mb_strtolower(trim($query));
        $threads = array_values(array_filter(
            $this->conversations->findSupportInbox($filter === 'all' ? null : $filter, $agent, self::SCAN),
            static function (ChatConversation $conversation) use ($query): bool {
                $owner = $conversation->getSupportOwner();

                return $query === '' || ($owner instanceof ChatUserInterface && (str_contains(mb_strtolower($owner->getChatDisplayName()), $query) || str_contains(mb_strtolower((string) $owner->getChatEmail()), $query)));
            },
        ));
        $threads = array_slice($threads, 0, $limit);
        $ids     = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $threads);
        $unread  = $this->messages->countUnreadByConversation((int) $agent->getId(), $ids);
        $latest  = $this->messages->findLatestByConversationIds($ids);

        return array_map(function (ChatConversation $conversation) use ($agent, $unread, $latest): array {
            $id    = (int) $conversation->getId();
            $owner = $conversation->getSupportOwner();

            return $this->presenter->conversation($conversation, $agent, null, null, true, $unread[$id] ?? 0, $latest[$id] ?? null, true) + [
                'owner' => $owner instanceof ChatUserInterface ? ['uuid' => $owner->getChatPublicId(), 'name' => $owner->getChatDisplayName(), 'email' => $owner->getChatEmail()] : null,
            ];
        }, $threads);
    }

    public function countOpen(): int
    {
        return $this->conversations->countOpenSupport();
    }
}
