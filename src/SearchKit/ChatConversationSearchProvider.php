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
 * reaches it (SearchKit's deny by default for providers that do not list the filter).
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
        $this->provider = new EntitySearchProvider($definition, $dispatcher, $translator, $groups ?? new SearchGroupSettings(), $recentsSafe, $notice);
    }

    public function groupKey(): string
    {
        return self::GROUP;
    }

    public function search(SearchQuery $query): ?SearchGroup
    {
        return $this->provider->search($query);
    }

    public function searchPage(SearchQuery $query, int $page, int $perPage): ?SearchGroup
    {
        return $this->provider->searchPage($query, $page, $perPage);
    }

    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
    {
        return $this->provider->resolve($reference, $userIdentifier, $locale);
    }
}
