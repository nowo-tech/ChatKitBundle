<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\SearchKit;

use Nowo\ChatKitBundle\DependencyInjection\Compiler\SearchKitRecentsPass;
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

/**
 * 1.38: the "Conversations" group (`chat_channels`) of the SearchKit palette and results page, registered only with
 * `search_kit.enabled`, `search_kit.channels.enabled` and nowo-tech/search-kit-bundle ^1.10: SearchKit's generic
 * EntitySearchProvider around {@see ChatConversationSearchDefinition}.
 *
 * ChatKit registers it itself (instead of tagging the definition for SearchKit's EntitySearchPass) so that
 * {@see SearchKitRecentsPass} can remove it, like the message group,
 * while SearchKit keeps recents in the browser without revalidation. It takes no filter: a query with a filter never
 * reaches it (SearchKit's deny by default for providers that do not list the filter). When the definition's direct
 * scan (`search_kit.channels.direct_scan`) was full, the group's total is marked not exact.
 *
 * Stateless (FrankenPHP worker mode).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatConversationSearchProvider implements PaginatedSearchProviderInterface, SearchReferenceResolverInterface
{
    /** Key of the group. */
    public const string GROUP = ChatConversationSearchDefinition::GROUP;

    private EntitySearchProvider $provider;

    private ChatConversationSearchDefinition $definition;

    /**
     * @param ChatConversationSearchDefinition $definition The hooks of the group
     * @param EventDispatcherInterface|null $dispatcher Dispatches SearchKitEvents::ITEM_VISIBILITY (null: no host event)
     * @param TranslatorInterface|null $translator The "see all" label
     * @param SearchGroupSettings|null $groups `nowo_search_kit.groups` (null: every group on)
     * @param SensitiveGroupNotice|null $notice SearchKit's notice of a sensitive group switched off
     * @param bool $recentsSafe False while SearchKit keeps recents in the browser without revalidation (the group is then off)
     */
    public function __construct(
        ChatConversationSearchDefinition $definition,
        ?EventDispatcherInterface $dispatcher = null,
        ?TranslatorInterface $translator = null,
        ?SearchGroupSettings $groups = null,
        ?SensitiveGroupNotice $notice = null,
        bool $recentsSafe = true,
    ) {
        $this->definition = $definition;
        $this->provider   = new EntitySearchProvider($definition, $dispatcher, $translator, $groups ?? new SearchGroupSettings(), $recentsSafe, $notice);
    }

    /**
     * Returns the key of the search group this provider fills.
     */
    public function groupKey(): string
    {
        return self::GROUP;
    }

    /**
     * Runs the search for the given query and returns the matching group, or null when there is nothing to show.
     */
    public function search(SearchQuery $query): ?SearchGroup
    {
        return $this->searchPage($query, 1, $query->limit);
    }

    /**
     * SearchKit's page, with the total marked not exact when the direct-conversation scan of this search was full
     * (older direct conversations were not looked at: audit VO-1). The note is read from the current request for this
     * search object only.
     */
    public function searchPage(SearchQuery $query, int $page, int $perPage): ?SearchGroup
    {
        $group = $this->provider->searchPage($query, $page, $perPage);
        $cut   = $this->definition->takeScanCut($query);
        if (!$group instanceof SearchGroup || !$cut || !$group->exactTotal) {
            return $group;
        }

        return new SearchGroup($group->key, $group->label, $group->items, $group->total, $group->moreUrl, $group->moreLabel, false);
    }

    /**
     * Resolves a stored search reference back into a result item for the given user, or null when it is no longer visible.
     */
    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
    {
        return $this->provider->resolve($reference, $userIdentifier, $locale);
    }
}
