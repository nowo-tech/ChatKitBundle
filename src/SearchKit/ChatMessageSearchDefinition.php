<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\SearchKit;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use LogicException;
use Nowo\ChatKitBundle\Controller\ChatApiController;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Search\ChatMessageSearch;
use Nowo\ChatKitBundle\Search\ChatMessageSearchHit;
use Nowo\ChatKitBundle\Search\ChatSearchQueryException;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Nowo\SearchKitBundle\Search\Query\SearchWindow;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_values;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function parse_str;
use function parse_url;
use function preg_match;

use const PHP_URL_QUERY;

/**
 * 1.38: the SearchKit "Messages" group (1.37) on SearchKit's provider base (nowo-tech/search-kit-bundle ^1.10): the
 * hooks of {@see EntitySearchDefinition}, built on {@see ChatMessageSearch}, so the palette keeps every rule of the
 * message search page — the same permission filter in SQL (direct conversations through the viewer's participant row,
 * the viewer's own tickets, every ticket for a support agent, the channels of `channelsFor()` ∩ `canUse()`), the same
 * kinds, deleted rows and retention window, the same match on the viewer-neutral search text (full text or an escaped
 * LIKE: audit VA2-1), then per row `ChatAccess::canView()` and the text the viewer sees (a hidden `#reference` title
 * never matches), the same plain-text excerpts. SearchKit then applies the host's SearchKitEvents::ITEM_VISIBILITY
 * votes (deny wins) and counts only what is left (no count oracle).
 *
 * The `from:` filter is applied by {@see ChatMessageSearchProvider}, which checks it and wraps the generic provider; an
 * unknown or look-alike author gives no rows. The palette has its own bucket of `limits.searches_per_minute` (audits
 * VA2-5, VA3-2): one token per search. Titles are message excerpts, so the group is sensitive (off while SearchKit keeps
 * recents in the browser without revalidation: audit VA-3).
 *
 * Stateless: the viewer is resolved again in every hook (FrankenPHP worker mode, kernel reset off). The rows a search
 * shows are the messages the per-row check loaded into ChatKit's entity manager; prepareItems() reads them from it (no
 * query) and loads the other people of their conversations once, into SearchKit's per-render context (never a property).
 * The query text is never logged.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatMessageSearchDefinition extends EntitySearchDefinition
{
    /** Key of the group (1.37). */
    public const string GROUP = 'messages';

    /** Row kind (icon template, recents). */
    public const string KIND = 'chat_message';

    /** Route of a row. */
    public const string ROUTE = 'nowo_chat_page_conversation';

    /** The author filter (`from:me`, `from:<public id>`). */
    public const string FILTER_FROM = 'from';

    /** Key of the shown rows' hits in SearchKit's item context. */
    private const string HITS = 'chat_message_hits';

    /** Limiter key prefix of the palette's own search budget (audits VA2-5, VA3-2). */
    public const string LIMITER_KEY = 'nowo_chat_search_palette_';

    /**
     * @param ChatMessageSearch $search Message search
     * @param ChatSearchKitViewer $viewers The signed-in participant
     * @param UrlGeneratorInterface $urls Links to the messages and to the search page
     * @param TranslatorInterface $translator The group label
     * @param RateLimiterFactoryInterface $searchLimiter `limits.searches_per_minute`: the palette's own bucket of it
     * @param int $maxCandidates `search_kit.messages.max_candidates`: candidates read and checked per search
     */
    public function __construct(
        private readonly ChatMessageSearch $search,
        private readonly ChatSearchKitViewer $viewers,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.nowo_chat_search')]
        private readonly RateLimiterFactoryInterface $searchLimiter,
        private readonly int $maxCandidates = self::DEFAULT_MAX_CANDIDATES,
    ) {
    }

    public function group(): string
    {
        return self::GROUP;
    }

    public function label(SearchQuery $query): string
    {
        return $this->translator->trans('chat_search.group', [], NowoChatKitBundle::TRANSLATION_DOMAIN, $query->locale);
    }

    public function subjectType(): string
    {
        return self::KIND;
    }

    public function tenant(SearchQuery $query): ?string
    {
        return $this->search->enabled() ? $this->viewers->tenant($query->userIdentifier) : null;
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->search->enabled() && $this->viewers->forTenant($tenant, $query->userIdentifier) instanceof ChatUserInterface;
    }

    public function searchableFields(): array
    {
        // Not used: applyFullText() always applies message search's own match on the viewer-neutral search text.
        return ['m.search_text'];
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder
    {
        $viewer = $this->viewers->forTenant($tenant, $query->userIdentifier);
        if (!$viewer instanceof ChatUserInterface) {
            return $this->search->noRows();
        }
        $from = $query->filtersOf(self::FILTER_FROM);

        return $this->search->readableQuery($viewer, $from === [] ? null : $from[0]->value);
    }

    /**
     * Message search's match on the viewer-neutral search text, from its own query words (full text for words of
     * letters and digits, an escaped LIKE otherwise), after one token of the palette's limiter. No rows for a text
     * outside `search.min_query_length` / `search.max_query_length`, an exhausted budget, or a full-text platform with no
     * indexable word (audit VA-7).
     */
    public function applyFullText(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $terms, SearchQuery $query): bool
    {
        $words  = $this->words($query);
        $viewer = $this->viewers->viewer($query->userIdentifier);
        if (!$queryBuilder instanceof DbalQueryBuilder || $words === null || $words === [] || !$viewer instanceof ChatUserInterface
            || !$this->searchLimiter->create(self::LIMITER_KEY . $viewer->getChatPublicId())->consume()->isAccepted()
            || !$this->search->matchWords($queryBuilder, $words)) {
            $queryBuilder->andWhere('1 = 0');
        }

        return true;
    }

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        $viewer = $this->viewers->forTenant($tenant, $query->userIdentifier);
        $words  = $this->words($query);
        if (!$viewer instanceof ChatUserInterface || $words === null) {
            return [];
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $this->subjectId($row);
        }
        $kept = $this->search->visibleIds($viewer, $ids, $words);

        return array_values(array_filter($rows, fn (array|object $row): bool => in_array($this->subjectId($row), $kept, true)));
    }

    /**
     * The message id of a row (`id` of readableQuery()).
     *
     * @throws LogicException When the row has no id
     */
    public function subjectId(array|object $row): int
    {
        if (!is_array($row) || !is_numeric($row['id'] ?? null)) {
            throw new LogicException('A message search row has no id.');
        }

        return (int) $row['id'];
    }

    /**
     * The shown rows' display data in one go (SearchKit 1.10): the messages the per-row check loaded, the other people
     * of their direct conversations in one directory call, excerpts and titles for the viewer.
     */
    public function prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context): void
    {
        $viewer = $this->viewers->viewer($query->userIdentifier);
        if (!$viewer instanceof ChatUserInterface) {
            return;
        }
        $ids = [];
        foreach ($shownRows as $row) {
            $ids[] = $this->subjectId($row);
        }
        $context->set(self::HITS, $this->search->hitsOf($viewer, $ids, $this->words($query) ?? []));
    }

    /**
     * @throws LogicException When the checked row cannot be shown any more (the viewer changed within the call): SearchKit drops the group
     */
    public function toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
    {
        $hits = $context->get(self::HITS, []);
        $hit  = is_array($hits) ? ($hits[$this->subjectId($row)] ?? null) : null;
        if (!$hit instanceof ChatMessageSearchHit) {
            throw new LogicException('A checked message search row cannot be shown.');
        }

        return new SearchItem(
            $hit->snippet,
            $this->urls->generate(self::ROUTE, ['uuid' => $hit->conversationUuid, 'message' => $hit->messageUuid]),
            $hit->authorName === null ? $hit->conversationTitle : $hit->conversationTitle . ' · ' . $hit->authorName,
            null,
            null,
            self::KIND,
            null,
            $hit->createdAt,
        );
    }

    /**
     * One row on its own (prepareItems() for that row, then toPreparedItem()).
     *
     * @throws LogicException When the row cannot be shown
     */
    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        $context = new SearchItemContext();
        $this->prepareItems([$row], $query, $context);

        return $this->toPreparedItem($row, $query, $context);
    }

    public function seeAllUrl(SearchQuery $query): string
    {
        return $this->urls->generate('nowo_chat_page_search', ['q' => $query->text]);
    }

    public function maxCandidates(): int
    {
        return max(1, min(SearchWindow::MAX_CANDIDATES, $this->maxCandidates));
    }

    public function sensitive(): bool
    {
        return true;
    }

    public function idField(): string
    {
        return 'm.uuid';
    }

    /**
     * The message uuid of a stored recent: route `nowo_chat_page_conversation`, a UUID `uuid` route parameter and a UUID
     * `message` in the URL; null for anything else. SearchKit then reads the message again through every check and
     * keeps it only with the same URL (so the conversation must match as well).
     */
    public function referenceId(SearchReference $reference): ?string
    {
        if ($reference->kind !== self::KIND || $reference->route !== self::ROUTE) {
            return null;
        }
        $conversation = $reference->routeParameters['uuid'] ?? null;
        parse_str((string) parse_url($reference->url, PHP_URL_QUERY), $parameters);
        $message = $parameters['message'] ?? null;
        $uuid    = '/^' . ChatApiController::UUID . '\z/';

        return is_string($conversation) && is_string($message) && preg_match($uuid, $conversation) === 1 && preg_match($uuid, $message) === 1 ? $message : null;
    }

    /**
     * The query words (empty for an empty text: recents), or null for a text outside the configured length bounds.
     *
     * @return list<string>|null
     */
    private function words(SearchQuery $query): ?array
    {
        if ($query->text === '') {
            return [];
        }
        try {
            return $this->search->query($query->text)->words;
        } catch (ChatSearchQueryException) {
            return null;
        }
    }
}
