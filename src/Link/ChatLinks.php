<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Link;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Event\ChatLinkAddedEvent;
use Nowo\ChatKitBundle\Event\ChatLinkRemovedEvent;
use Nowo\ChatKitBundle\Event\ChatLinkUpdatedEvent;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationLinkRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageLinkRepository;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function mb_strtolower;
use function str_contains;

use const DATE_ATOM;

/**
 * 1.19: links of conversations — the pinned links (add, change, remove, with events and the `links` broadcast) and the
 * link index of the messages behind the "Links" panel. Authorization is the caller's job (ChatAccess::canManageLinks()
 * for changes, the conversation voter for reading); this service validates (ChatLinkUrls), enforces the per-conversation
 * limit and keeps the data consistent.
 */
final readonly class ChatLinks
{
    /**
     * Builds the service.
     *
     * @param EntityManagerInterface $entityManager Persists the links
     * @param ChatLinkUrls $urls URL rules and providers
     * @param ChatConversationLinkRepository $pinned Pinned links
     * @param ChatMessageLinkRepository $index Link index of the messages
     * @param ChatNotifier $notifier Broadcasts `links` after a change of the pinned links
     * @param ClockInterface $clock Clock of the creation and change times
     * @param EventDispatcherInterface|null $events Receives ChatLinkAddedEvent, ChatLinkUpdatedEvent and ChatLinkRemovedEvent; null dispatches none
     * @param int $maxPinned Pinned links per conversation (`links.max_pinned`)
     * @param bool $enabled Whether the links feature is on (`links.enabled`); off, nothing is indexed and the API answers 404
     * @param bool $channelMembers Whether every channel member manages the pinned links (`links.channel_managers: members`)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatLinkUrls $urls,
        private ChatConversationLinkRepository $pinned,
        private ChatMessageLinkRepository $index,
        private ChatNotifier $notifier,
        private ClockInterface $clock = new Clock(),
        private ?EventDispatcherInterface $events = null,
        private int $maxPinned = 20,
        private bool $enabled = true,
        private bool $channelMembers = true,
    ) {
    }

    /**
     * Whether the links feature is on.
     *
     * @return bool True when enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Pinned links allowed per conversation.
     *
     * @return int The limit
     */
    public function maxPinned(): int
    {
        return $this->maxPinned;
    }

    /**
     * Whether every channel member manages the pinned links (else only whom the application allows).
     *
     * @return bool True for `channel_managers: members`
     */
    public function channelMembersManage(): bool
    {
        return $this->channelMembers;
    }

    /**
     * The URL rules and providers.
     *
     * @return ChatLinkUrls The rules
     */
    public function urls(): ChatLinkUrls
    {
        return $this->urls;
    }

    /**
     * The pinned links of a conversation, oldest first.
     *
     * @param ChatConversation $conversation The conversation
     *
     * @return list<ChatConversationLink>
     */
    public function pinned(ChatConversation $conversation): array
    {
        return $this->pinned->forConversation($conversation);
    }

    /**
     * Pins a link to the conversation, dispatches ChatLinkAddedEvent and broadcasts `links`.
     *
     * @param ChatUserInterface $by Who adds it
     * @param ChatConversation $conversation The conversation
     * @param string $url The link as typed
     * @param string|null $title Optional title
     *
     * @throws InvalidArgumentException `invalid_link_url`, `invalid_link_title` or `too_many_links`
     *
     * @return ChatConversationLink The new link
     */
    public function add(ChatUserInterface $by, ChatConversation $conversation, string $url, ?string $title): ChatConversationLink
    {
        $url   = $this->urls->normalize($url);
        $title = $this->urls->title($title);
        if (count($this->pinned->forConversation($conversation)) >= $this->maxPinned) {
            throw new InvalidArgumentException('too_many_links');
        }
        $link = new ChatConversationLink($conversation, $by, $url, $this->urls->host($url), $title, $this->clock->now());
        $this->entityManager->persist($link);
        $this->entityManager->flush();
        $this->events?->dispatch(new ChatLinkAddedEvent($link, $by));
        $this->notifier->linksChanged($conversation);

        return $link;
    }

    /**
     * Changes a pinned link; dispatches ChatLinkUpdatedEvent and broadcasts `links` when something changed.
     *
     * @param ChatUserInterface $by Who changes it
     * @param ChatConversationLink $link The pinned link
     * @param string $url The link as typed
     * @param string|null $title Optional title
     *
     * @throws InvalidArgumentException `invalid_link_url` or `invalid_link_title`
     */
    public function update(ChatUserInterface $by, ChatConversationLink $link, string $url, ?string $title): void
    {
        $url   = $this->urls->normalize($url);
        $title = $this->urls->title($title);
        if ($url === $link->getUrl() && $title === $link->getTitle()) {
            return;
        }
        $previousUrl   = $link->getUrl();
        $previousTitle = $link->getTitle();
        $link->change($url, $this->urls->host($url), $title, $this->clock->now());
        $this->entityManager->flush();
        $this->events?->dispatch(new ChatLinkUpdatedEvent($link, $by, $previousUrl, $previousTitle));
        $this->notifier->linksChanged($link->getConversation());
    }

    /**
     * Removes a pinned link, dispatches ChatLinkRemovedEvent and broadcasts `links`.
     *
     * @param ChatUserInterface $by Who removes it
     * @param ChatConversationLink $link The pinned link
     */
    public function remove(ChatUserInterface $by, ChatConversationLink $link): void
    {
        $event = new ChatLinkRemovedEvent($link->getConversation(), $link->getUuid(), $link->getUrl(), $link->getTitle(), $by);
        $this->entityManager->remove($link);
        $this->entityManager->flush();
        $this->events?->dispatch($event);
        $this->notifier->linksChanged($event->conversation);
    }

    /**
     * Validates the link cards of a message text and writes them back normalised.
     *
     * @param string $body The message text
     *
     * @throws InvalidArgumentException `invalid_link` when a card holds a link that is not accepted
     *
     * @return string The text with normalised cards
     */
    public function normalizeCards(string $body): string
    {
        return $this->urls->normalizeCards($body);
    }

    /**
     * Puts the message's links in the index (replacing its earlier rows); nothing for a deleted message, a line or a
     * bot message, or with the feature off. Persists without flushing: the caller flushes.
     *
     * @param ChatMessage $message The sent or edited message
     *
     * @return int Links indexed
     */
    public function index(ChatMessage $message): int
    {
        if (!$this->enabled) {
            return 0;
        }
        if ($message->getId() !== null) {
            // @igor-ignore - a DQL delete through the repository, not worker state.
            $this->index->removeForMessageIds([$message->getId()]);
        }
        if ($message->isDeleted() || $message->getKind() !== ChatMessage::KIND_TEXT) {
            return 0;
        }
        $links = $this->urls->extract($message->getBody());
        foreach ($links as $link) {
            $host = $this->urls->host($link['url']);
            $this->entityManager->persist(new ChatMessageLink(
                $message,
                $link['url'],
                $host,
                $link['title'],
                $link['kind'],
                $this->urls->provider($host)?->key,
                $message->getCreatedAt(),
            ));
        }

        return count($links);
    }

    /**
     * Removes the message's rows from the index (a deleted message).
     *
     * @param ChatMessage $message The message
     */
    public function forget(ChatMessage $message): void
    {
        if ($message->getId() !== null) {
            // @igor-ignore - a DQL delete through the repository, not worker state.
            $this->index->removeForMessageIds([$message->getId()]);
        }
    }

    /**
     * One page of the links shared in the conversation's messages, newest first.
     *
     * @param ChatConversation $conversation The conversation
     * @param string|null $query Text to find in the title or the URL
     * @param string|null $provider Provider key, `other`, or null for all
     * @param int|null $before Only rows older than this cursor
     * @param int $limit Maximum rows
     *
     * @return list<ChatMessageLink>
     */
    public function shared(ChatConversation $conversation, ?string $query, ?string $provider, ?int $before, int $limit): array
    {
        return $this->index->page($conversation, $query, $provider, $before, $limit);
    }

    /**
     * The JSON view of a pinned link.
     *
     * @param ChatConversationLink $link The link
     *
     * @return array{uuid: string, url: string, title: string|null, label: string, host: string, provider: array{key: string, label: string, icon: string, fallbackIcon: string}|null, createdBy: array{uuid: string, name: string}|null, createdAt: string, updatedAt: string|null}
     */
    public function presentPinned(ChatConversationLink $link): array
    {
        $author = $link->getCreatedBy();

        return [
            'uuid'      => $link->getUuid(),
            'url'       => $link->getUrl(),
            'title'     => $link->getTitle(),
            'label'     => $this->urls->label($link->getTitle(), $link->getHost()),
            'host'      => $link->getHost(),
            'provider'  => $this->urls->providerView($link->getHost()),
            'createdBy' => $author instanceof ChatUserInterface ? ['uuid' => $author->getChatPublicId(), 'name' => $author->getChatDisplayName()] : null,
            'createdAt' => $link->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $link->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * The JSON view of a link shared in a message.
     *
     * @param ChatMessageLink $link The index row
     *
     * @return array{id: int, url: string, title: string|null, label: string, host: string, provider: array{key: string, label: string, icon: string, fallbackIcon: string}|null, kind: string, messageId: int, messageUuid: string, author: array{uuid: string, name: string}|null, createdAt: string}
     */
    public function presentShared(ChatMessageLink $link): array
    {
        $message = $link->getMessage();
        $author  = $message->getAuthor();

        return [
            'id'          => (int) $link->getId(),
            'url'         => $link->getUrl(),
            'title'       => $link->getTitle(),
            'label'       => $this->urls->label($link->getTitle(), $link->getHost()),
            'host'        => $link->getHost(),
            'provider'    => $this->urls->providerView($link->getHost()),
            'kind'        => $link->getKind(),
            'messageId'   => (int) $message->getId(),
            'messageUuid' => $message->getUuid(),
            'author'      => $author instanceof ChatUserInterface ? ['uuid' => $author->getChatPublicId(), 'name' => $author->getChatDisplayName()] : null,
            'createdAt'   => $link->getCreatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * Whether a link matches the panel's search and provider filter (for the pinned links, filtered in memory).
     *
     * @param ChatConversationLink $link The pinned link
     * @param string|null $query Text to find in the title or the URL
     * @param string|null $provider Provider key, `other`, or null for all
     *
     * @return bool True when it matches
     */
    public function matches(ChatConversationLink $link, ?string $query, ?string $provider): bool
    {
        $key = $this->urls->provider($link->getHost())?->key;
        if ($provider === ChatMessageLinkRepository::OTHER ? $key !== null : ($provider !== null && $provider !== '' && $key !== $provider)) {
            return false;
        }

        return $query === null || $query === ''
            || str_contains(mb_strtolower($link->getUrl()), mb_strtolower($query))
            || str_contains(mb_strtolower((string) $link->getTitle()), mb_strtolower($query));
    }
}
