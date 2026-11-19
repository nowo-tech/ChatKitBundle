<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\SearchKit;

use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Search\ChatMessageSearch;
use Nowo\ChatKitBundle\Search\ChatMessageSearchHit;
use Nowo\ChatKitBundle\Search\ChatMessageSearchPage;
use Nowo\ChatKitBundle\Search\ChatSearchQueryException;
use Nowo\SearchKitBundle\Contract\FilterableSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\PaginatedSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function count;
use function is_string;
use function parse_str;
use function parse_url;

use const PHP_URL_QUERY;

/**
 * 1.37 (spec 002, FR-009; registered only with `search_kit.enabled` and nowo-tech/search-kit-bundle ^1.8): the
 * "Messages" group of the SearchKit palette and results page, through {@see ChatMessageSearch} — the same permission
 * filter in SQL, the same per-row checks, the same plain-text excerpts (SearchKit escapes them).
 *
 * Deny by default:
 * - rows only for the signed-in user whose identifier is the query's (a search made by identifier for someone else,
 *   `SearchEngineInterface::searchFor()`, gets none: ChatKit cannot map an identifier to a participant without the
 *   session; the provider is therefore not `IdentifierAwareSearchProviderInterface`);
 * - no rows for a scoped search (ChatKit has no scopes), a negated or repeated `from:` filter, or any other filter key;
 * - no total (SearchKit shows the number of returned rows).
 *
 * Stateless (FrankenPHP worker mode); the query text is never logged.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatMessageSearchProvider implements FilterableSearchProviderInterface, PaginatedSearchProviderInterface, SearchReferenceResolverInterface
{
    /** Key of the group. */
    public const string GROUP = 'messages';

    /** Row kind (icon template, recents). */
    public const string KIND = 'chat_message';

    /** Limiter key prefix of the palette's own search budget (audit VA2-5). */
    public const string LIMITER_KEY = 'nowo_chat_search_palette_';

    /** The author filter (`from:me`, `from:<public id>`). */
    public const string FILTER_FROM = 'from';

    /**
     * Deepest results page served. Each page replays the pages before it (keyset cursors), so the bound keeps one call
     * to at most MAX_PAGE searches (audit VA-2).
     */
    public const int MAX_PAGE = 3;

    /**
     * @param ChatMessageSearch $search Message search
     * @param Security $security The signed-in user
     * @param UrlGeneratorInterface $urls Links to the messages and to the search page
     * @param TranslatorInterface $translator The group label
     * @param RateLimiterFactoryInterface $searchLimiter `limits.searches_per_minute`: the palette's own bucket of it
     * @param CurrentChatUserResolverInterface|null $currentUser The participant of the signed-in user (null: the security user itself)
     */
    public function __construct(
        private ChatMessageSearch $search,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        #[Autowire(service: 'limiter.nowo_chat_search')]
        private RateLimiterFactoryInterface $searchLimiter,
        private ?CurrentChatUserResolverInterface $currentUser = null,
    ) {
    }

    public function supportedFilters(): array
    {
        return [self::FILTER_FROM];
    }

    public function groupKey(): string
    {
        return self::GROUP;
    }

    public function search(SearchQuery $query): ?SearchGroup
    {
        return $this->searchPage($query, 1, $query->limit);
    }

    public function searchPage(SearchQuery $query, int $page, int $perPage): ?SearchGroup
    {
        $viewer = $this->viewer($query->userIdentifier);
        if (!$viewer instanceof ChatUserInterface || $query->isScoped() || $page < 1 || $page > self::MAX_PAGE) {
            return null;
        }
        $from = $query->filtersOf(self::FILTER_FROM);
        if (count($from) > 1 || ($from !== [] && $from[0]->negated) || count($query->filters) !== count($from)) {
            return null;
        }
        // Audits VA-2 / VA2-5: the palette has its own bucket of the same limiter (`limits.searches_per_minute`, key
        // `nowo_chat_search_palette_<public id>`), so typing in the palette never eats the page and API budget; a call
        // costs one token per search it runs (results page N replays N searches). Over it, no rows.
        if (!$this->searchLimiter->create(self::LIMITER_KEY . $viewer->getChatPublicId())->consume($page)->isAccepted()) {
            return null;
        }
        try {
            $search = $this->search->query($query->text, null, $from === [] ? null : $from[0]->value);
        } catch (ChatSearchQueryException) {
            return null;
        }
        $result = $this->search->search($viewer, $search, $perPage);
        for ($current = 1; $current < $page && $result->next !== null; ++$current) {
            $result = $this->search->search($viewer, $this->search->query($query->text, null, $search->author, null, null, false, $result->next), $perPage);
        }
        if ($current < $page) {
            return null;
        }

        return $this->group($result, $query);
    }

    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
    {
        $viewer = $this->viewer($userIdentifier);
        if (!$viewer instanceof ChatUserInterface || $reference->kind !== self::KIND || $reference->route !== 'nowo_chat_page_conversation') {
            return null;
        }
        $conversation = $reference->routeParameters['uuid'] ?? null;
        parse_str((string) parse_url($reference->url, PHP_URL_QUERY), $parameters);
        $message = $parameters['message'] ?? null;
        if (!is_string($conversation) || !is_string($message)) {
            return null;
        }
        $hit = $this->search->find($viewer, $conversation, $message);
        if (!$hit instanceof ChatMessageSearchHit) {
            return null;
        }
        $item = $this->item($hit);

        // Same URL as the reference, or nothing (SearchKit contract).
        return $item->url === $reference->url ? $item : null;
    }

    /**
     * The participant of the signed-in user, only when it is the user the search runs for.
     */
    private function viewer(string $userIdentifier): ?ChatUserInterface
    {
        if (!$this->search->enabled()) {
            return null;
        }
        $user = $this->security->getUser();
        if (!$user instanceof UserInterface || $user->getUserIdentifier() !== $userIdentifier) {
            return null;
        }
        $viewer = $this->currentUser instanceof CurrentChatUserResolverInterface ? $this->currentUser->resolve($user) : $user;

        return $viewer instanceof ChatUserInterface ? $viewer : null;
    }

    private function group(ChatMessageSearchPage $page, SearchQuery $query): ?SearchGroup
    {
        if ($page->hits === []) {
            return null;
        }

        return new SearchGroup(
            self::GROUP,
            $this->translator->trans('chat_search.group', [], NowoChatKitBundle::TRANSLATION_DOMAIN, $query->locale),
            array_map($this->item(...), $page->hits),
            null,
            $this->urls->generate('nowo_chat_page_search', ['q' => $query->text]),
            $this->translator->trans('chat_search.see_all', [], NowoChatKitBundle::TRANSLATION_DOMAIN, $query->locale),
        );
    }

    private function item(ChatMessageSearchHit $hit): SearchItem
    {
        return new SearchItem(
            $hit->snippet,
            $this->urls->generate('nowo_chat_page_conversation', ['uuid' => $hit->conversationUuid, 'message' => $hit->messageUuid]),
            $hit->authorName === null ? $hit->conversationTitle : $hit->conversationTitle . ' · ' . $hit->authorName,
            null,
            null,
            self::KIND,
            null,
            $hit->createdAt,
        );
    }
}
