<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\SearchKit;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use LogicException;
use Nowo\ChatKitBundle\Controller\ChatApiController;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatChannelKey;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Port\ChatChannelPort;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Search\ChatSearchText;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Support\ChatSupportPolicy;
use Nowo\ChatKitBundle\Support\ChatSupportTopics;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Nowo\SearchKitBundle\Search\Query\LikePattern;
use Nowo\SearchKitBundle\Search\Query\SearchWindow;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_keys;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function max;
use function min;
use function preg_match;
use function spl_object_id;
use function strlen;

/**
 * 1.38: the SearchKit "Conversations" group (`chat_channels`): the conversations the viewer can open on ChatKit's own
 * pages, found by their name — a direct conversation by the other person's name, a channel by its `#label`, a support
 * ticket by its label (`SUP-000123 · Topic`). Built on SearchKit's provider base ({@see EntitySearchDefinition}).
 *
 * Who sees what — the rules of the conversation page (`ChatAccess::canView()`) and of message search:
 * - direct conversations through the viewer's participant row, then `involvesDirectly()` byte for byte;
 * - support tickets of the viewer, and every ticket for a support agent (as the agent's inbox and the conversation page
 *   allow); never intake drafts (no ticket number, no name to find);
 * - channels of `channelsFor()` ∩ `canUse()` only (a channel the viewer left, or one the provider no longer lists, is not
 *   found; nor a channel opened by key that the provider never lists);
 * - conversations the viewer closed (archived) in their list are found: they still open, from the list's "Closed"
 *   view as from a link. Resolved and closed tickets are found too (they open read-only).
 *
 * The names are not stored by ChatKit (people's names come from the directory, channel labels from the channel provider,
 * topic labels from the configuration), so they are matched in PHP, case- and accent-insensitively
 * ({@see ChatSearchText::containsAll()}, a plain substring test: `%`, `_`, `!` and `\` are literal), and only the
 * matching ids, keys, topics and ticket numbers reach SQL, as bound parameters (a topic that is no longer configured
 * is matched on its stored key with SearchKit's escaped LIKE). Direct conversations are matched within the viewer's
 * newest `search_kit.channels.direct_scan` ones; when that scan is full the group's total is marked not exact
 * ({@see ChatConversationSearchProvider}, audit VO-1). Ticket numbers match as a whole number (`123`, `000123`,
 * `SUP-000123`) or through the full label. The group has its own bucket of `limits.searches_per_minute` (key
 * `nowo_chat_search_conversations_<public id>`, one token per search; audit VO-2). Every candidate is then checked
 * again per row — access, and the name the viewer sees — before SearchKit applies the host's SearchKitEvents::ITEM_VISIBILITY votes (deny wins) and counts
 * what is left (no count oracle). Only names the viewer already sees in their list are ever matched or shown (never a
 * channel's members, never message text, never a ticket's owner for anyone but agents, who see it in their inbox).
 *
 * Membership is in SQL (no window slot or count for a row the viewer may not open): the viewer's participant row and
 * the pair (direct), the owner (tickets, unless an agent), the listed and usable channel keys. Hidden rows therefore
 * never reach the candidate window; the per-row check is defence in depth (look-alikes under a folding collation).
 *
 * Stateless: the viewer is resolved again in every hook; the people and channels a search needs come through
 * ChatAccess, whose answers live on the current request only, and the shown rows' display data lives in SearchKit's
 * per-render item context (prepareItems()), never in a property (FrankenPHP worker mode, kernel reset off). Read only:
 * no write, flush, clear or transaction on any entity manager.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatConversationSearchDefinition extends EntitySearchDefinition
{
    /** Key of the group. */
    public const string GROUP = 'chat_channels';

    /** Row kind (icon template, recents) and host event subject type. */
    public const string KIND = 'chat_conversation';

    /** Route of a row. */
    public const string ROUTE = 'nowo_chat_page_conversation';

    /** Default of `search_kit.channels.direct_scan`. */
    public const int DEFAULT_DIRECT_SCAN = 200;

    /** Largest `search_kit.channels.direct_scan`. */
    public const int MAX_DIRECT_SCAN = 5000;

    /** Keys of the shown rows' display data in SearchKit's item context. */
    private const string VIEWER = 'chat_viewer';

    private const string PEOPLE = 'chat_people';

    private const string CHANNELS = 'chat_channels';

    private const string AGENT = 'chat_agent';

    /** Limiter key prefix of the group's own search budget (audit VO-2; no other chat bucket's prefix starts it). */
    public const string LIMITER_KEY = 'nowo_chat_search_conversations_';

    /** Request attribute where a search notes that its direct scan was cut (search object id => true). */
    private const string SCAN_CUT = '_nowo_chat_conversation_scan_cut';

    /** Longest digit run read as a ticket number. */
    private const int MAX_NUMBER_DIGITS = 9;

    /**
     * @param ChatConversationRepository $conversations The conversations
     * @param ChatSearchKitViewer $viewers The signed-in participant
     * @param ChatAccess $access Access, support agents, channels and people (request-scoped answers)
     * @param ChatChannelPort $channels The channels the viewer belongs to (`channelsFor()`)
     * @param ChatPresenter $presenter Titles as the viewer's list shows them
     * @param ChatSupportTopics $topics Topic labels of tickets
     * @param ChatSupportPolicy $policy Readable ticket numbers
     * @param UrlGeneratorInterface $urls Links to the conversations
     * @param TranslatorInterface $translator The group label
     * @param RateLimiterFactoryInterface $searchLimiter `limits.searches_per_minute`: the group's own bucket of it (audit VO-2)
     * @param RequestStack $requests The current request (where a search notes that its direct scan was cut: audit VO-1)
     * @param int $maxCandidates `search_kit.channels.max_candidates`
     * @param int $directScan `search_kit.channels.direct_scan`
     */
    public function __construct(
        private readonly ChatConversationRepository $conversations,
        private readonly ChatSearchKitViewer $viewers,
        private readonly ChatAccess $access,
        private readonly ChatChannelPort $channels,
        private readonly ChatPresenter $presenter,
        private readonly ChatSupportTopics $topics,
        private readonly ChatSupportPolicy $policy,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.nowo_chat_search')]
        private readonly RateLimiterFactoryInterface $searchLimiter,
        private readonly RequestStack $requests,
        private readonly int $maxCandidates = self::DEFAULT_MAX_CANDIDATES,
        private readonly int $directScan = self::DEFAULT_DIRECT_SCAN,
    ) {
    }

    /**
     * Returns the key of the search group.
     */
    public function group(): string
    {
        return self::GROUP;
    }

    /**
     * Returns the translated label of the group for the given query.
     */
    public function label(SearchQuery $query): string
    {
        return $this->translator->trans('chat_search.conversations.group', [], NowoChatKitBundle::TRANSLATION_DOMAIN, $query->locale);
    }

    /**
     * Returns the subject type this definition searches.
     */
    public function subjectType(): string
    {
        return self::KIND;
    }

    /**
     * Returns the tenant the query runs for, or null when the query carries none.
     */
    public function tenant(SearchQuery $query): ?string
    {
        return $this->viewers->tenant($query->userIdentifier);
    }

    /**
     * Tells whether the search is enabled for the given tenant.
     */
    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->viewers->forTenant($tenant, $query->userIdentifier) instanceof ChatUserInterface;
    }

    /**
     * Returns the columns the full-text match applies to.
     *
     * @return list<string>
     */
    public function searchableFields(): array
    {
        // Not used: applyFullText() matches the names (not stored by ChatKit) in PHP.
        return ['c.channelKey'];
    }

    /**
     * Builds the base query for the given tenant and search query.
     */
    public function baseQuery(int|string $tenant, SearchQuery $query): OrmQueryBuilder
    {
        $viewer = $this->viewers->forTenant($tenant, $query->userIdentifier);
        if (!$viewer instanceof ChatUserInterface) {
            return $this->conversations->createQueryBuilder('c')->where('1 = 0');
        }

        return $this->conversations->createSearchQueryBuilder($viewer, array_keys($this->channelsOf($viewer)), $this->access->isSupportAgent($viewer));
    }

    /**
     * The name match: the matching direct conversations, channels and tickets, found in PHP from the names the viewer
     * sees, reach SQL as ids, keys, topic keys and numbers (bound parameters). No match at all: no rows.
     */
    public function applyFullText(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $terms, SearchQuery $query): bool
    {
        $viewer = $this->viewers->viewer($query->userIdentifier);
        $words  = ChatSearchText::words($query->text);
        if (!$queryBuilder instanceof OrmQueryBuilder || !$viewer instanceof ChatUserInterface || $words === []
            || !$this->searchLimiter->create(self::LIMITER_KEY . $viewer->getChatPublicId())->consume()->isAccepted()) {
            $queryBuilder->andWhere('1 = 0');

            return true;
        }
        $branches = ['(c.kind = :chat_support AND ' . $this->ticketCondition($queryBuilder, $words) . ')'];
        $queryBuilder->setParameter('chat_support', ConversationKind::Support);
        $direct = $this->matchingDirect($viewer, $words, $query);
        if ($direct !== []) {
            $branches[] = '(c.kind = :chat_direct AND c.id IN (:chat_match_ids))';
            $queryBuilder->setParameter('chat_direct', ConversationKind::Direct)->setParameter('chat_match_ids', $direct);
        }
        $keys = [];
        foreach ($this->channelsOf($viewer) as $key => $channel) {
            if (ChatSearchText::containsAll($words, '#' . $channel->label)) {
                $keys[] = $key;
            }
        }
        if ($keys !== []) {
            $branches[] = '(c.kind = :chat_channel AND c.channelKey IN (:chat_match_keys))';
            $queryBuilder->setParameter('chat_channel', ConversationKind::Channel)->setParameter('chat_match_keys', $keys);
        }
        $queryBuilder->andWhere('(' . implode(' OR ', $branches) . ')');

        return true;
    }

    /**
     * Per row, again: ChatKit's access check, the channel still listed for the viewer, and the name the viewer sees
     * holding every word (the other people of the page's direct conversations are loaded in one directory call).
     */
    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        $viewer = $this->viewers->forTenant($tenant, $query->userIdentifier);
        if (!$viewer instanceof ChatUserInterface) {
            return [];
        }
        $words    = ChatSearchText::words($query->text);
        $channels = $this->channelsOf($viewer);
        $peers    = [];
        foreach ($rows as $row) {
            $peer = $row instanceof ChatConversation ? $row->peerKeyOf($viewer) : null;
            if ($peer !== null) {
                $peers[] = $peer;
            }
        }
        $people = $this->access->usersByIds($peers);
        $kept   = [];
        foreach ($rows as $row) {
            if (!$row instanceof ChatConversation || !$this->access->canView($row, $viewer)) {
                continue;
            }
            $name = match (true) {
                $row->isDirect()  => $this->presenter->heading($row, $viewer, $people[(string) $row->peerKeyOf($viewer)] ?? null, null)[0],
                $row->isChannel() => isset($channels[(string) $row->getChannelKey()]) ? '#' . $channels[(string) $row->getChannelKey()]->label : null,
                default           => $this->presenter->ticketLabel($row),
            };
            if ($name !== null && ChatSearchText::containsAll($words, $name)) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * The shown rows' display data in one go (SearchKit 1.10): the other people of their direct conversations in one
     * directory call, their channels, whether the viewer answers support.
     */
    public function prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context): void
    {
        $viewer = $this->viewers->viewer($query->userIdentifier);
        if (!$viewer instanceof ChatUserInterface) {
            return;
        }
        $peers    = [];
        $channels = [];
        foreach ($shownRows as $row) {
            if (!$row instanceof ChatConversation) {
                continue;
            }
            $peer = $row->peerKeyOf($viewer);
            if ($peer !== null) {
                $peers[] = $peer;
            }
            if ($row->isChannel()) {
                $channels[(string) $row->getChannelKey()] = $this->access->channel((string) $row->getChannelKey());
            }
        }
        $context->set(self::VIEWER, $viewer);
        $context->set(self::PEOPLE, $this->access->usersByIds($peers));
        $context->set(self::CHANNELS, $channels);
        $context->set(self::AGENT, $this->access->isSupportAgent($viewer));
    }

    /**
     * @throws LogicException When the checked row cannot be shown any more (the viewer changed within the call): SearchKit drops the group
     */
    public function toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
    {
        $viewer   = $context->get(self::VIEWER);
        $people   = $context->get(self::PEOPLE, []);
        $channels = $context->get(self::CHANNELS, []);
        if (!$row instanceof ChatConversation || !$viewer instanceof ChatUserInterface || !is_array($people) || !is_array($channels)) {
            throw new LogicException('A checked conversation search row cannot be shown.');
        }
        $peer               = $people[(string) $row->peerKeyOf($viewer)] ?? null;
        $channel            = $channels[(string) $row->getChannelKey()] ?? null;
        [$title, $subtitle] = $this->presenter->heading($row, $viewer, $peer instanceof ChatUserInterface ? $peer : null, $channel instanceof ChatChannel ? $channel : null, $context->get(self::AGENT) === true);
        if ($row->isDirect()) {
            // Never the other person's e-mail address (the dock's subtitle): the kind of conversation.
            $subtitle = $this->translator->trans('chat_search.conversations.direct', [], NowoChatKitBundle::TRANSLATION_DOMAIN, $query->locale);
        }

        return new SearchItem(
            $title,
            $this->urls->generate(self::ROUTE, ['uuid' => $row->getUuid()]),
            $subtitle,
            null,
            null,
            self::KIND,
            null,
            $row->getLastMessageAt(),
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

    /**
     * Returns the URL of the full results page for the query, or null when there is none.
     */
    public function seeAllUrl(SearchQuery $query): ?string
    {
        // ChatKit has no page that lists conversations by name: SearchKit's results page is the "see all".
        return null;
    }

    /**
     * Returns the maximum number of candidate rows read before the visibility filter.
     */
    public function maxCandidates(): int
    {
        return max(1, min(SearchWindow::MAX_CANDIDATES, $this->maxCandidates));
    }

    /**
     * Tells whether the results are sensitive and must not be stored in recents.
     */
    public function sensitive(): bool
    {
        // People's names and private channel names: off while SearchKit keeps recents in the browser unchecked.
        return true;
    }

    /**
     * Returns the identifier column of the searched rows.
     */
    public function idField(): string
    {
        return 'c.uuid';
    }

    /**
     * The conversation uuid of a stored recent: kind `chat_conversation`, route `nowo_chat_page_conversation` and a UUID
     * `uuid` parameter; null for anything else. SearchKit then reads the conversation again through every check and keeps
     * it only with the same URL.
     */
    public function referenceId(SearchReference $reference): ?string
    {
        $uuid = $reference->routeParameters['uuid'] ?? null;

        return $reference->kind === self::KIND && $reference->route === self::ROUTE && is_string($uuid) && preg_match('/^' . ChatApiController::UUID . '\z/', $uuid) === 1 ? $uuid : null;
    }

    /**
     * Whether this search's direct scan stopped at `direct_scan` (older direct conversations not looked at), read once:
     * the note is removed. Kept on the current request for this search object only, never in a property; without a
     * request there is no note (false).
     *
     * @param SearchQuery $query The search (the object the hooks received)
     */
    public function takeScanCut(SearchQuery $query): bool
    {
        $request = $this->requests->getMainRequest();
        $notes   = $request?->attributes->get(self::SCAN_CUT);
        if (!$request instanceof Request || !is_array($notes) || !isset($notes[spl_object_id($query)])) {
            return false;
        }
        unset($notes[spl_object_id($query)]);
        // @igor-ignore - attributes of the current main request (request-scoped, gone with it), not service state.
        $request->attributes->set(self::SCAN_CUT, $notes);

        return true;
    }

    private function noteScanCut(SearchQuery $query): void
    {
        $request = $this->requests->getMainRequest();
        if ($request instanceof Request) {
            $notes                        = $request->attributes->get(self::SCAN_CUT);
            $notes                        = is_array($notes) ? $notes : [];
            $notes[spl_object_id($query)] = true;
            // @igor-ignore - attributes of the current main request (request-scoped, gone with it), not service state.
            $request->attributes->set(self::SCAN_CUT, $notes);
        }
    }

    /**
     * The channels the viewer may use now: `channelsFor()` ∩ `canUse()`, valid keys, the first of a repeated key; key =>
     * the channel as ChatAccess knows it (the label the viewer's list shows).
     *
     * @return array<string, ChatChannel>
     */
    private function channelsOf(ChatUserInterface $viewer): array
    {
        $channels = [];
        foreach ($this->channels->channelsFor($viewer) as $listed) {
            $key     = $listed->key;
            $channel = ChatChannelKey::isValid($key) && !isset($channels[$key]) && $this->access->canUseChannel($viewer, $key) ? $this->access->channel($key) : null;
            if ($channel instanceof ChatChannel) {
                $channels[$key] = $channel;
            }
        }

        return $channels;
    }

    /**
     * Ids of the viewer's direct conversations (within the newest `direct_scan`) whose other person's name, as the
     * viewer's list shows it, holds every word. A full scan is noted for takeScanCut().
     *
     * @param list<string> $words
     *
     * @return list<int>
     */
    private function matchingDirect(ChatUserInterface $viewer, array $words, SearchQuery $query): array
    {
        $rows    = [];
        $peers   = [];
        $limit   = max(1, min(self::MAX_DIRECT_SCAN, $this->directScan));
        $scanned = $this->conversations->findDirectForSearch($viewer, $limit);
        if (count($scanned) >= $limit) {
            // Older direct conversations were not looked at: the total of this search is a lower bound (audit VO-1).
            $this->noteScanCut($query);
        }
        foreach ($scanned as $conversation) {
            $peer = $conversation->peerKeyOf($viewer);
            if ($peer !== null) {
                $rows[]  = [$conversation, $peer];
                $peers[] = $peer;
            }
        }
        $people = $this->access->usersByIds($peers);
        $ids    = [];
        foreach ($rows as [$conversation, $peer]) {
            if (ChatSearchText::containsAll($words, $this->presenter->heading($conversation, $viewer, $people[$peer] ?? null, null)[0])) {
                $ids[] = (int) $conversation->getId();
            }
        }

        return $ids;
    }

    /**
     * The SQL condition of tickets whose label (`SUP-000123 · Topic`) may hold every word: per word, a configured topic
     * whose label holds it, the topic-less label, a topic no longer configured whose stored key holds it (escaped LIKE),
     * the number the word ends with, or every ticket when the word is part of the number's prefix. The exact label is
     * checked again per row.
     *
     * @param list<string> $words
     */
    private function ticketCondition(OrmQueryBuilder $queryBuilder, array $words): string
    {
        $labels = [];
        foreach (array_keys($this->topics->all()) as $key) {
            $labels[$key] = $this->topics->label($key);
        }
        $known      = array_keys($labels);
        $noTopic    = $this->topics->label(null);
        $prefix     = (string) $this->policy->format(0);
        $conditions = [];
        foreach (array_values($words) as $index => $word) {
            if (preg_match('/\d/', $word) !== 1 && ChatSearchText::containsAll([$word], $prefix . ' · ')) {
                // A part of the prefix (or the separator): every ticket's label has it.
                continue;
            }
            $parts  = [];
            $topics = [];
            foreach ($labels as $key => $label) {
                if (ChatSearchText::containsAll([$word], $label)) {
                    $topics[] = $key;
                }
            }
            if ($topics !== []) {
                $parts[] = 'c.ticketTopic IN (:chat_topics' . $index . ')';
                $queryBuilder->setParameter('chat_topics' . $index, $topics);
            }
            if (ChatSearchText::containsAll([$word], $noTopic)) {
                $parts[] = 'c.ticketTopic IS NULL';
            }
            $parts[] = '(' . ($known === [] ? '' : 'c.ticketTopic NOT IN (:chat_known_topics) AND ') . LikePattern::condition('c.ticketTopic', 'chat_topic_key' . $index) . ')';
            $queryBuilder->setParameter('chat_topic_key' . $index, LikePattern::contains($word));
            if ($known !== []) {
                $queryBuilder->setParameter('chat_known_topics', $known);
            }
            if (preg_match('/(\d+)$/', $word, $digits) === 1 && strlen($digits[1]) <= self::MAX_NUMBER_DIGITS) {
                $parts[] = 'c.ticketNumber = :chat_number' . $index;
                $queryBuilder->setParameter('chat_number' . $index, (int) $digits[1]);
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }

        return $conditions === [] ? 'c.ticketNumber IS NOT NULL' : implode(' AND ', $conditions);
    }
}
