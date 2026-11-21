<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\SearchKit;

use Nowo\SearchKitBundle\Contract\FilterableSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\PaginatedSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\SearchKitBundle\Search\Group\SearchGroupSettings;
use Nowo\SearchKitBundle\Search\Group\SensitiveGroupNotice;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

/**
 * 1.37 (spec 002, FR-009; registered only with `search_kit.enabled`, `search_kit.messages.enabled` and
 * nowo-tech/search-kit-bundle ^1.10): the "Messages" group of the SearchKit palette and results page.
 *
 * 1.38: SearchKit's generic EntitySearchProvider runs the search ({@see ChatMessageSearchDefinition}: the hooks of
 * message search), so the group follows SearchKit's shared flow — a bounded candidate window, the bundle's per-row
 * checks, the host's SearchKitEvents::ITEM_VISIBILITY votes (deny wins), the visible total, the
 * `nowo_search_kit.groups.messages` switch. This class only adds the `from:` filter, which SearchKit's provider does not
 * take, and checks it first.
 *
 * Deny by default:
 * - rows only for the signed-in user whose identifier is the query's (not IdentifierAwareSearchProviderInterface:
 *   `SearchEngineInterface::searchFor()` skips the group);
 * - no rows for a scoped search (ChatKit has no scopes), a negated or repeated `from:` filter, or any other filter key.
 *
 * Stateless (FrankenPHP worker mode); the query text is never logged.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatMessageSearchProvider implements FilterableSearchProviderInterface, PaginatedSearchProviderInterface, SearchReferenceResolverInterface
{
    /** Key of the group. */
    public const string GROUP = ChatMessageSearchDefinition::GROUP;

    /** Row kind (icon template, recents). */
    public const string KIND = ChatMessageSearchDefinition::KIND;

    /** Limiter key prefix of the palette's own search budget (audit VA2-5). */
    public const string LIMITER_KEY = ChatMessageSearchDefinition::LIMITER_KEY;

    /** The author filter (`from:me`, `from:<public id>`). */
    public const string FILTER_FROM = ChatMessageSearchDefinition::FILTER_FROM;

    private EntitySearchProvider $provider;

    /**
     * @param ChatMessageSearchDefinition $definition The hooks of message search
     * @param EventDispatcherInterface|null $dispatcher Dispatches SearchKitEvents::ITEM_VISIBILITY (null: no host event)
     * @param TranslatorInterface|null $translator The "see all" label
     * @param SearchGroupSettings|null $groups `nowo_search_kit.groups` (null: every group on)
     * @param SensitiveGroupNotice|null $notice SearchKit's notice of a sensitive group switched off
     * @param bool $recentsSafe False while SearchKit keeps recents in the browser without revalidation (the group is then off)
     */
    public function __construct(
        ChatMessageSearchDefinition $definition,
        ?EventDispatcherInterface $dispatcher = null,
        ?TranslatorInterface $translator = null,
        ?SearchGroupSettings $groups = null,
        ?SensitiveGroupNotice $notice = null,
        bool $recentsSafe = true,
    ) {
        $this->provider = new EntitySearchProvider($definition, $dispatcher, $translator, $groups ?? new SearchGroupSettings(), $recentsSafe, $notice);
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
        $from = $query->filtersOf(self::FILTER_FROM);
        if (count($from) > 1 || ($from !== [] && $from[0]->negated) || count($query->filters) !== count($from)) {
            return null;
        }

        return $this->provider->searchPage($query, $page, $perPage);
    }

    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
    {
        return $this->provider->resolve($reference, $userIdentifier, $locale);
    }
}
