<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

use DateTimeImmutable;
use Doctrine\DBAL\Query\QueryBuilder;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\Controller\ChatApiController;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatChannelKey;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Support\ChatRetention;
use Nowo\ChatKitBundle\Support\InvalidRetentionConfigurationException;
use Nowo\ChatKitBundle\Support\RetentionUnavailableException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use function array_keys;
use function count;
use function in_array;
use function max;
use function mb_strlen;
use function min;
use function preg_match;
use function sprintf;
use function usort;

/**
 * 1.37 (spec 002, owner decision 2026-11-18): message search for one viewer. The database finds the candidates with the
 * permission filter, the retention window and the text match in one statement ({@see ChatMessageSearchSql}); every
 * candidate is then checked again for the viewer (`ChatAccess::canView()`) and against the text the viewer sees (a
 * `#reference` the viewer may not open never matches its hidden title). Rows that fail are skipped without shortening
 * the page (at most {@see self::MAX_ROUNDS} reads). Unknown and forbidden filter values and cursors give the same empty
 * page. Nothing is kept between calls (FrankenPHP worker mode); the query text is never logged.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatMessageSearch
{
    /** Reads of candidates per page at most (each reads one page plus one row). */
    public const int MAX_ROUNDS = 5;

    /** Largest page size accepted from a caller. */
    public const int MAX_PAGE_SIZE = 50;

    /** Longest plain text compared (a message is at most ChatMessage::MAX_LENGTH characters before #references grow). */
    private const int PLAIN_LENGTH = 100_000;

    /**
     * @param ChatMessageSearchSql $sql The SQL of the search
     * @param ChatAccess $access Reachability, support agents, channels and the final per-row check
     * @param ChatChannelProviderInterface $channels The channels the viewer belongs to (`channelsFor()`)
     * @param ChatDirectoryInterface $directory Resolves the author filter (a public id)
     * @param ChatMessageRepository $messages Loads the matching messages
     * @param ChatPresenter $presenter Conversation titles as the viewer's list shows them
     * @param ChatReferences $references The `#references` the viewer may open
     * @param ChatMarkdownRenderer $markdown Plain text of a message
     * @param ChatUserReferences $refs User references in SQL
     * @param bool $enabled `search.enabled`
     * @param int $minQueryLength `search.min_query_length`
     * @param int $maxQueryLength `search.max_query_length`
     * @param int $pageSize `search.page_size`
     * @param int $retentionDays The configured `retention_days` (the purge's window)
     * @param RetentionPolicyProviderInterface|null $retention The host's retention, asked on every search
     * @param ClockInterface $clock The clock of the retention window
     * @param LoggerInterface|null $logger Receives a warning when the retention cannot be read (never the query text)
     */
    public function __construct(
        private ChatMessageSearchSql $sql,
        private ChatAccess $access,
        private ChatChannelProviderInterface $channels,
        private ChatDirectoryInterface $directory,
        private ChatMessageRepository $messages,
        private ChatPresenter $presenter,
        private ChatReferences $references,
        private ChatMarkdownRenderer $markdown,
        private ChatUserReferences $refs = new ChatUserReferences(),
        private bool $enabled = false,
        private int $minQueryLength = 2,
        private int $maxQueryLength = 200,
        private int $pageSize = 20,
        private int $retentionDays = 365,
        private ?RetentionPolicyProviderInterface $retention = null,
        private ClockInterface $clock = new Clock(),
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether search is on (`search.enabled`).
     *
     * @return bool True when enabled
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Builds a query with this configuration's length bounds.
     *
     * @param string $text The raw text
     * @param string|null $conversation Uuid of one conversation
     * @param string|null $author Public id of the author, or `me`
     * @param DateTimeImmutable|null $after Created at or after
     * @param DateTimeImmutable|null $before Created before
     * @param bool $hasLink Only messages with a link
     * @param string|null $cursor The previous page's `next`
     *
     * @throws ChatSearchQueryException When the text is too short or too long
     *
     * @return ChatMessageSearchQuery The query
     */
    public function query(string $text, ?string $conversation = null, ?string $author = null, ?DateTimeImmutable $after = null, ?DateTimeImmutable $before = null, bool $hasLink = false, ?string $cursor = null): ChatMessageSearchQuery
    {
        return ChatMessageSearchQuery::create($text, $this->minQueryLength, $this->maxQueryLength, $conversation, $author, $after, $before, $hasLink, $cursor);
    }

    /**
     * One page of results for the viewer, newest first.
     *
     * @param ChatUserInterface $viewer The signed-in participant
     * @param ChatMessageSearchQuery $query Text, filters and cursor
     * @param int|null $limit Rows of the page (null: `search.page_size`; at most {@see self::MAX_PAGE_SIZE})
     *
     * @return ChatMessageSearchPage The page (empty when search is off, the viewer is unreachable, or a filter or the
     *                               cursor is unknown or not readable by the viewer)
     */
    public function search(ChatUserInterface $viewer, ChatMessageSearchQuery $query, ?int $limit = null): ChatMessageSearchPage
    {
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit ?? $this->pageSize));
        if (!$this->enabled || $query->words === [] || !$this->access->isReachable($viewer)) {
            return ChatMessageSearchPage::empty();
        }
        if ($query->conversation !== null && preg_match('/^' . ChatApiController::UUID . '$/D', $query->conversation) !== 1) {
            return ChatMessageSearchPage::empty();
        }
        $scope  = $this->scope($viewer);
        $cursor = null;
        if ($query->cursor !== null) {
            $cursor = preg_match('/^' . ChatApiController::UUID . '$/D', $query->cursor) === 1 ? $this->sql->readableId($scope, $query->cursor) : null;
            if ($cursor === null) {
                return ChatMessageSearchPage::empty();
            }
        }
        $author = null;
        if ($query->author !== null) {
            $author = $this->author($viewer, $query->author);
            if ($author === null) {
                return ChatMessageSearchPage::empty();
            }
        }
        $cutoff = $this->cutoff();
        if (!$cutoff instanceof DateTimeImmutable) {
            return ChatMessageSearchPage::empty();
        }

        return $this->collect($viewer, $scope, $query, $author, $cutoff, $cursor, $limit);
    }

    /**
     * Reads candidates round by round until the page is full, the candidates end, or {@see self::MAX_ROUNDS} reads.
     *
     * The database matches the viewer-neutral search text (audit VA2-1), which holds no hidden text (no reference title,
     * link URL or mention id), so no row is ever found through hidden text; the text check below also keeps the
     * viewer's own visible words authoritative (and drops rows whose search text is out of step). A full page always
     * carries the last hit as its cursor and a list whose candidates end first has none (audit VA-1); reads that stop
     * at {@see self::MAX_ROUNDS} (five reads of candidates the per-row checks dropped) go on from the last readable row
     * read.
     */
    private function collect(ChatUserInterface $viewer, ChatSearchScope $scope, ChatMessageSearchQuery $query, int|string|null $author, DateTimeImmutable $cutoff, ?int $cursor, int $limit): ChatMessageSearchPage
    {
        $hits        = [];
        $lastScanned = null;
        $agent       = $this->access->isSupportAgent($viewer);
        /** @var array<string, string> $titles */
        $titles = [];
        for ($round = 0; $round < self::MAX_ROUNDS; ++$round) {
            $ids  = $this->sql->candidateIds($scope, $query, $author, $cutoff, $cursor, $limit);
            $rows = $this->load($ids);
            $this->references->prime($viewer, $rows);
            foreach ($rows as $message) {
                $cursor       = (int) $message->getId();
                $conversation = $message->getConversation();
                if (!$this->access->canView($conversation, $viewer)) {
                    // Defence in depth (the SQL already filters, 1.38 the direct pair too): never hand out the uuid of a
                    // row the viewer cannot read (a look-alike pair under a folding collation, house rule LQ).
                    continue; // @codeCoverageIgnore
                }
                $lastScanned = $message->getUuid();
                $text        = $this->references->plainText($this->markdown, $message->getBody(), $viewer, self::PLAIN_LENGTH);
                if (!ChatSearchText::containsAll($query->words, $text)) {
                    continue;
                }
                $hits[] = $this->hit($message, $viewer, $text, $query->words, $agent, $titles);
                if (count($hits) === $limit) {
                    return new ChatMessageSearchPage($hits, $hits[$limit - 1]->messageUuid);
                }
            }
            if (count($ids) < $limit) {
                return new ChatMessageSearchPage($hits, null);
            }
        }

        // The reads stopped before the end: the next page goes on from the last row read, even when this one shows nothing.
        return new ChatMessageSearchPage($hits, $lastScanned);
    }

    /**
     * 1.37 (SearchKit recents): one message as a result row for the viewer, re-checked now with every rule of the
     * search (readable conversation, not deleted, a kind people write, inside the retention window); null for an
     * unknown, removed or unreadable message alike.
     *
     * @param ChatUserInterface $viewer The signed-in participant
     * @param string $conversationUuid The conversation the reference names
     * @param string $messageUuid The message
     *
     * @return ChatMessageSearchHit|null The row (excerpt from the start of the text)
     */
    public function find(ChatUserInterface $viewer, string $conversationUuid, string $messageUuid): ?ChatMessageSearchHit
    {
        $uuid = '/^' . ChatApiController::UUID . '$/D';
        if (!$this->enabled || preg_match($uuid, $conversationUuid) !== 1 || preg_match($uuid, $messageUuid) !== 1 || !$this->access->isReachable($viewer)) {
            return null;
        }
        $id     = $this->sql->readableId($this->scope($viewer), $messageUuid);
        $cutoff = $this->cutoff();
        if ($id === null || !$cutoff instanceof DateTimeImmutable) {
            return null;
        }
        $message = $this->load([$id])[0] ?? null;
        if (!$message instanceof ChatMessage || $message->getConversation()->getUuid() !== $conversationUuid || $message->isDeleted()
            || !in_array($message->getKind(), ChatMessageSearchSql::KINDS, true) || $message->getCreatedAt() < $cutoff
            || ($message->getThreadRoot() instanceof ChatMessage && $message->getThreadRoot()->getCreatedAt() < $cutoff)
            || !$this->access->canView($message->getConversation(), $viewer)) {
            return null;
        }
        $this->references->prime($viewer, [$message]);
        $titles = [];

        return $this->hit($message, $viewer, $this->references->plainText($this->markdown, $message->getBody(), $viewer, self::PLAIN_LENGTH), [], $this->access->isSupportAgent($viewer), $titles);
    }

    /**
     * 1.38 (SearchKit message group, ChatMessageSearchDefinition): the DBAL query of the messages the viewer may read now
     * — the same permission filter, kinds, deleted rows and retention window as search() — newest first, without a limit
     * or a text condition (matchWords() adds it). Null (no rows) when search is off, the viewer is unreachable, the author
     * filter is unknown or not exact, or the retention cannot be read (a query that matches nothing).
     *
     * @param ChatUserInterface $viewer The signed-in participant
     * @param string|null $author Public id of the author, `me`, or null for any author
     *
     * @return QueryBuilder The query (alias `m`, column `id`); one that matches nothing when nothing is readable
     */
    public function readableQuery(ChatUserInterface $viewer, ?string $author = null): QueryBuilder
    {
        if (!$this->enabled || !$this->access->isReachable($viewer)) {
            return $this->sql->none();
        }
        $value = null;
        if ($author !== null) {
            $value = $this->author($viewer, $author);
            if ($value === null) {
                return $this->sql->none();
            }
        }
        $cutoff = $this->cutoff();

        return ($cutoff instanceof DateTimeImmutable ? $this->sql->readable($this->scope($viewer), $value, $cutoff) : null) ?? $this->sql->none();
    }

    /**
     * 1.38: a query of readableQuery()'s shape that matches nothing (no viewer: no rows, no hint).
     *
     * @return QueryBuilder The query
     */
    public function noRows(): QueryBuilder
    {
        return $this->sql->none();
    }

    /**
     * 1.38: adds the text condition of the words to a readableQuery() (the viewer-neutral search text, full text or an
     * escaped LIKE).
     *
     * @param QueryBuilder $qb A query of readableQuery()
     * @param list<string> $words The query words
     *
     * @return bool False when no word can use the full-text index (the caller answers no rows)
     */
    public function matchWords(QueryBuilder $qb, array $words): bool
    {
        return $this->sql->matchWords($qb, $words);
    }

    /**
     * 1.38: the per-row checks of search() on candidate ids, in their order: the messages are loaded in one query (their
     * `#references` primed for the viewer in one resolve per type), and a row is kept only while the viewer may read its
     * conversation (`ChatAccess::canView()`) and the text the viewer sees holds every word.
     *
     * @param ChatUserInterface $viewer The signed-in participant
     * @param list<int> $ids Candidate ids (from readableQuery())
     * @param list<string> $words The query words (empty: no text check, for recents)
     *
     * @return list<int> The ids kept
     */
    public function visibleIds(ChatUserInterface $viewer, array $ids, array $words): array
    {
        $rows = $this->load($ids);
        $this->references->prime($viewer, $rows);
        $kept = [];
        foreach ($rows as $message) {
            if ($this->access->canView($message->getConversation(), $viewer)
                && ChatSearchText::containsAll($words, $this->references->plainText($this->markdown, $message->getBody(), $viewer, self::PLAIN_LENGTH))) {
                $kept[] = (int) $message->getId();
            }
        }

        return $kept;
    }

    /**
     * 1.38: the result rows of messages visibleIds() kept in this call (SearchKit's prepareItems(): the shown rows only),
     * keyed by id: read from ChatKit's entity manager, which already holds them (no query), the other people of their
     * direct conversations in one directory call, with the plain text, excerpt and conversation title for the viewer.
     * A message that is not loaded any more or not readable is left out.
     *
     * @param ChatUserInterface $viewer The signed-in participant
     * @param list<int> $ids Message ids visibleIds() kept
     * @param list<string> $words The query words (marked in the excerpt)
     *
     * @return array<int, ChatMessageSearchHit> id => row
     */
    public function hitsOf(ChatUserInterface $viewer, array $ids, array $words): array
    {
        $messages = [];
        $peers    = [];
        foreach ($ids as $id) {
            $message = $this->messages->find($id);
            if ($message instanceof ChatMessage && $this->access->canView($message->getConversation(), $viewer)) {
                $messages[$id] = $message;
                $peer          = $message->getConversation()->peerKeyOf($viewer);
                if ($peer !== null) {
                    $peers[] = $peer;
                }
            }
        }
        $people = $this->access->usersByIds($peers);
        $agent  = $this->access->isSupportAgent($viewer);
        $titles = [];
        $hits   = [];
        foreach ($messages as $id => $message) {
            $hits[$id] = $this->hit($message, $viewer, $this->references->plainText($this->markdown, $message->getBody(), $viewer, self::PLAIN_LENGTH), $words, $agent, $titles, $people);
        }

        return $hits;
    }

    /**
     * A result row from what the viewer may see.
     *
     * @param list<string> $words The query words (marked in the excerpt)
     * @param array<string, string> $titles Conversation titles already built for this page (uuid => title)
     * @param array<int|string, ChatUserInterface>|null $people 1.38: the other people of direct conversations, already
     *                                                          loaded (key => user); null: ChatAccess::directPeer()
     */
    private function hit(ChatMessage $message, ChatUserInterface $viewer, string $text, array $words, bool $agent, array &$titles, ?array $people = null): ChatMessageSearchHit
    {
        $conversation = $message->getConversation();
        $key          = $conversation->getUuid();
        $peer         = $people === null ? $this->access->directPeer($conversation, $viewer) : ($people[(string) $conversation->peerKeyOf($viewer)] ?? null);
        $titles[$key] ??= (string) $this->presenter->conversation($conversation, $viewer, $peer, $conversation->isChannel() ? $this->access->channel((string) $conversation->getChannelKey()) : null, false, 0, null, $agent)['title'];
        $snippet = ChatSearchText::snippet($text, $words);
        $writer  = $message->getAuthor();

        return new ChatMessageSearchHit(
            $message->getUuid(),
            $message->getThreadRoot()?->getUuid(),
            $key,
            $conversation->getKind(),
            $titles[$key],
            $writer?->getChatDisplayName(),
            $writer?->getChatPublicId(),
            $message->getCreatedAt(),
            $snippet,
            ChatSearchText::segments($snippet, $words),
        );
    }

    /**
     * What the viewer may read now.
     */
    private function scope(ChatUserInterface $viewer): ChatSearchScope
    {
        $keys = [];
        foreach ($this->channels->channelsFor($viewer) as $channel) {
            $key = $channel->key;
            if (ChatChannelKey::isValid($key) && !in_array($key, $keys, true) && $this->access->canUseChannel($viewer, $key)) {
                $keys[] = $key;
            }
        }
        $key = ChatUserReferences::key($viewer);

        return new ChatSearchScope($key === null ? null : $this->refs->sqlValue($key), $this->searchesEveryTicket($viewer), $keys);
    }

    /**
     * Which support tickets a support agent searches: every ticket, as the agent's inbox and the conversation page
     * allow (ChatAccess::canView()); owner decision 2026-11-19. The one place to change if that rule ever changes.
     */
    private function searchesEveryTicket(ChatUserInterface $viewer): bool
    {
        return $this->access->isSupportAgent($viewer);
    }

    /**
     * The SQL value of the author filter; null when the author is unknown (no rows).
     */
    private function author(ChatUserInterface $viewer, string $author): int|string|null
    {
        if ($author === ChatMessageSearchQuery::ME) {
            $user = $viewer;
        } else {
            $user = mb_strlen($author) <= ChatMessageSearchQuery::MAX_FILTER_LENGTH ? $this->directory->findByPublicId($author) : null;
            if ($user instanceof ChatUserInterface && $user->getChatPublicId() !== $author) {
                // A directory that folds case or accents must not hand back a look-alike (house rule LQ).
                $user = null;
            }
        }
        $key = $user instanceof ChatUserInterface ? ChatUserReferences::key($user) : null;

        return $key === null ? null : $this->refs->sqlValue($key);
    }

    /**
     * The start of the retention window, the purge's own (null when it cannot be read: no rows, fail closed).
     */
    private function cutoff(): ?DateTimeImmutable
    {
        try {
            $days = (new ChatRetention($this->retentionDays, $this->retention, $this->logger ?? new NullLogger()))->days();
        } catch (InvalidRetentionConfigurationException|RetentionUnavailableException $exception) {
            $this->logger?->warning('Chat search answered no rows: the retention window cannot be read.', ['exception' => $exception::class]);

            return null;
        }

        return $this->clock->now()->modify(sprintf('-%d days', $days));
    }

    /**
     * The messages of the ids (conversation, author and thread root fetched), newest first.
     *
     * @param list<int> $ids
     *
     * @return list<ChatMessage>
     */
    private function load(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->messages->createQueryBuilder('m')
            ->addSelect('c', 'r')
            ->innerJoin('m.conversation', 'c')
            ->leftJoin('m.threadRoot', 'r')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids);
        $this->refs->join($qb, 'm.author', 'author');
        /** @var list<ChatMessage> $rows */
        $rows = $qb->getQuery()->getResult();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->getId()] = $row;
        }
        $keys = array_keys($byId);
        usort($keys, static fn (int $a, int $b): int => $b <=> $a);
        $sorted = [];
        foreach ($keys as $id) {
            $sorted[] = $byId[$id];
        }

        return $sorted;
    }
}
