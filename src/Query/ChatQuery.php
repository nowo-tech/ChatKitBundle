<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Query;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatQueryInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatChannelKey;
use Nowo\ChatKitBundle\Model\ChatConversationListItem;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Port\ChatChannelPort;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatActorResolver;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function max;
use function min;

use const ARRAY_FILTER_USE_BOTH;

/**
 * 2.2: default ChatQueryInterface (aliased to it). Reads through ChatKit's own repositories (ChatKit's entity manager),
 * applies the JSON API's rules for the user methods (ChatVoter, ChatAccess, the channel provider) and answers with views;
 * every uuid, channel key and user key the SQL matched is re-checked byte for byte in PHP (house rule LQ). Stateless
 * (FrankenPHP worker mode, kernel reset false).
 */
final readonly class ChatQuery implements ChatQueryInterface
{
    /** Conversation kinds a list may be narrowed to. */
    private const array KINDS = ['direct', 'channel', 'support'];

    /**
     * @param ChatConversationRepository $conversations Conversations
     * @param ChatMessageRepository $messages Messages, unread counts, usage lengths
     * @param ChatParticipantRepository $participants The user's participant rows (closed, muted)
     * @param ChatChannelPort $channels The channels offered to the user
     * @param ChatVoter $voter The API's rules
     * @param ChatAccess $access Reachability and the support-agent role
     * @param ChatActorResolver $actors Users by public id
     * @param ClockInterface $clock Mute and close state at read time
     * @param ChatMarkdownRenderer|null $markdown Renders the HTML of the message views; null keeps the stored render
     */
    public function __construct(
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatParticipantRepository $participants,
        private ChatChannelPort $channels,
        private ChatVoter $voter,
        private ChatAccess $access,
        private ChatActorResolver $actors,
        private ClockInterface $clock = new Clock(),
        private ?ChatMarkdownRenderer $markdown = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function conversationsFor(ChatUserInterface|string $user, int $limit = 50, ?DateTimeImmutable $before = null, bool $archived = false, array $kinds = []): array
    {
        $actor = $this->actors->resolve($user);
        if (!$actor instanceof ChatUserInterface || !$this->access->isReachable($actor)) {
            return [];
        }
        $kinds = array_values(array_filter($kinds, static fn (string $kind): bool => in_array($kind, self::KINDS, true)));
        $keys  = array_map(static fn (ChatChannel $channel): string => $channel->key, $this->channels->channelsFor($actor));
        // Participant rows outlive access (left channel, demoted agent): only what the user may read today.
        $list = array_values(array_filter(
            $this->conversations->findListForUser($actor, $keys, $this->access->isSupportAgent($actor), $this->clamp($limit), $before),
            fn (ChatConversation $conversation): bool => ($kinds === [] || in_array($conversation->getKind()->value, $kinds, true))
                && $this->voter->decide(ChatVoter::VIEW, $conversation, $actor),
        ));
        $ids    = $this->ids($list);
        $rows   = $this->participants->findForUser($actor, $ids);
        $now    = $this->clock->now();
        $list   = array_values(array_filter($list, static fn (ChatConversation $conversation): bool => ($rows[(int) $conversation->getId()] ?? null)?->isArchived($now) === true ? $archived : !$archived));
        $ids    = $this->ids($list);
        $unread = $this->messages->countUnreadByConversation((string) ChatUserReferences::key($actor), $ids);
        $latest = $this->messages->findLatestByConversationIds($ids);

        return array_map(function (ChatConversation $conversation) use ($rows, $now, $unread, $latest): ChatConversationListItem {
            $id    = (int) $conversation->getId();
            $row   = $rows[$id] ?? null;
            $last  = $latest[$id] ?? null;
            $muted = $row?->getMutedUntil();

            return new ChatConversationListItem(
                ChatConversationView::of($conversation),
                $unread[$id] ?? 0,
                $row?->isArchived($now) === true,
                $muted instanceof DateTimeImmutable && $row?->isMuted($now) === true ? $muted : null,
                $last instanceof ChatMessage ? ChatMessageView::of($last, $this->markdown) : null,
            );
        }, $list);
    }

    /**
     * {@inheritDoc}
     */
    public function conversation(ChatUserInterface|string $user, string $conversationUuid): ?ChatConversationView
    {
        $found = $this->readable($user, $conversationUuid);

        return $found === null ? null : ChatConversationView::of($found[0]);
    }

    /**
     * {@inheritDoc}
     */
    public function messages(ChatUserInterface|string $user, string $conversationUuid, int $limit = 50, ?int $beforeId = null, ?int $afterId = null): array
    {
        $found = $this->readable($user, $conversationUuid);
        if ($found === null) {
            return [];
        }

        return array_map(fn (ChatMessage $message): ChatMessageView => ChatMessageView::of($message, $this->markdown), $this->messages->findPage($found[0], $this->clamp($limit), $beforeId, $afterId));
    }

    /**
     * {@inheritDoc}
     */
    public function unreadCounts(ChatUserInterface|string $user, array $conversationUuids): array
    {
        $actor = $this->actors->resolve($user);
        $asked = $this->batch($conversationUuids);
        if (!$actor instanceof ChatUserInterface || $asked === []) {
            return [];
        }
        $readable = [];
        foreach ($this->exactByUuid($asked) as $uuid => $conversation) {
            if ($this->voter->decide(ChatVoter::VIEW, $conversation, $actor)) {
                $readable[$uuid] = (int) $conversation->getId();
            }
        }
        $counts = $readable === [] ? [] : $this->messages->countUnreadByConversation((string) ChatUserReferences::key($actor), array_values($readable));

        return array_map(static fn (int $id): int => $counts[$id] ?? 0, $readable);
    }

    /**
     * {@inheritDoc}
     */
    public function conversationsByUuids(array $conversationUuids): array
    {
        return array_map(ChatConversationView::of(...), $this->exactByUuid($this->batch($conversationUuids)));
    }

    /**
     * {@inheritDoc}
     */
    public function channelConversations(array $channelKeys): array
    {
        $keys = $this->channelKeys($channelKeys);
        if ($keys === []) {
            return [];
        }
        $byKey = [];
        foreach ($this->conversations->findBy(['channelKey' => $keys]) as $conversation) {
            $key = $conversation->getChannelKey();
            // The SQL IN runs on the column collation: only a conversation whose key is exactly one asked for.
            if ($key !== null && $conversation->isChannel() && in_array($key, $keys, true)) {
                $byKey[$key] = ChatConversationView::of($conversation);
            }
        }

        return $byKey;
    }

    /**
     * {@inheritDoc}
     */
    public function countChannelConversations(array $channelKeys): int
    {
        return count($this->channelConversations($channelKeys));
    }

    /**
     * {@inheritDoc}
     */
    public function channelBodyLength(array $channelKeys): int
    {
        $keys = $this->channelKeys($channelKeys);

        return $keys === [] ? 0 : $this->exactSum($this->messages->bodyLengthByChannelKey($keys), $keys);
    }

    /**
     * {@inheritDoc}
     */
    public function directBodyLengthByAuthors(array $authorPublicIds): int
    {
        $keys = [];
        foreach ($this->batch($authorPublicIds) as $publicId) {
            $author = $this->actors->resolve($publicId);
            $key    = $author instanceof ChatUserInterface ? ChatUserReferences::key($author) : null;
            if ($key !== null) {
                $keys[] = $key;
            }
        }
        $keys = array_values(array_unique($keys));

        return $keys === [] ? 0 : $this->exactSum($this->messages->bodyLengthByDirectAuthor($keys), $keys);
    }

    /**
     * The conversation of exactly this uuid and the resolved user, when the user may read it now.
     *
     * @return array{0: ChatConversation, 1: ChatUserInterface}|null
     */
    private function readable(ChatUserInterface|string $user, string $uuid): ?array
    {
        $actor = $this->actors->resolve($user);
        if (!$actor instanceof ChatUserInterface || $uuid === '') {
            return null;
        }
        $conversation = $this->conversations->findOneByUuid($uuid);

        return $conversation instanceof ChatConversation && $conversation->getUuid() === $uuid && $this->voter->decide(ChatVoter::VIEW, $conversation, $actor)
            ? [$conversation, $actor] : null;
    }

    /**
     * @param list<string> $uuids
     *
     * @return array<string, ChatConversation> asked uuid => conversation, in the order asked
     */
    private function exactByUuid(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $found = $this->conversations->findByUuids($uuids);
        $exact = [];
        foreach ($uuids as $uuid) {
            $conversation = $found[$uuid] ?? null;
            if ($conversation instanceof ChatConversation && $conversation->getUuid() === $uuid) {
                $exact[$uuid] = $conversation;
            }
        }

        return $exact;
    }

    /**
     * @param list<ChatConversation> $conversations
     *
     * @return list<int>
     */
    private function ids(array $conversations): array
    {
        return array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $conversations);
    }

    private function clamp(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * Distinct non-empty values, at most MAX_BATCH.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function batch(array $values): array
    {
        return array_slice(array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== ''))), 0, self::MAX_BATCH);
    }

    /**
     * Distinct channel keys that keep the ChatChannelKey rule, at most MAX_BATCH.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function channelKeys(array $keys): array
    {
        return array_values(array_filter($this->batch($keys), ChatChannelKey::isValid(...)));
    }

    /**
     * The sum of the totals whose stored key is exactly one of the asked keys.
     *
     * @param array<string, int> $totals stored key => total
     * @param list<string> $keys asked keys
     */
    private function exactSum(array $totals, array $keys): int
    {
        return array_sum(array_filter($totals, static fn (int $total, int|string $key): bool => in_array((string) $key, $keys, true), ARRAY_FILTER_USE_BOTH));
    }
}
